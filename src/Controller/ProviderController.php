<?php

namespace Packeton\Controller;

use Packeton\Attribute\Vars;
use Packeton\Composer\JsonResponse;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Model\PackageManager;
use Packeton\Service\DistManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProviderController extends AbstractController
{
    public function __construct(
        private readonly PackageManager $packageManager,
    ){}

    /**
     * @Route("/packages.json", name="root_packages", defaults={"_format" = "json"}, methods={"GET"})
     */
    public function packagesAction()
    {
        $rootPackages = $this->packageManager->getRootPackagesJson($this->getUser());

        return new JsonResponse($rootPackages);
    }

    /**
     * @Route(
     *     "/p/providers${hash}.json",
     *     requirements={"hash"="[a-f0-9]+"},
     *     name="root_providers", defaults={"_format" = "json"},
     *     methods={"GET"}
     * )
     *
     * @param string $hash
     * @return Response
     */
    public function providersAction($hash)
    {
        $providers = $this->packageManager->getProvidersJson($this->getUser(), $hash);
        if (!$providers) {
            return $this->createNotFound();
        }

        return new JsonResponse($providers);
    }

    /**
     * @Route(
     *     "/p/{package}.json",
     *     requirements={"package"="[\w+\/\-\$]+"},
     *     name="root_package", defaults={"_format" = "json"},
     *     methods={"GET"}
     * )
     *
     * @param string $package
     * @return Response
     */
    public function packageAction(string $package)
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

    /**
     * @Route(
     *     "/p2/{package}.json",
     *     requirements={"package"="[\w+\/\-\~]+"},
     *     name="root_package_v2", defaults={"_format" = "json"},
     *     methods={"GET"}
     * )
     */
    public function packageV2Action(Request $request, string $package)
    {
        $isDev = str_ends_with($package, '~dev');
        $package = preg_replace('/~dev$/', '', $package);

        $package = $this->packageManager->getPackageV2Json($this->getUser(), $package, $isDev, $lastModified);
        if (!$package) {
            return $this->createNotFound();
        }

        $response = new JsonResponse($package);
        $response->setLastModified(new \DateTime($lastModified));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @Route(
     *     "/zipball/{package}/{hash}",
     *     name="download_dist_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "hash"="[a-f0-9]{40}\.[a-z]+?"},
     *     methods={"GET"}
     * )
     *
     * @param Package $package
     * @param string $hash
     * @return Response
     */
    public function zipballAction(#[Vars('name')] Package $package, $hash)
    {
        $distManager = $this->container->get(DistManager::class);
        if (false === \preg_match('{[a-f0-9]{40}}i', $hash, $match)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not Found'], 404);
        }

        $versions = $package->getVersions()->filter(
            function (Version $version) use ($match) {
                if ($dist = $version->getDist()) {
                    return isset($dist['reference']) && $match[0] === $dist['reference'];
                }
                return false;
            }
        );

        // Try to download from cache
        if ($versions->count() === 0) {
            list($path, $versionName) = $distManager->lookupInCache($match[0], $package->getName());
            if (null !== $versionName) {
                $version = $package->getVersions()
                    ->filter(
                        function (Version $version) use ($versionName) {
                            return $versionName === $version->getVersion();
                        }
                )->first();
                if ($version && $this->isGranted('ROLE_FULL_CUSTOMER', $version)) {
                    return new BinaryFileResponse($path);
                }
            }
        }

        /** @var Version $version */
        foreach ($versions as $version) {
            if (!$this->isGranted('ROLE_FULL_CUSTOMER', $version)) {
                continue;
            }

            if ($path = $distManager->getDistPath($version)) {
                return new BinaryFileResponse($path);
            }
            break;
        }

        return $this->createNotFound();
    }

    protected function createNotFound()
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Not Found'], 404);
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
