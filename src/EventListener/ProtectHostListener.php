<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class ProtectHostListener
{
    public static $allowedRoutes = [
        'root_packages' => 1,
        'root_providers' => 1,
        'metadata_changes' => 1,
        'root_package' => 1,
        'root_package_v2' => 1,
        'download_dist_package' => 1,
        'track_download' => 1,
        'track_download_batch' => 1,
        'root_packages_slug' => 1,
        'root_providers_slug' => 1,
        'root_package_slug' => 1,
        'root_package_v2_slug' => 1,
        'download_dist_package_slug' => 1,
        'track_download_batch_slug' => 1,
        'track_download_slug' => 1,
        'mirror_root' => 1,
        'mirror_metadata_v2' => 1,
        'mirror_metadata_v1' => 1,
        'mirror_zipball' => 1,
        'mirror_provider_includes' => 1,
    ];

    public function __construct(
        #[Autowire(param: 'packeton_web_protection')]
        protected ?array $protection = null
    ) {
    }

    #[AsEventListener('kernel.request', priority: 30)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (empty($this->protection)) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (isset(static::$allowedRoutes[$route])) {
            return;
        }

        if ($protectedHosts = ($this->protection['repo_hosts'] ?? [])) {
            $host = $request->getHost();
            if (in_array($host, $protectedHosts, true) || (in_array('*', $protectedHosts, true) && !in_array('!'.$host, $protectedHosts, true))) {
                $this->terminate($event);
            }
        }

        if ($allowIps = ($this->protection['allow_ips'] ?? null)) {
            $allowIps = array_map('trim', explode(',', $allowIps));
            if (false === IpUtils::checkIp($request->getClientIp() ?? '', $allowIps)) {
                $this->terminate($event);
            }
        }
    }

    private function terminate(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');

        $response = new JsonResponse(['error' => 'Not Found'], 404);
        if ($route === 'home' && ($customPage = $this->protection['custom_page'] ?? null)) {
            $customPage = is_file($customPage) ? file_get_contents($customPage) : $customPage;
            $response = new Response($customPage, $this->protection['status_code'] ?? 200);
            if ($contentType = $this->protection['content_type'] ?? null) {
                $response->headers->set('content-type', $contentType);
            }

            $event->getRequest()->attributes->set('_format', 'X-Debug');
        }

        $event->setResponse($response);
    }
}
