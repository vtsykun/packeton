<?php

declare(strict_types=1);

namespace Packeton\Integrations\Bitbucket;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Exclude]
class BitbucketResultPager
{
    public $perPage = 50;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $url,
        private readonly array $params,
        private readonly string $method = 'GET',
        private readonly array $options = [],
    ) {
    }

    public function all(): array
    {
        $params = $this->params;
        $paginationParameter = $this->options['query_name'] ?? 'pagelen';
        $maxLimit = $this->options['per_page'] ?? $this->perPage;

        $params['query'][$paginationParameter] ??= $maxLimit;

        $processed = [];
        $url = $this->url;
        $result = [];

        while (true) {
            $response = $this->httpClient->request($this->method, $url, $params);
            $processed[$url] = 1;
            $current = $response->toArray();

            $result = array_merge($result, $current['values'] ?? []);
            $url = $current['next'] ?? null;
            if (null === $url || isset($processed[$url]) || empty($current['values'] ?? null)) {
                break;
            }
        }

        return $result;
    }
}
