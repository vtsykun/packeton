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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HookTestAction
{
    private $executor;
    private $registry;
    private $tokenStorage;
    private $requestStack;

    public function __construct(ManagerRegistry $registry, HookRequestExecutor $executor, TokenStorageInterface $tokenStorage = null, RequestStack $requestStack = null)
    {
        $this->registry = $registry;
        $this->executor = $executor;
        $this->tokenStorage = $tokenStorage;
        $this->requestStack = $requestStack;
    }

    public function runTest(Webhook $webhook, array $data)
    {
        $this->selectPackage($data);
        $this->selectVersion($data);
        $this->selectUser($data);
        $this->selectPayload($data);

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
            case Webhook::HOOK_USER_LOGIN:
                $context = [
                    'user' => $data['user'],
                    'ip_address' => $data['ip_address']
                ];
                break;
            case Webhook::HOOK_HTTP_REQUEST:
                $context = [
                    'request' => $data['payload'] ?? null,
                    'ip_address' => $data['ip_address'],
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
            if (null !== $this->tokenStorage) {
                if ($token = $this->tokenStorage->getToken()) {
                    $data['user'] = $token->getUser();
                    return;
                }
            }

            $data['user'] = $this->registry->getRepository(User::class)
                ->findOneBy([]);
        }
    }

    private function selectPayload(array &$data)
    {
        if (isset($data['payload'])) {
            try {
                $payload = @json_decode($data['payload'], true);
                $data['payload'] = $payload ?: $data['payload'];
            } catch (\Throwable $exception) {}
        } else {
            $data['payload'] = [];
        }

        if (!isset($data['ip_address'])) {
            $data['ip_address'] = '127.0.0.1';
            if ($this->requestStack and $req = $this->requestStack->getMasterRequest()) {
                $data['ip_address'] = $req->getClientIp();
            }
        }
    }
}
