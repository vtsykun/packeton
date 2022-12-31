<?php

namespace Packeton\EventListener;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Packeton\Entity\Version;
use Packeton\Service\DistConfig;
use Symfony\Component\HttpFoundation\RequestStack;

class DoctrineListener
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @param Version $version
     * @param PostLoadEventArgs $event
     *
     * @return void
     */
    public function postLoad(Version $version, PostLoadEventArgs $event)
    {
        if (!$request = $this->requestStack->getMainRequest()) {
            return;
        }

        $dist = $version->getDist();
        if (isset($dist['url']) && str_starts_with($dist['url'], DistConfig::HOSTNAME_PLACEHOLDER)) {
            $currentHost = $request->getSchemeAndHttpHost();

            $dist['url'] = str_replace(DistConfig::HOSTNAME_PLACEHOLDER, $currentHost, $dist['url']);
            $version->distNormalized = $dist;
        }
    }
}
