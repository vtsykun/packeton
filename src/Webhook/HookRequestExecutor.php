<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Packeton\Entity\Webhook;
use Packeton\Webhook\Twig\ContextAwareInterface;
use Packeton\Webhook\Twig\InterruptException;
use Packeton\Webhook\Twig\WebhookContext;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HookRequestExecutor implements ContextAwareInterface, LoggerAwareInterface
{
    private ?LoggerInterface $logger;

    public function __construct(
        private readonly RequestResolver $requestResolver,
        private readonly HmacOpensslCrypter $crypter,
        private readonly HttpClientInterface $noPrivateHttpClient,
    ) {
    }

    /**
     * @param Webhook $webhook
     * @param HttpClientInterface|null $client
     * @param array $variables
     * @return HookResponse[]
     */
    public function executeWebhook(Webhook $webhook, array $variables = [], HttpClientInterface $client = null)
    {
        $variables['webhook'] = $webhook;
        $client ??= $this->noPrivateHttpClient;

        $maxAttempt = 3;
        try {
            $requests = $this->requestResolver->resolveHook($webhook, $variables);
        } catch (\Throwable $exception) {
            return [$this->createFailsResponse($webhook, $exception)];
        }

        // Limit only 50 first requests.
        $responses = [];
        $requests = array_slice($requests, 0, 50);
        foreach ($requests as $request) {
            $options = $request->getOptions();

            $secrets = $options['secrets'] ?? null;
            unset($options['secrets']);

            if ($body = $request->getBody()) {
                if (is_string($body)) {
                    if (is_array($json = @json_decode($body, true))) {
                        $options['json'] = $json;
                    } else {
                        $options['body'] = $body;
                    }
                } else {
                    $options['json'] = $body;
                }
            }

            try {
                if (is_string($secrets)) {
                    if (!$this->crypter->isEncryptData($secrets)) {
                        throw new \InvalidArgumentException('Unable to decrypt secrets parameter. Probably encryption key was changed, please check you configuration');
                    }

                    $secrets = $this->crypter->decryptData($secrets);
                    $secrets = $secrets ? json_decode($secrets, true) : null;
                    if (empty($secrets) || !is_array($secrets)) {
                        throw new \InvalidArgumentException('Unable to decrypt secrets, probably enc key was changed, please check you configuration.');
                    }
                }

                $cloneRequestOpts = $options;
                [$url, $options] = $secrets ? $this->processSecrets($secrets, $request->getUrl(), $options) : [$request->getUrl(), $options];

                $response = $client->request($request->getMethod(), $url, $options);
                $headers = array_map(fn ($item) => $item[0] ?? $item, $response->getHeaders(false));
                $options = [
                    'status_code' => $response->getStatusCode(),
                    'headers' => $headers,
                ];
                if ($info = $response->getInfo()) {
                    $options['request_headers'] = $this->getRequestHeaders($info, $request->getHeaders($cloneRequestOpts));
                    $options = array_merge($info, $options);
                }

                [$content, $options] = $this->hideSensitiveParameters(substr($response->getContent(false), 0, 8192), $options, $secrets);
                $request = $this->hideSensitiveRequest($request, $secrets);

                $responses[] = new HookResponse($request, $content, $options);
            } catch (\Exception $exception) {
                $request =  $this->hideSensitiveRequest($request, $secrets);
                $responses[] = $this->createFailsResponse($webhook, $exception, $request);
                $maxAttempt--;
            }

            if ($maxAttempt < 0) {
                break;
            }
        }

        return $responses;
    }

    private function processSecrets(array $secrets, string $url, array $options): array
    {
        if (!isset($secrets['allowed-domains'])) {
            $this->logger?->warning('Using secrets without restriction "allowed-domains" is not secure. Please set allowed domains parameters for secrets options');
        } else {
            $domains = (array)$secrets['allowed-domains'];
            $hostname = parse_url($url, PHP_URL_HOST);

            if (empty($hostname) || !in_array($hostname, $domains, true)) {
                throw new \InvalidArgumentException("This domain $hostname is not allowed, only allowed-domains: " . implode(',', $domains));
            }
        }

        $secretWrapper = function(&$node) use ($secrets) {
            if (is_string($node)) {
                foreach ($secrets as $name => $secret) {
                    if (is_string($secret)) {
                        $name = 'secrets.' . $name;
                        $node = str_replace('${' . $name . '}', $secret, $node);
                        $node = str_replace('${ ' . $name . ' }', $secret, $node);
                    }
                }
            }
            return $node;
        };

        array_walk_recursive($options, $secretWrapper);
        return [$secretWrapper($url), $options];
    }

    private function hideSensitiveRequest(HookRequest $request, mixed $secrets = null): HookRequest
    {
        $opts = $this->hideSensitiveParameters(null, $request->jsonSerialize(), $secrets)[1];
        if (is_array($headers = $opts['options']['headers'] ?? null)) {
            foreach ($headers as $name => $value) {
                if (str_contains(strtolower($name), 'authorization')) {
                    $headers[$name] = '***';
                }
            }
            $opts['options']['headers'] = $headers;
        }

        return HookRequest::fromArray($opts);
    }

    private function hideSensitiveParameters(mixed $content, array $options, mixed $secrets = null): array
    {
        $secretHider = function(&$content) use ($secrets) {
            if (is_string($content)) {
                foreach ($secrets as $value) {
                    $content = is_string($value) ? str_replace($value, '***', $content) : $content;
                }
            }
            return $content;
        };

        if (is_array($secrets)) {
            $content = $secretHider($content);
            array_walk_recursive($options, $secretHider);
        }

        if (isset($options['request_headers']['Authorization'])) {
            $options['request_headers']['Authorization'] = '***';
        }
        if (isset($options['request_headers']['authorization'])) {
            $options['request_headers']['authorization'] = '***';
        }

        return [$content, $options];
    }

    private function getRequestHeaders($info, array $baseHeaders = [])
    {
        if (!isset($info['debug']) || !is_string($info['debug'])) {
            return $baseHeaders;
        }

        $headers = [];
        foreach ($baseHeaders as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        [$debug] = explode("\r\n\r\n", $info['debug']);
        $debug = explode("\r\n", $debug);
        array_shift($debug);
        foreach ($debug as $header) {
            $header = explode(':', $header, 2);
            if (count($header) === 2) {
                $headers[strtolower($header[0])] = $header[1];
            }
        }

        return $headers;
    }

    private function createFailsResponse(Webhook $webhook, \Throwable $exception, HookRequest $request = null)
    {
        $message = null;
        if ($exception->getPrevious() instanceof InterruptException) {
            $message = $exception->getPrevious()->getMessage();
        } else {
            while ($exception) {
                $message = null === $message ?
                    sprintf("Exception (%s). %s", get_class($exception), $exception->getMessage()) :
                    $message . sprintf("\n * Prev exception (%s). %s", get_class($exception), $exception->getMessage());

                $exception = $exception->getPrevious();
            }
        }

        if (null === $request) {
            $request = new HookRequest($webhook->getUrl(), $webhook->getMethod(), [], $message);
            $message = null;
        }

        return new HookResponse($request, $message);
    }

    /**
     * @inheritDoc
     */
    public function setContext(WebhookContext $context = null): void
    {
        $this->requestResolver->setContext($context);
    }

    /**
     * @inheritDoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->requestResolver->setLogger($logger);
    }
}
