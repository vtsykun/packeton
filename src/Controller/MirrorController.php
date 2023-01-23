<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyRepositoryInterface as PRI;
use Packeton\Mirror\RootMetadataMerger;
use Packeton\Mirror\Service\ComposeProxyRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/mirror', defaults:['_format' => 'json'])]
class MirrorController extends AbstractController
{
    public function __construct(
        protected ComposeProxyRegistry $proxyRegistry,
        protected RootMetadataMerger $metadataMerger,
        protected MetadataMinifier $minifier,
    ) {
    }

    #[Route('/{alias}/packages.json', name: 'mirror_root', methods: ['GET'])]
    public function rootAction(Request $request, string $alias): Response
    {
        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->rootMetadata());

        $metadata = $this->metadataMerger->merge($metadata);

        return $this->renderMetadata($metadata, $request);
    }

    #[Route('/{alias}/p2/{package}.json', name: 'mirror_metadata_v2', requirements: ['package' => '[A-Za-z0-9_.-]+/[A-Za-z0-9_.~-]+'], methods: ['GET'])]
    public function metadataV2Action(string $package, string $alias, Request $request): Response
    {
        $devStability = \str_ends_with($package, '~dev');
        $package = \preg_replace('/~dev$/', '', $package);

        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->findPackageMetadata($package));

        $metadata = $metadata->withContent(fn ($package) => $this->minifier->minify($package, $devStability));

        return $this->renderMetadata($metadata, $request);
    }

    #[Route('/{alias}/pkg/{package}.json', name: 'mirror_metadata_v1', requirements: ['package' => '[A-Za-z0-9_.-]+/[A-Za-z0-9_.$-]+'], methods: ['GET'])]
    public function packageAction(string $package, string $alias, Request $request): Response
    {
        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->findPackageMetadata($package));

        return $this->renderMetadata($metadata, $request);
    }

    // provider - proxy full url name, include providers is not changed for root
    #[Route('/{alias}/{provider}', name: 'mirror_provider_includes', requirements: ['provider' => '.+'], methods: ['GET'])]
    public function providerAction(Request $request, $alias, $provider): Response
    {
        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->findProviderMetadata($provider));

        return $this->renderMetadata($metadata, $request);
    }

    protected function renderMetadata(JsonMetadata $metadata, Request $request): Response
    {
        $response = new Response($metadata->getContent(), 200, ['Content-Type' => 'application/json']);
        $response->setLastModified($metadata->lastModified());
        $response->isNotModified($request);

        return $response;
    }

    protected function wrap404Error(string $alias, callable $callback): JsonMetadata
    {
        try {
            $repo = $this->proxyRegistry->createRepository($alias);
            return $callback($repo);
        } catch (MetadataNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}
