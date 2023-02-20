<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Composer\Downloader\TransportException;
use Packeton\Composer\MetadataMinifier;
use Packeton\Form\Type\ProxySettingsType;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\ProxyInfoInterface;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Service\ComposeProxyRegistry;
use Packeton\Mirror\Service\RemoteSyncProxiesFacade;
use Packeton\Mirror\Utils\MirrorPackagesValidate;
use Packeton\Mirror\Utils\MirrorTextareaParser;
use Packeton\Mirror\Utils\MirrorUIFormatter;
use Packeton\Model\PackageManager;
use Packeton\Service\JobScheduler;
use Packeton\Util\HtmlJsonHuman;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/proxies')]
class ProxiesController extends AbstractController
{
    public function __construct(
        private readonly ProxyRepositoryRegistry $proxyRepositoryRegistry,
        private readonly ComposeProxyRegistry $composeProxyRegistry,
        private readonly MirrorTextareaParser $textareaParser,
        private readonly JobScheduler $jobScheduler,
        private readonly MirrorPackagesValidate $mirrorValidate,
        private readonly MetadataMinifier $metadataMinifier,
        private readonly PackageManager $packageManager,
    ) {
    }

    #[Route('', name: 'proxies_list')]
    public function listAction(): Response
    {
        /** @var ProxyOptions[] $proxies */
        $proxies = [];
        foreach ($this->proxyRepositoryRegistry->getAllRepos() as $repo) {
            if ($repo instanceof ProxyInfoInterface) {
                $proxies[] = $repo->getConfig();
            }
        }

        return $this->render('proxies/index.html.twig', [
            'proxies' => $proxies
        ]);
    }

    #[Route('/{alias}', name: 'proxy_view')]
    public function viewAction(string $alias): Response
    {
        $repo = $this->getRemoteRepository($alias);
        $data = $this->getProxyData($repo);

        $action = $this->createFormBuilder()->getForm()->createView();

        return $this->render('proxies/view.html.twig', $data + ['proxy' => $repo->getConfig(), 'action' => $action]);
    }

    #[Route('/{alias}/settings', name: 'proxy_settings')]
    public function settings(Request $request, string $alias)
    {
        $repo = $this->getRemoteRepository($alias);
        $settings = $repo->getPackageManager()->getSettings();

        $form = $this->createForm(ProxySettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repo->getPackageManager()->setSettings($form->getData());
            $this->addFlash('success', 'The proxy settings has been updated.');
            $repo->touchRoot();
            return $this->redirect($this->generateUrl('proxy_view', ['alias' => $alias]));
        }

        return $this->render('proxies/settings.html.twig', [
            'proxy' => $repo->getConfig(),
            'form'    => $form->createView()
        ]);
    }

    #[Route(
        '/{alias}/metadata/{package}',
        name: 'proxy_package_meta',
        requirements: ['package' => '.+'],
        methods: ["POST", "GET"]
    )]
    public function metadata(HtmlJsonHuman $jsonHuman, string $alias, string $package)
    {
        try {
            $repo = $this->composeProxyRegistry->createRepository($alias);
            $meta = $repo->findPackageMetadata($package);
        } catch (MetadataNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        $json = $meta->decodeJson();
        $metadata = $this->metadataMinifier->minify($json)['packages'][$package] ?? [];

        return new Response($jsonHuman->buildToHtml($metadata));
    }

    #[Route('/{alias}/update', name: 'proxy_update', methods: ["PUT"])]
    public function updateAction(Request $request, string $alias)
    {
        $repo = $this->getRemoteRepository($alias);
        $data = $this->jsonRequest($request);
        $flags = ($data['force'] ?? false) ? RemoteSyncProxiesFacade::FULL_RESET : 0;
        $flags |= RemoteSyncProxiesFacade::UI_RESET;

        $job = $this->jobScheduler->publish(
            'sync:mirrors',
            ['mirror' => $alias, 'flags' => $flags],
            $repo->getConfig()->reference()
        );

        return new JsonResponse(['job' => $job->getId()], 201);
    }

    #[Route('/{alias}/mark-enabled', name: 'proxy_mark_mass', methods: ["POST"])]
    public function markMass(Request $request, string $alias)
    {
        $repo = $this->getRemoteRepository($alias);
        $data = $this->jsonRequest($request);

        $packages = $this->textareaParser->parser($data['packages'] ?? null);
        $action = $data['action'] ?? 'approve';
        $pm = $repo->getPackageManager();

        try {
            $result = $this->mirrorValidate->checkPackages($repo, $packages, $pm->getEnabled());
        } catch (TransportException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if (false === ($data['check'] ?? false)) {
            $pm = $repo->getPackageManager();
            match ($action) {
                'enable' => \array_map($pm->markEnable(...), $result['valid'] ?? []),
                'approve' => \array_map($pm->markApprove(...), $result['valid'] ?? []),
                'remove' => \array_map($pm->markDisable(...), $packages),
            };

            $this->addFlash('success', 'The packages have been updated.');
            $repo->touchRoot();
        }

        return new JsonResponse($result);
    }

    #[Route('/{alias}/mark-approved', name: 'proxy_mark_approved', methods: ["PUT"])]
    public function markApproved(Request $request, string $alias)
    {
        $repo = $this->getRemoteRepository($alias);
        $data = $this->jsonRequest($request);

        $pm = $repo->getPackageManager();
        \array_map($pm->markApprove(...), $data['packages'] ?? []);
        $this->addFlash('success', 'The packages have been approved');
        $repo->touchRoot();

        return new JsonResponse([], 204);
    }

    #[Route('/{alias}/remove-approved', name: 'proxy_remove_approved', methods: ["DELETE"])]
    public function removeApproved(Request $request, string $alias)
    {
        $repo = $this->getRemoteRepository($alias);
        $data = $this->jsonRequest($request);

        $pm = $repo->getPackageManager();
        \array_map($pm->removeApprove(...), $data['packages'] ?? []);
        $this->addFlash('success', 'The packages have been removed');
        $repo->touchRoot();

        return new JsonResponse([], 204);
    }

    protected function jsonRequest(Request $request): array
    {
        $json = \json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('actions', $json['token'] ?? '')) {
            throw new BadRequestHttpException('Csrf token is not a valid');
        }

        return $json;
    }

    protected function getProxyData(RemoteProxyRepository $repo): array
    {
        $config = $repo->getConfig();
        $repoUrl = $this->generateUrl('mirror_index', ['alias' => $config->getAlias()], UrlGeneratorInterface::ABSOLUTE_URL);

        $rpm = $repo->getPackageManager();
        $privatePackages = $this->packageManager->getPackageNames();

        $packages = MirrorUIFormatter::getGridPackagesData($rpm->getApproved(), $rpm->getEnabled(), $privatePackages);

        return [
            'repoUrl' => $repoUrl,
            'usedPackages' => $packages,
            'tooltips' => [
                'API_V2' => 'Support metadata-url for Composer v2',
                'API_V1' => 'Support Composer v1 API',
                'API_META_CHANGE' => 'Support Metadata changes API',
            ]
        ];
    }

    protected function getRemoteRepository(string $alias): RemoteProxyRepository
    {
        try {
            $repo = $this->proxyRepositoryRegistry->getRepository($alias);
            if (!$repo instanceof RemoteProxyRepository) {
                throw $this->createNotFoundException();
            }
        } catch (\Exception) {
            throw $this->createNotFoundException();
        }

        return $repo;
    }
}
