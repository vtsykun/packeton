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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Repository\PackageRepository;
use Packeton\Service\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('packagist:update', 'Updates packages')]
class UpdatePackagesCommand extends Command
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
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

        $deleteBefore = false;
        $updateEqualRefs = false;
        $randomTimes = true;

        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);
        $interval = $input->getOption('update-crawl-interval') ?: 14400; // 4 hour

        if ($package) {
            $packages = [$repo->findOneByName($package)->getId()];
            if ($force) {
                $updateEqualRefs = true;
            }
            $randomTimes = false;
        } elseif ($force) {
            $packages = $em->getConnection()->fetchFirstColumn('SELECT p.id FROM package p WHERE p.parent_id IS NULL ORDER BY p.id ASC');
            $updateEqualRefs = true;
        } else {
            $packages = $repo->getStalePackages($interval);
            $packages = $repo->filterByJson($packages, fn($data) => ($data['disabled_update'] ?? false) !== true);
        }

        if ($input->getOption('delete-before')) {
            $deleteBefore = true;
        }
        if ($input->getOption('update-equal-refs')) {
            $updateEqualRefs = true;
        }


        while ($packages) {
            $idsGroup = array_splice($packages, 0, 100);

            foreach ($idsGroup as $id) {
                $job = $this->scheduler->scheduleUpdate($id, $updateEqualRefs, $deleteBefore, $randomTimes ? new \DateTime('+'.rand(1, (int) ($interval/1.5)).'seconds') : null, true);
                if ($verbose) {
                    $output->writeln('Scheduled update job '.$job->getId().' for package '.$id);
                }
                $em->detach($job);
            }

            $em->clear();
        }

        return 0;
    }
}
