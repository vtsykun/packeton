<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Mirror\Model\ProxyInfoInterface;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/proxies')]
class ProxiesController extends AbstractController
{
    public function __construct(
        private readonly ProxyRepositoryRegistry $proxyRepositoryRegistry
    ) {
    }

    #[Route('', name: 'proxies_list')]
    public function listAction(Request $request): Response
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
}
