<?php

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class ProviderController extends Controller
{
    /**
     * @Route("/packages.json", name="packages", defaults={"_format" = "json"})
     * @Method({"GET"})
     */
    public function packagesAction()
    {
        if (!$user = $this->getUser()) {

        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @Route(
     *     "/zipball/{package}/{hash}",
     *     name="download_dist_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "hash"="[a-f0-9]{40}\.[a-z]+?"}
     * )
     * @Method({"GET"})
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     *
     * @param Package $package
     * @param string $hash
     * @return Response
     */
    public function zipballAction(Package $package, $hash)
    {
        $config = $this->container->get('packagist.dist_config');
        if (false === $this->isGranted('ROLE_USER', $package) || $config->isEnable() === false) {
            return new JsonResponse(['status' => 'error', 'message' => ''], 403);
        }
        if (false === \preg_match('{[a-f0-9]{40}}i', $hash, $match)) {
            return new JsonResponse([], 404);
        }

        $versions = $package->getVersions()->filter(
            function (Version $version) use ($match) {
                if ($dist = $version->getDist()) {
                    return isset($dist['reference']) && $match[0] === $dist['reference'];
                }
                return false;
            }
        );

        /** @var Version $version */
        foreach ($versions as $version) {
            if (false === $this->isGranted('ROLE_USER', $version)) {
                continue;
            }

            $dist = $version->getDist();
            if ($file = $config->generateDistFileName($version->getName(), $dist['reference']) and \file_exists($file)) {
                return new BinaryFileResponse($file);
            }
        }

        return new JsonResponse([], 404);
    }
}
