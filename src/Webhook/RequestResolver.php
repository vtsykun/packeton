<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Packeton\Entity\Webhook;
use Packeton\Webhook\Twig\ContextAwareInterface;
use Packeton\Webhook\Twig\PayloadRenderer;
use Packeton\Webhook\Twig\PlaceholderContext;
use Packeton\Webhook\Twig\PlaceholderExtension;
use Packeton\Webhook\Twig\WebhookContext;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class RequestResolver implements ContextAwareInterface, LoggerAwareInterface
{
    private $logger;
    private $renderer;
    private $storedPrefix;

    /**
     * @param PayloadRenderer $renderer
     * @param string $rootDir
     */
    public function __construct(PayloadRenderer $renderer, string $rootDir = null)
    {
        $this->renderer = $renderer;
        $this->storedPrefix = $rootDir ? rtrim($rootDir, '/') . '/var/webhooks/' : null;
    }

    /**
     * @param Webhook $webhook
     * @param array $context
     *
     * @return HookRequest[]
     */
    public function resolveHook(Webhook $webhook, array $context = [])
    {
        return iterator_to_array($this->doResolveHook($webhook, $context));
    }

    /**
     * @param Webhook $webhook
     * @param array $context
     * @return \Generator|void
     */
    private function doResolveHook(Webhook $webhook, array $context = [])
    {
        $separator = '-------------' . sha1(random_bytes(10)) . '---------------';
        $context[PlaceholderExtension::VARIABLE_NAME] = $placeholder = new PlaceholderContext();

        $this->renderer->init();
        if (null !== $this->logger) {
            $this->renderer->setLogger($this->logger);
        }

        if ($payload = $webhook->getPayload()) {
            if (preg_match('/^@\w+$/', trim($payload)) && null !== $this->storedPrefix) {
                $filename = $this->storedPrefix . substr(trim($payload), 1) . '.twig';
                if (@file_exists($filename)) {
                    $payload = file_get_contents($filename);
                }
            }
            $payload = (string) $this->renderer->createTemplate($payload)->render($context);
            $content = $webhook->getUrl() . $separator . trim($payload);
        } else {
            $content = $webhook->getUrl() . $separator;
        }

        foreach ($placeholder->walkContent($content) as $content) {
            list($url, $content) = explode($separator, $content);
            yield new HookRequest($url, $webhook->getMethod(), $webhook->getOptions() ?: [], $content ? trim($content) : null);
        }
    }

    /**
     * @inheritDoc
     */
    public function setContext(WebhookContext $context = null): void
    {
        $this->renderer->setContext($context);
    }

    /**
     * @inheritDoc
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
