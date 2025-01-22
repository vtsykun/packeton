<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\Package;
use Packeton\Entity\Zipball;
use Packeton\Event\ZipballEvent;
use Packeton\Exception\ZipballException;
use Packeton\Model\UploadZipballStorage;
use Packeton\Package\RepTypes;
use Packeton\Service\DistManager;
use Packeton\Service\SubRepositoryHelper;
use Packeton\Util\PacketonUtils;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route(defaults: ['_format' => 'json'])]
class ZipballController extends AbstractController
{
    use SubRepoControllerTrait;

    public function __construct(
        protected DistManager $dm,
        protected UploadZipballStorage $storage,
        protected ManagerRegistry $registry,
        protected EventDispatcherInterface $dispatcher,
        protected SubRepositoryHelper $subRepositoryHelper,
        protected LoggerInterface $logger,
    ) {
    }

    #[Route('/archive/upload', name: 'archive_upload', methods: ["POST"], format: 'json')]
    #[IsGranted('ROLE_MAINTAINER')]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('archive_upload', $request->get('token'))) {
            return new JsonResponse(['error' => 'Csrf token is not a valid'], 400);
        }

        if (!$file = $request->files->get('archive')) {
            return new JsonResponse(['error' => 'File is empty'], 400);
        }

        try {
            $result = $this->storage->save($file);
        } catch (ZipballException $e) {
            return new JsonResponse(['error' => $e->getMessage()], max($e->getCode(), 300));
        }

        return new JsonResponse(['id' => $result->getId(), 'filename' => $result->getFilename(), 'size' => $result->getFileSize()], 201);
    }

    #[Route('/archive/remove/{id}', name: 'archive_remove', methods: ["DELETE"])]
    #[IsGranted('ROLE_MAINTAINER')]
    public function remove(#[Vars] Zipball $zip, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('archive_upload', $request->get('token'))) {
            return new JsonResponse(['error' => 'Csrf token is not a valid'], 400);
        }
        if ($zip->isUsed()) {
            return new JsonResponse(['error' => 'You can not remove used zipball'], 400);
        }

        $this->storage->remove($zip);

        return new JsonResponse([], 204);
    }

    #[Route('/archive/list', name: 'archive_list')]
    #[IsGranted('ROLE_MAINTAINER')]
    public function zipballList(Request $request): Response
    {
        $withIds = $request->query->get('with_archives');
        $withIds = $withIds ? explode(',', $withIds) : [];
        $data = $this->registry->getRepository(Zipball::class)->ajaxSelect(true, $withIds);

        return new JsonResponse($data);
    }

    #[Route(
        '/zipball/{package}/{hash}',
        name: 'download_dist_package',
        requirements: ['package' => '%package_name_regex%', 'hash' => '[a-f0-9]{40}(\.?[A-Za-z\.]+?)?'],
        methods: ['GET']
    )]
    #[Route(
        '/{slug}/zipball/{package}/{hash}',
        name: 'download_dist_package_slug',
        requirements: ['package' => '%package_name_regex%', 'hash' => '[a-f0-9]{40}(\.?[A-Za-z\.]+?)?'],
        methods: ['GET']
    )]
    public function zipballAction(#[Vars('name')] Package $package, string $hash): Response
    {
        if ((false === $this->dm->isEnabled() && false === RepTypes::isBuildInDist($package->getRepoType()))
            || !\preg_match('{[a-f0-9]{40}}i', $hash, $match)
            || !($reference = $match[0])
            || !$this->checkSubrepositoryAccess($package->getName())
        ) {
            return $this->createNotFound();
        }

        $isGranted = $this->subRepositoryHelper->isPublicAccess()
            || $this->isGranted('VIEW_ALL_VERSION', $package)
            || $this->isGranted('ROLE_FULL_CUSTOMER', $package);

        if (false === $isGranted) {
            foreach ($package->getAllVersionsByReference($reference) as $version) {
                $isGranted = $isGranted || $this->isGranted('ROLE_FULL_CUSTOMER', $version);
            }
        }

        if (true !== $isGranted) {
            return $this->createNotFound();
        }

        try {
            $dist = $this->dm->getDist($reference, $package);
        } catch (\Exception $e) {
            $msg = $this->isGranted('ROLE_MAINTAINER') ? $e->getMessage() : null;
            $this->logger->warning($e->getMessage(), ['e' => $e]);
            return $this->createNotFound($msg);
        }

        $this->dispatcher->dispatch($event = new ZipballEvent($package, $reference, $dist), ZipballEvent::DOWNLOAD);
        $dist = $event->getDist();

        if (is_string($dist)) {
            return new BinaryFileResponse($dist);
        }

        return new StreamedResponse(PacketonUtils::readStream($dist), 200, [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => 'application/zip',
        ]);
    }

    protected function createNotFound(?string $msg = null): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => $msg ?: 'Not Found'], 404);
    }
}
