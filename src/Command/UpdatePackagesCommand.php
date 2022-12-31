<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Command;

use Packeton\Entity\Package;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UpdatePackagesCommand extends Command
{
    protected static $defaultName = 'packagist:update';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-crawl of all packages, or if a package name is given forces an update of all versions'),
                new InputOption('delete-before', null, InputOption::VALUE_NONE, 'Force deletion of all versions before an update'),
                new InputOption('update-equal-refs', null, InputOption::VALUE_NONE, 'Force update of all versions even when they already exist'),
                new InputOption('update-crawl-interval', null, InputOption::VALUE_OPTIONAL, 'Package update interval in seconds.', 14400),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to update'),
            ])
            ->setDescription('Updates packages')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');
        $package = $input->getArgument('package');

        $doctrine = $this->getContainer()->get('doctrine');
        $deleteBefore = false;
        $updateEqualRefs = false;
        $randomTimes = true;

        $interval = $input->getOption('update-crawl-interval') ?: 14400; // 4 hour

        if ($package) {
            $packages = [['id' => $doctrine->getRepository(Package::class)->findOneByName($package)->getId()]];
            if ($force) {
                $updateEqualRefs = true;
            }
            $randomTimes = false;
        } elseif ($force) {
            $packages = $doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
            $updateEqualRefs = true;
        } else {
            $packages = $doctrine->getRepository(Package::class)->getStalePackages($interval);
        }

        $ids = [];
        foreach ($packages as $package) {
            $ids[] = (int) $package['id'];
        }

        if ($input->getOption('delete-before')) {
            $deleteBefore = true;
        }
        if ($input->getOption('update-equal-refs')) {
            $updateEqualRefs = true;
        }

        $scheduler = $this->getContainer()->get('scheduler');

        while ($ids) {
            $idsGroup = array_splice($ids, 0, 100);

            foreach ($idsGroup as $id) {
                $job = $scheduler->scheduleUpdate($id, $updateEqualRefs, $deleteBefore, $randomTimes ? new \DateTime('+'.rand(1, (int) ($interval/1.5)).'seconds') : null);
                if ($verbose) {
                    $output->writeln('Scheduled update job '.$job->getId().' for package '.$id);
                }
                $doctrine->getManager()->detach($job);
            }

            $doctrine->getManager()->clear();
        }

        return 0;
    }
}
