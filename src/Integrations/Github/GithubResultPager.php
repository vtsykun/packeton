<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[Exclude]
class GithubResultPager
{
    public $perPage = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $url,
        private readonly array $params,
        private readonly string $method = 'GET'
    ) {
    }

    public function all(string $column = null): array
    {
        $params = $this->params;
        $params['query']['per_page'] ??= $this->perPage;

        $processed = [];
        $url = $this->url;
        $result = [];

        while (true) {
            $response = $this->httpClient->request($this->method, $url, $params);
            $processed[$url] = 1;
            $current = $response->toArray();
            if ($column !== null) {
                if (isset($current[$column])) {
                    $result = array_merge($result, $current[$column]);
                } elseif (is_array($current[0] ?? null)) {
                    $result = array_merge($result, $current);
                }
            } else {
                $result = array_merge($result, $current);
            }

            $pagination = $this->getPagination($response);
            $url = $pagination['next'] ?? null;
            if (null === $url || isset($processed[$url])) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function getPagination(ResponseInterface $response): array
    {
        if (null === ($header = $response->getHeaders()['link'][0] ?? null)) {
            return [];
        }

        $pagination = [];
        foreach (explode(',', $header) as $link) {
            preg_match('/<(.*)>; rel="(.*)"/i', trim($link, ','), $match);
            if (3 === count($match)) {
                $pagination[$match[2]] = $match[1];
            }
        }

        return $pagination;
    }
}
