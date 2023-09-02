<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/api', name: 'api_', defaults: ['_format' => 'json'])]
class ApiHealthController extends AbstractController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function health(): Response
    {
        if (false === $this->getParameter('packeton_health_check')) {
            return new JsonResponse([], 404);
        }

        $checks = [
            'database:ping' => function() {
                /** @var Connection $conn */
                $conn = $this->container->get(ManagerRegistry::class)->getConnection();
                $conn->executeQuery('SELECT 1');
            },
            'redis:ping' => function() {
                /** @var \Redis $redis */
                $redis = $this->container->get(\Redis::class);
                $redis->get('packages-last-modify');
            },
            'cache:ping' => function() {
                $this->container->get(CacheInterface::class)->get('cache:ping', fn () => []);
            },
        ];

        $status = true;
        $checksResult = [];
        foreach ($checks as $name => $fn) {
            $item = $checksResult[$name] = $this->checkHealth($fn);
            $status = $status && ($item['status'] !== 'fail');
        }

        $result = [
            'status' => $status ? 'pass' : 'fail',
            'checks' => $checksResult,
        ];

        return new Response(json_encode($result, 448), $status ? 200 : 500, ['Content-Type' => 'application/json']);
    }

    protected function checkHealth(callable $check): array
    {
        $result = ['time' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')];

        try {
            $start = microtime(true);
            $result += $check() ?: [];
            $result['status'] = 'pass';
            $ping = microtime(true) - $start;

            $result += ['observedValue' => (float)round(1000*$ping, 2), 'observedUnit' => 'ms'];
        } catch (\Throwable $e) {
            $result['status'] = 'fail';
            if ($this->isGranted('ROLE_MAINTAINER')) {
                $result['output'] = '[' . $e::class . '] ' . $e->getMessage();
            }
        }

        return $result;
    }

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            \Redis::class,
            ManagerRegistry::class,
            CacheInterface::class
        ];
    }
}
