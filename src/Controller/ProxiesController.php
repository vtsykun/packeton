<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Form\Type\ProxySettingsType;
use Packeton\Mirror\Model\ProxyInfoInterface;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\RemoteProxyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/proxies')]
class ProxiesController extends AbstractController
{
    public function __construct(
        private readonly ProxyRepositoryRegistry $proxyRepositoryRegistry
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

        return $this->render('proxies/view.html.twig', $data + ['proxy' => $repo->getConfig()]);
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
            return $this->redirect($this->generateUrl('proxy_view', ['alias' => $alias]));
        }

        return $this->render('proxies/settings.html.twig', [
            'proxy' => $repo->getConfig(),
            'form'    => $form->createView()
        ]);
    }

    protected function getProxyData(RemoteProxyRepository $repo): array
    {
        $config = $repo->getConfig();
        $repoUrl = $this->generateUrl('mirror_index', ['alias' => $config->getAlias()], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            'repoUrl' => $repoUrl,
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
