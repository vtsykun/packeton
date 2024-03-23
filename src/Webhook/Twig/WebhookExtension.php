<?php

namespace Packeton\Webhook\Twig;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Entity\Webhook;
use Packeton\Util\ChangelogUtils;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * This extension only apply for webhook sandbox env.
 */
class WebhookExtension extends AbstractExtension implements ContextAwareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var WebhookContext */
    private $context;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ChangelogUtils $changelogUtils,
        private readonly \Redis $redis,
        private readonly HttpClientInterface $noPrivateHttpClient,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions(): array
    {
        $functions = [];
        $reflect = new \ReflectionClass(__CLASS__);
        foreach ($reflect->getMethods() as $method) {
            if ($method->isPublic() && str_starts_with($method->getName(), 'hook_function_')) {
                $functions[] = new TwigFunction(substr($method->getName(), 14), [$this, $method->getName()]);
            }
        }

        return $functions;
    }

    /**
     * Get commit messages between two tags
     *
     * {@inheritDoc}
     */
    public function hook_function_get_changelog($package, $fromVersion = null, $toVersion = null, $maxCount = 100)
    {
        $repo = $this->registry->getRepository(Package::class);
        if (is_int($package)) {
            $package = $repo->find($package);
        } elseif (is_string($package)) {
            $package = $repo->findOneBy(['name' => $package]);
        }

        if (!is_string($toVersion) || !$package instanceof Package) {
            return [];
        }
        if (!is_int($maxCount)) {
            $maxCount = 100;
        }

        if (!is_string($fromVersion)) {
            $fromVersion = $this->registry->getRepository(Version::class)
                ->getPreviousRelease($package->getName(), $toVersion);
        }
        if ($toVersion && $fromVersion) {
            return $this->changelogUtils->getChangelog($package, $fromVersion, $toVersion, $maxCount);
        }

        return [];
    }

    public function hook_function_preg_match_all($regex, $content, $matchOffset = null)
    {
        try {
            preg_match_all($regex, $content, $matches);
            $this->logger?->debug(sprintf('preg_match_all result "%s": "%s"', $regex, json_encode($matches)));
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Error in regex "%s": %s', $regex, $e->getMessage()));
            return [];
        }

        return is_int($matchOffset) ? $matches[$matchOffset] ?? [] : $matches;
    }

    /**
     * Key value storage. Set value
     *
     * {@inheritDoc}
     */
    public function hook_function_keyvalue_set(string $key, $value, $expire = 86400)
    {
        $key = 'hook.' . md5($key);
        if ($expire) {
            $this->redis->setex($key, $expire, $value);
        } else {
            $this->redis->set($key, $value);
        }
    }

    /**
     * Key value storage. Get value
     *
     * {@inheritDoc}
     */
    public function hook_function_keyvalue_get(string $key)
    {
        $key = 'hook.' . md5($key);
        return $this->redis->get($key);
    }

    /**
     * Json decode function.
     *
     * {@inheritDoc}
     */
    public function hook_function_json_decode($value)
    {
        return json_decode($value, true);
    }

    /**
     * PHP hash_mac.
     *
     * {@inheritDoc}
     */
    public function hook_function_hash_mac($algo, $value, string $key, bool $binary = false)
    {
        try {
            return hash_hmac($algo, (string)$value, $key, $binary);
        } catch (\Throwable $exception) {
            $this->logger?->error('hash_hmac error: ' . $exception->getMessage());
        }

        return null;
    }

    /**
     * Simple wrapper for HttpClient.
     * Allow make get http request from twig code (ONLY for webhooks).
     * Don't recommended to use, but this hack can be very useful
     *
     * Return decoded as array body, if JSON response.
     * {@inheritDoc}
     */
    public function hook_function_http_request(string $url, array $options = [])
    {
        $method = $options['method'] ?? 'GET';
        $isRaw = $options['raw'] ?? false;

        unset($options['method'], $options['raw']);

        try {
            $response = $this->noPrivateHttpClient->request($method, $url, $options);
        } catch (\Throwable $exception) {
            $this->logger?->error('Http request failed: ' . $exception->getMessage());
            return null;
        }

        $array = $info = $statusCode = $content = $headers = $array = null;
        try {
            $info = $response->getInfo();
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
            $array = $content ? @json_decode($content, true) : null;
        } catch (\Throwable $exception) {
            $this->logger?->warning('Http get response failed: ' . $exception->getMessage());
        }

        if (false === $isRaw) {
            return $array ?: $content;
        }

        return [
            'info' => $info,
            'statusCode' => $statusCode,
            'content' => $content,
            'toArray' => $array,
            'headers' => $headers,
        ];
    }

    public function hook_function_array_unique($value)
    {
        return is_array($value) ? array_unique($value) : null;
    }

    /**
     * Interrupt request
     *
     * {@inheritDoc}
     */
    public function hook_function_interrupt($reason = null)
    {
        throw new InterruptException('This request was interrupt by user from twig code. ' . $reason);
    }

    /**
     * Trigger other webhook by ID
     *
     * {@inheritdoc}
     */
    public function hook_function_trigger_webhook($hookId, array $context = [])
    {
        if (!$this->context instanceof WebhookContext) {
            return;
        }

        $hook = $hookId instanceof Webhook ? $hookId :
            $this->registry->getRepository(Webhook::class)->find((int) $hookId);
        if (null !== $hook) {
            $this->context[WebhookContext::CHILD_WEBHOOK][] = [$hook, $context];
        } else {
            $this->logger?->warning(sprintf('webhook %s not found', $hookId));
        }
    }

    /**
     * Log event from webhook.
     *
     * {@inheritdoc}
     */
    public function hook_function_log($level, $value)
    {
        if (!is_string($value)) {
            $value = json_encode($value);
        }

        $this->logger?->log($level, $value);
    }

    /**
     * @inheritDoc
     */
    public function setContext(?WebhookContext $context = null): void
    {
        $this->context = $context;
    }
}
