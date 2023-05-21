<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\JsonResponse;
use Packeton\Entity\Package;
use Packeton\Model\PackageManager;
use Packeton\Model\ProviderManager;
use Packeton\Service\DistManager;
use Packeton\Util\UserAgentParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProviderController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ProviderManager $providerManager,
        private readonly ManagerRegistry $registry,
    ) {
    }

    #[Route('/packages.json', name: 'root_packages', defaults: ['_format' => 'json'], methods: ['GET'])]
    public function packagesAction(Request $request): Response
    {
        $response = new JsonResponse([]);
        $response->setLastModified($this->providerManager->getRootLastModify());
        if ($response->isNotModified($request)) {
            return $response;
        }

        $ua = new UserAgentParser($request->headers->get('User-Agent'));
        $apiVersion = $request->query->get('ua') ? (int) $request->query->get('ua') : $ua->getComposerMajorVersion();

        $rootPackages = $this->packageManager->getRootPackagesJson($this->getUser(), $apiVersion);

        $response->setData($rootPackages);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $response;
    }

    #[Route('/p/providers${hash}.json', name: 'root_providers', requirements: ['hash' => '[a-f0-9]+'], defaults: ['_format' => 'json'], methods: ['GET'])]
    public function providersAction($hash): Response
    {
        $providers = $this->packageManager->getProvidersJson($this->getUser(), $hash);
        if (!$providers) {
            return $this->createNotFound();
        }

        return new JsonResponse($providers);
    }

    /**
     * Copy from Packagist. Can be used for https://workers.cloudflare.com sync mirrors.
     * Used two unix format: Packagist and RFC-3399
     */
    #[Route('/metadata/changes.json', name: 'metadata_changes', defaults: ['_format' => 'json'], methods: ['GET'])]
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

    #[Route(
        '/p/{package}.json',
        name: 'root_package',
        requirements: ['package' => '%package_name_regex_v1%'],
        defaults: ['_format' => 'json'],
        methods: ['GET']
    )]
    public function packageAction(string $package): Response
    {
        $package = \explode('$', $package);
        if (\count($package) !== 2) {
            $package = $this->packageManager->getPackageJson($this->getUser(), $package[0]);
            if ($package) {
                return new JsonResponse($package);
            }
            return $this->createNotFound();
        }

        $package = $this->packageManager->getCachedPackageJson($this->getUser(), $package[0], $package[1]);
        if (!$package) {
            return $this->createNotFound();
        }

        return new JsonResponse($package);
    }

    #[Route(
        '/p2/{package}.json',
        name: 'root_package_v2',
        requirements: ['package' => '%package_name_regex_v2%'],
        defaults: ['_format' => 'json'],
        methods: ['GET']
    )]
    public function packageV2Action(Request $request, string $package): Response
    {
        $response = new JsonResponse([]);
        $response->setLastModified($this->providerManager->getLastModify($package));
        if ($response->isNotModified($request)) {
            return $response;
        }

        $isDev = str_ends_with($package, '~dev');
        $package = preg_replace('/~dev$/', '', $package);

        $package = $this->packageManager->getPackageV2Json($this->getUser(), $package, $isDev);
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

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                DistManager::class
            ]
        );
    }
}
