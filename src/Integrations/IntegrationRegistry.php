<?php

declare(strict_types=1);

namespace Packeton\Integrations;

use Packeton\Integrations\Exception\NotFoundAppException;
use Psr\Container\ContainerInterface;

class IntegrationRegistry
{
    public function __construct(
        protected ContainerInterface $container,
        protected array $names,
        protected array $loginProviders,
    ) {
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names);
    }

    public function get(string $name): IntegrationInterface
    {
        return $this->container->get($name);
    }

    public function getNames(): array
    {
        return $this->names;
    }

    public function getLoginProviders(): array
    {
        return $this->loginProviders;
    }

    public function findLogin(string $name, bool $check = true): LoginInterface
    {
        if (!$this->has($name)) {
            throw new NotFoundAppException('App does not exits');
        }

        $app = $this->container->get($name);
        if (!$app instanceof LoginInterface) {
            throw new NotFoundAppException("This app $name is not support for oauth2 login");
        }

        $conf = $app->getConfig();
        if ($check === true && (false === $conf->isLogin() || false === $conf->isEnabled())) {
            throw new NotFoundAppException("This app $name is disabled for login");
        }

        return $app;
    }

    public function findApp(string $name, bool $check = true): AppInterface
    {
        if (!$this->has($name)) {
            throw new NotFoundAppException('App does not exits');
        }

        $app = $this->container->get($name);
        if (!$app instanceof AppInterface) {
            throw new NotFoundAppException("$name used only for login.");
        }

        $conf = $app->getConfig();
        if ($check === true && (false === $conf->isEnabled())) {
            throw new NotFoundAppException("This app $name is disabled");
        }

        return $app;
    }

    public function findAllApps(): array
    {
        $apps = [];
        foreach ($this->names as $name) {
            $app = $this->container->get($name);
            if (!$app instanceof AppInterface) {
                continue;
            }
            $conf = $app->getConfig();
            if (false === $conf->isEnabled()) {
                continue;
            }
            $apps[] = $app;
        }

        return $apps;
    }
}
