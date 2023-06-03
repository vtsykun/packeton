<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session may be defined as STRICT, so http oauth2 flow can not use session storage
 */
class OAuth2State
{
    public function __construct(
        protected \Redis $redis,
        protected RequestStack $requestStack
    ) {
    }

    public function getStateBag(): ParameterBag
    {
        $bag = new ParameterBag();
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return $bag;
        }

        if ($request->attributes->has('_oauth2_state_bag')) {
            return $request->attributes->get('_oauth2_state_bag');
        }

        $state = (string)$request->cookies->get('oauth2_state');
        if (strlen($state) === 40) {
            $key = "state:" . sha1($state);
            $data = $this->redis->get($key);

            $data = is_string($data) ? json_decode($data, true) : null;
            $bag = new ParameterBag(is_array($data) ? $data : []);
        }

        $request->attributes->set('_oauth2_state_bag', $bag);

        return $bag;
    }

    public function set(string $key, mixed $value): void
    {
        $this->getStateBag()->set($key, $value);
    }

    public function get(string $key): mixed
    {
        return $this->getStateBag()->get($key);
    }

    public function save(Response $response = null): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || false === $request->attributes->has('_oauth2_state_bag')) {
            return;
        }

        $bag = $request->attributes->get('_oauth2_state_bag');
        $data = $bag instanceof ParameterBag ? $bag->all() : [];

        $state = $request->cookies->get('oauth2_state');
        if ($state === null && $response === null) {
            return;
        }

        $state ??= sha1(random_bytes(10));
        // Set state to LAX cookie
        $response?->headers->setCookie(Cookie::create('oauth2_state', $state, 3600 + time()));
        $this->redis->setex($this->getKey($state), 3600, json_encode($data));
    }

    private function getKey(string $state): string
    {
        return "state:" . sha1($state);
    }
}
