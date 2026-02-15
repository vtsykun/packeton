<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Manager\RootMetadataMerger;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyRepositoryInterface;
use Packeton\Mirror\Model\StrictProxyRepositoryInterface as PRI;
use Packeton\Mirror\Service\ComposeProxyRegistry;
use Packeton\Security\Acl\ObjectIdentity;
use Packeton\Util\UserAgentParser;
use Packeton\Util\PacketonUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/mirror', defaults:['_format' => 'json'])]
class MirrorController extends AbstractController
{
    public function __construct(
        protected ComposeProxyRegistry $proxyRegistry,
        protected RootMetadataMerger $metadataMerger,
        protected MetadataMinifier $minifier,
    ) {
    }

    #[Route('/{alias}', name: 'mirror_index', defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(string $alias): Response
    {
        try {
            $this->checkAccess($alias);
            $config = $this->proxyRegistry->getProxyConfig($alias);
        } catch (MetadataNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        $repo = $this->generateUrl('mirror_index', ['alias' => $alias], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('proxies/mirror.html.twig', ['alias' => $alias, 'repoUrl' => $repo, 'repo' => $config]);
    }

    #[Route('/{alias}/packages.json', name: 'mirror_root', methods: ['GET'])]
    public function rootAction(Request $request, string $alias): Response
    {
        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->rootMetadata());

        $uaParser = new UserAgentParser($request->headers->get('User-Agent'));
        $api = $uaParser->getComposerMajorVersion();

        return $this->renderMetadata($metadata, $request, fn ($meta) => $this->metadataMerger->merge($meta, $api));
    }

    #[Route('/{alias}/p2/{package}.json', name: 'mirror_metadata_v2', requirements: ['package' => '%mirror_metadata_regex_v2%'], methods: ['GET'])]
    public function metadataV2Action(string $package, string $alias, Request $request): Response
    {
        $devStability = \str_ends_with($package, '~dev');
        $package = \preg_replace('/~dev$/', '', $package);

        $modifiedSince = $request->headers->get('If-Modified-Since');
        $modifiedSince = $modifiedSince ? (\strtotime($modifiedSince) ?: null) : null;

        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->findPackageMetadata($package, $modifiedSince));

        if (false === $metadata->isNotModified()) {
            $metadata = $metadata->withContent(fn ($package) => $this->minifier->minify($package, $devStability));
        }

        return $this->renderMetadata($metadata, $request);
    }

    #[Route('/{alias}/pkg/{package}.json', name: 'mirror_metadata_v1', requirements: ['package' => '%mirror_metadata_regex_v1%'], methods: ['GET'])]
    public function packageAction(string $package, string $alias, Request $request): Response
    {
        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->findPackageMetadata($package));

        return $this->renderMetadata($metadata, $request);
    }

    #[Route(
        '/{alias}/zipball/{package}/{version}/{ref}.{type}',
        name: 'mirror_zipball',
        requirements: ['package' => '%mirror_metadata_regex%'],
        methods: ['GET']
    )]
    public function zipball(string $alias, string $package, string $version, string $ref, string $type): Response
    {
        try {
            $this->checkAccess($alias);
            $dm = $this->proxyRegistry->getProxyDownloadManager($alias);

            $ref = str_starts_with($ref, 'ref') ? substr($ref, 3) : $ref;
            $path = $dm->distPath($package, $version, $ref, $type);

            $response = new BinaryFileResponse($path);
            $response->setAutoEtag();
            $response->setPublic();

            return $response;
        } catch (MetadataNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            // Local cache write failed, try streaming directly from S3
            $dm = $this->proxyRegistry->getProxyDownloadManager($alias);
            $stream = $dm->distStream($package, $version, $ref, $type);

            if ($stream !== null) {
                return new StreamedResponse(PacketonUtils::readStream($stream), 200, [
                    'Content-Type' => 'application/zip',
                ]);
            }

            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    // provider - proxy full url name, include providers is not changed for root
    #[Route('/{alias}/{provider}', name: 'mirror_provider_includes', requirements: ['provider' => '.+'], methods: ['GET'])]
    public function providerAction(Request $request, $alias, $provider): Response
    {
        $metadata = $this->wrap404Error($alias, fn (PRI $repo) => $repo->findProviderMetadata($provider));

        return $this->renderMetadata($metadata, $request);
    }

    protected function renderMetadata(JsonMetadata $metadata, Request $request, ?callable $lazyLoad = null): Response
    {
        $response = new Response($metadata->getContent(), 200, ['Content-Type' => 'application/json']);
        $response->setLastModified($metadata->lastModified());
        $notModified = $response->isNotModified($request);
        if ($metadata->isNotModified()) {
            $response->setNotModified();
        }

        if (null !== $lazyLoad && false === $notModified) {
            $metadata = $lazyLoad($metadata);
            $response->setContent($metadata instanceof JsonMetadata ? $metadata->getContent() : $metadata);
        }

        return $response;
    }

    protected function wrap404Error(string $alias, callable $callback): JsonMetadata
    {
        try {
            $this->checkAccess($alias);
            $repo = $this->proxyRegistry->createACLAwareRepository($alias);
            return $callback($repo);
        } catch (MetadataNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    protected function checkAccess(string $alias)
    {
        try {
            $config = $this->proxyRegistry->getProxyConfig($alias);
        } catch (MetadataNotFoundException) {
            throw $this->createNotFoundException();
        }

        if (false === $config->isPublicAccess()) {
            if (null !== $this->getUser()) {
                if (!$this->isGranted('ROLE_MAINTAINER') && !$this->isGranted('VIEW', new ObjectIdentity($alias, ProxyRepositoryInterface::class))) {
                    throw $this->createAccessDeniedException();
                }
            } else {
                throw $this->createNotFoundException();
            }
        }
    }
}
