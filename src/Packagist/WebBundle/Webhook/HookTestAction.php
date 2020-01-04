<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Webhook\Twig\WebhookContext;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HookTestAction
{
    private $executor;
    private $registry;

    public function __construct(ManagerRegistry $registry, HookRequestExecutor $executor)
    {
        $this->registry = $registry;
        $this->executor = $executor;
    }

    public function runTest(Webhook $webhook, array $data)
    {
        $this->selectPackage($data);
        $this->selectVersion($data);
        $this->selectUser($data);

        switch ($data['event']) {
            case Webhook::HOOK_RL_UPDATE:
            case Webhook::HOOK_RL_NEW:
            case Webhook::HOOK_PUSH_UPDATE:
            case Webhook::HOOK_PUSH_NEW:
                $context = [
                    'package' => $data['package'],
                    'versions' => $data['versions']
                ];
                break;
            case Webhook::HOOK_REPO_NEW:
                $context = [
                    'package' => $data['package'],
                ];
                break;
            case Webhook::HOOK_RL_DELETE:
                $versions = array_map(function (Version $version) {
                    return $version->toArray();
                }, $data['versions']);

                $context = [
                    'package' => $data['package'],
                    'versions' => $versions
                ];
                break;
            case Webhook::HOOK_REPO_DELETE:
                $repo = $this->registry->getRepository(Version::class);
                $package = $data['package'] ?? null;
                if ($package instanceof Package) {
                    $package = $package->toArray($repo);
                }
                $context = [
                    'package' => $package,
                ];
                break;
            case Webhook::HOOK_REPO_FAILED:
                $context = [
                    'package' => $data['package'],
                    'message' => 'Exception message'
                ];
                break;
            default:
                $context = [];
                break;
        }

        $context['event'] = $data['event'];
        $client = null;
        if (($data['sendReal'] ?? false) !== true) {
            $callback = function () {
                $responseTime = rand(0, 900000);
                usleep($responseTime);
                return new MockResponse('true', [
                    'total_time' => $responseTime/1000.0,
                    'response_headers' => [
                        'Content-type' => 'application/json',
                        'Pragma' => 'no-cache',
                        'Server' => 'mock-http-client',
                    ]
                ]);
            };

            $client = new MockHttpClient($callback);
        }

        return $this->processChildWebhook($webhook, $context, $client);
    }

    /**
     * @param Webhook $webhook
     * @param array $context
     * @param HttpClientInterface $client
     * @param int $nestingLevel
     *
     * @return HookResponse[]
     */
    private function processChildWebhook(Webhook $webhook, array $context, HttpClientInterface $client = null, int $nestingLevel = 0)
    {
        if ($nestingLevel >= 3) {
            return [new HookErrorResponse('Maximum webhook nesting level of 3 reached')];
        }

        $runtimeContext = new WebhookContext();
        $this->executor->setContext($runtimeContext);

        $child = $response = $this->executor->executeWebhook($webhook, $context, $client);
        $this->executor->setContext(null);

        if (isset($runtimeContext[WebhookContext::CHILD_WEBHOOK])) {
            /** @var Webhook $childHook */
            foreach ($runtimeContext[WebhookContext::CHILD_WEBHOOK] as list($childHook, $childContext)) {
                if (null !== $childHook->getOwner() && $childHook->getVisibility() === Webhook::USER_VISIBLE && $childHook->getOwner() !== $webhook->getOwner()) {
                    $response[] = new HookErrorResponse('You can not call private webhooks of another user owner, please check nesting webhook visibility');
                    continue;
                }

                $context['parentResponse'] = reset($child);
                $child = $this->processChildWebhook($childHook, $childContext, $client, $nestingLevel+1);
                $response = array_merge($response, $child);
            }
        }

        return $response;
    }

    private function selectPackage(array &$data): void
    {
        if (!($data['package'] ?? null) instanceof Package) {
            $data['package'] = $this->registry->getRepository(Package::class)
                ->findOneBy([]);
        }
    }

    private function selectVersion(array &$data): void
    {
        /** @var Package $package */
        if (!$package = $data['package']) {
            $data['versions'] = [];
            return;
        }

        $isStability = in_array($data['event'] ?? '', [Webhook::HOOK_RL_DELETE, Webhook::HOOK_RL_UPDATE, Webhook::HOOK_RL_NEW]);
        $collection = $package->getVersions()->filter(function (Version $version) use ($isStability) {
            return $isStability === false || !$version->isDevelopment();
        });

        if (isset($data['versions'])) {
            $versions = array_map('trim', explode(',', $data['versions']));
            $collection = $collection->filter(function (Version $version) use ($versions) {
                return in_array($version->getVersion(), $versions);
            });
            $data['versions'] = array_values($collection->toArray());
        } elseif ($ver = $collection->first()) {
            $data['versions'] = [$ver];
        } else {
            $data['versions'] = [];
        }
    }

    private function selectUser(array &$data): void
    {
        if (!($data['user'] ?? null) instanceof User) {
            $data['user'] = $this->registry->getRepository(User::class)
                ->findOneBy([]);
        }
    }
}
