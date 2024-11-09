<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Model\PackageManager;
use Packeton\Model\ProviderManager;
use Packeton\Service\SubRepositoryHelper;
use Packeton\Util\UserAgentParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_format' => 'json'], methods: ['GET'])]
class ProviderController extends AbstractController
{
    use ControllerTrait;
    use SubRepoControllerTrait;

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ProviderManager $providerManager,
        private readonly ManagerRegistry $registry,
        private readonly SubRepositoryHelper $subRepositoryHelper,
    ) {
    }

    #[Route('/packages.json', name: 'root_packages')]
    #[Route('/{slug}/packages.json', name: 'root_packages_slug')]
    public function packagesAction(Request $request, ?string $slug = null): Response
    {
        $response = new JsonResponse([]);
        $response->setLastModified($this->providerManager->getRootLastModify());
        if ($response->isNotModified($request)) {
            return $response;
        }

        $ua = new UserAgentParser($request->headers->get('User-Agent'));
        $apiVersion = $request->query->get('ua') ? (int) $request->query->get('ua') : $ua->getComposerMajorVersion();
        $subRepo = $this->subRepositoryHelper->getSubrepositoryId();;

        $rootPackages = $this->packageManager->getRootPackagesJson($this->getUser(), $apiVersion, $subRepo);

        $response->setData($rootPackages);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $response;
    }

    #[Route('/p/providers${hash}.json', name: 'root_providers', requirements: ['hash' => '[a-f0-9]+'])]
    #[Route('/{slug}/p/providers${hash}.json', name: 'root_providers_slug', requirements: ['hash' => '[a-f0-9]+'])]
    public function providersAction(string $hash, Request $request, ?string $slug = null): Response
    {
        $subRepo = $this->subRepositoryHelper->getSubrepositoryId();
        $providers = $this->packageManager->getProvidersJson($this->getUser(), $hash, $subRepo);
        if (!$providers) {
            return $this->createNotFound();
        }

        return $this->createJsonResponse($providers);
    }

    /**
     * Copy from Packagist. Can be used for https://workers.cloudflare.com sync mirrors.
     * Used two unix format: Packagist and RFC-3399
     */
    #[Route('/metadata/changes.json', name: 'metadata_changes')]
    public function metadataChangesAction(Request $request): Response
    {
        $now = time() * 10000;
        $since = $request->query->getInt('since');
        // Added unix
        if ($since > 1585061224 && $since < 15850612240000) {
            $since *= 10000;
        }

        $oldestSyncPoint = $now - 30 * 86400 * 10000;
        if (!$since || $since < $now - 15850612240000) {
            return new JsonResponse(['error' => 'Invalid or missing "since" query parameter, make sure you store the timestamp at the initial point you started mirroring, then send that to begin receiving changes, e.g. '.$this->generateUrl('metadata_changes', ['since' => $now], UrlGeneratorInterface::ABSOLUTE_URL).' for example.', 'timestamp' => $now], 400);
        }
        if ($since < $oldestSyncPoint) {
            return new JsonResponse(['actions' => [['type' => 'resync', 'time' => floor($now / 10000), 'package' => '*']], 'timestamp' => $now]);
        }

        // Only update action support.
        $updatesDev = $this->registry->getRepository(Package::class)
            ->getMetadataChanges(floor($since/10000), floor($now/10000), false);
        $updatesStab = $this->registry->getRepository(Package::class)
            ->getMetadataChanges(floor($since/10000), floor($now/10000), true);

        return new JsonResponse(['actions' => array_merge($updatesDev, $updatesStab), 'timestamp' => $now]);
    }

    #[Route('/p/{package}.json', name: 'root_package', requirements: ['package' => '%package_name_regex_v1%'])]
    #[Route('/{slug}/p/{package}.json', name: 'root_package_slug', requirements: ['package' => '%package_name_regex_v1%'])]
    public function packageAction(string $package): Response
    {
        $package = \explode('$', $package);
        $subRepo = $this->subRepositoryHelper->getSubrepositoryId();
        if (!$this->checkSubrepositoryAccess($package[0])) {
            return $this->createNotFound();
        }

        if (\count($package) !== 2) {
            $package = $this->packageManager->getPackageJson($this->getUser(), $package[0]);
            if ($package) {
                return $this->createJsonResponse($package);
            }
            return $this->createNotFound();
        }

        $package = $this->packageManager->getCachedPackageJson($this->getUser(), $package[0], $package[1], $subRepo);
        if (!$package) {
            return $this->createNotFound();
        }

        return $this->createJsonResponse($package);
    }

    #[Route('/p2/{package}.json', name: 'root_package_v2', requirements: ['package' => '%package_name_regex_v2%'])]
    #[Route('/{slug}/p2/{package}.json', name: 'root_package_v2_slug', requirements: ['package' => '%package_name_regex_v2%'])]
    public function packageV2Action(Request $request, string $package): Response
    {
        $isDev = str_ends_with($package, '~dev');
        $packageName = preg_replace('/~dev$/', '', $package);
        if (!$this->checkSubrepositoryAccess($packageName)) {
            return $this->createNotFound();
        }

        $response = new JsonResponse([]);
        $response->setLastModified($this->providerManager->getLastModify($package));
        if ($response->isNotModified($request)) {
            return $response;
        }

        $package = $this->packageManager->getPackageV2Json($this->getUser(), $packageName, $isDev);
        if (!$package) {
            return $this->createNotFound();
        }

        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES);
        $response->setData($package);

        return $response;
    }

    protected function createNotFound(?string $msg = null): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => $msg ?: 'Not Found'], 404);
    }

    protected function createJsonResponse(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setEncodingOptions(0);

        return $response;
    }
}
