<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Webhook\Twig\ContextAwareInterface;
use Packagist\WebBundle\Webhook\Twig\InterruptException;
use Packagist\WebBundle\Webhook\Twig\WebhookContext;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HookRequestExecutor implements ContextAwareInterface, LoggerAwareInterface
{
    private $requestResolver;
    private $logger;

    public function __construct(RequestResolver $requestResolver)
    {
        $this->requestResolver = $requestResolver;
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
        if (null === $client) {
            $client = HttpClient::create(['max_duration' => 60]);
        }

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
            if ($body = $request->getBody()) {
                $options['body'] = $request->getBody();
                try {
                    if ($json = @json_decode($body, true)) {
                        $options['json'] = $json;
                        unset($options['body']);
                    }
                } catch (\Throwable $e) {}
            }

            try {
                $response = $client->request($request->getMethod(), $request->getUrl(), $options);
                $headers = array_map(function ($item) {
                    return isset($item[0]) ? $item[0] : $item;
                }, $response->getHeaders(false));
                $options = [
                    'status_code' => $response->getStatusCode(),
                    'headers' => $headers,
                ];
                if ($info = $response->getInfo()) {
                    $options['request_headers'] = $this->getRequestHeaders($info, $request->getHeaders());
                    $options = array_merge($info, $options);
                }

                $responses[] = new HookResponse(
                    $request,
                    substr($response->getContent(false), 0, 8192), // Save only first 8k bytes to database
                    $options
                );
            } catch (\Exception $exception) {
                $responses[] = $this->createFailsResponse($webhook, $exception, $request);
                $maxAttempt--;
            }

            if ($maxAttempt < 0) {
                break;
            }
        }

        return $responses;
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

        list($debug) = explode("\r\n\r\n", $info['debug']);
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
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->requestResolver->setLogger($logger);
    }
}
