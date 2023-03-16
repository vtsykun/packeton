<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;

interface PacketonRepositoryInterface extends ConfigurableRepositoryInterface, RepositoryInterface
{
    public function getProcessExecutor(): ProcessExecutor;

    public function getHttpDownloader(): HttpDownloader;

    public function getConfig(): Config;

    public function getIO(): IOInterface;
}
