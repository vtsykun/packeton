<?php

namespace Packagist\WebBundle\Webhook\Twig;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Util\ChangelogUtils;
use Predis\Client as Redis;
use Symfony\Component\HttpClient\HttpClient;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * This extension only apply for webhook sandbox env.
 */
class WebhookExtension extends AbstractExtension implements ContextAwareInterface
{
    private $registry;
    private $changelogUtils;
    private $redis;

    /** @var WebhookContext */
    private $context;

    public function __construct(
        ManagerRegistry $registry,
        ChangelogUtils $changelogUtils,
        Redis $redis
    ) {
        $this->registry = $registry;
        $this->changelogUtils = $changelogUtils;
        $this->redis = $redis;
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        $functions = [];
        $reflect = new \ReflectionClass(__CLASS__);
        foreach ($reflect->getMethods() as $method) {
            if ($method->isPublic() && strpos($method->getName(), 'hook_function_') === 0) {
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
            @preg_match_all($regex, $content, $matches);
        } catch (\Throwable $e) {
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
        $client = HttpClient::create(['max_duration' => 60]);

        try {
            $response = $client->request($method, $url, $options);
        } catch (\Throwable $exception) {
            return null;
        }

        $array = $info = $statusCode = $content = $headers = $array = null;
        try {
            $info = $response->getInfo();
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
            $array = $content ? @json_decode($content, true) : null;
        } catch (\Throwable $exception) {}

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
        }
    }

    /**
     * @inheritDoc
     */
    public function setContext(WebhookContext $context = null): void
    {
        $this->context = $context;
    }
}
