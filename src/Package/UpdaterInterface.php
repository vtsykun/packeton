<?php

declare(strict_types=1);

namespace Packeton\Package;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;
use Packeton\Entity\Package;
use Seld\Signal\SignalHandler;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('updater')]
interface UpdaterInterface
{
    /**
     * Update a project
     *
     * @param IOInterface $io
     * @param Config $config
     * @param \Packeton\Entity\Package $package
     * @param RepositoryInterface $repository the repository instance used to update from
     * @param int $flags a few of the constants of this class
     * @param SignalHandler $signal
     *
     * @return Package
     */
    public function update(IOInterface $io, Config $config, Package $package, RepositoryInterface $repository, int $flags = 0, ?SignalHandler $signal = null): Package;

    /**
     * @return string[]
     */
    public static function supportRepoTypes(): iterable;
}
