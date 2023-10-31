<?php

declare(strict_types=1);

namespace Packeton\Composer\Util;

use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Util\Platform;

class ConfigFactory extends Factory
{
    protected static $overwriteHome = null;

    /**
     * @param string|null $overwriteHome
     * @return void
     */
    public static function setHomeDir(?string $overwriteHome): void
    {
        if (!is_dir($overwriteHome)) {
            @mkdir($overwriteHome);
        }

        static::$overwriteHome = $overwriteHome;
    }

    /**
     * @return string
     */
    public static function getHomeDir(): string
    {
        if (static::$overwriteHome) {
            return static::$overwriteHome;
        }

        return parent::getHomeDir();
    }

    /**
     * {@inheritdoc}
     */
    protected static function getCacheDir(string $home): string
    {
        $cacheDir = rtrim($home, '/') . '/cache';

        return rtrim(strtr($cacheDir, '\\', '/'), '/');
    }

    /**
     * {@inheritdoc}
     */
    public static function createConfig(?IOInterface $io = null, ?string $cwd = null): Config
    {
        $cwd = $cwd ?? Platform::getCwd(true);

        $config = new Config(true, $cwd);

        // determine and add main dirs to the config
        $home = static::getHomeDir();
        $config->merge([
            'config' => [
                'home' => $home,
                'cache-dir' => static::getCacheDir($home),
                'data-dir' => $home,
            ],
        ], Config::SOURCE_DEFAULT);

        // load global config
        $file = new JsonFile($config->get('home').'/config.json');
        if ($file->exists()) {
            if ($io instanceof IOInterface) {
                $io->writeError('Loading config file ' . $file->getPath(), true, IOInterface::DEBUG);
            }
            static::validateJsonSchema($io, $file);
            $config->merge($file->read(), $file->getPath());
        }
        $config->setConfigSource(new JsonConfigSource($file));

        // load global auth file
        $file = new JsonFile($config->get('home').'/auth.json');
        if ($file->exists()) {
            if ($io instanceof IOInterface) {
                $io->writeError('Loading config file ' . $file->getPath(), true, IOInterface::DEBUG);
            }
            static::validateJsonSchema($io, $file, JsonFile::AUTH_SCHEMA);
            $config->merge(['config' => $file->read()], $file->getPath());
        }
        $config->setAuthConfigSource(new JsonConfigSource($file, true));

        // load COMPOSER_AUTH environment variable if set
        if ($composerAuthEnv = Platform::getEnv('COMPOSER_AUTH')) {
            $authData = json_decode($composerAuthEnv);
            if (null === $authData) {
                throw new \UnexpectedValueException('COMPOSER_AUTH environment variable is malformed, should be a valid JSON object');
            } else {
                if ($io instanceof IOInterface) {
                    $io->writeError('Loading auth config from COMPOSER_AUTH', true, IOInterface::DEBUG);
                }
                static::validateJsonSchema($io, $authData, JsonFile::AUTH_SCHEMA, 'COMPOSER_AUTH');
                $authData = json_decode($composerAuthEnv, true);
                if (null !== $authData) {
                    $config->merge(['config' => $authData], 'COMPOSER_AUTH');
                }
            }
        }

        return $config;
    }

    /**
     * @param mixed $fileOrData
     * @param JsonFile::*_SCHEMA $schema
     */
    private static function validateJsonSchema(?IOInterface $io, $fileOrData, int $schema = JsonFile::LAX_SCHEMA, ?string $source = null): void
    {
        if (Platform::isInputCompletionProcess()) {
            return;
        }

        try {
            if ($fileOrData instanceof JsonFile) {
                $fileOrData->validateSchema($schema);
            } else {
                if (null === $source) {
                    throw new \InvalidArgumentException('$source is required to be provided if $fileOrData is arbitrary data');
                }
                JsonFile::validateJsonSchema($source, $fileOrData, $schema);
            }
        } catch (JsonValidationException $e) {
            $msg = $e->getMessage().', this may result in errors and should be resolved:'.PHP_EOL.' - '.implode(PHP_EOL.' - ', $e->getErrors());
            if ($io instanceof IOInterface) {
                $io->writeError('<warning>'.$msg.'</>');
            } else {
                throw new \UnexpectedValueException($msg);
            }
        }
    }
}
