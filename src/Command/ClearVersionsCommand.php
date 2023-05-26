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
use Packeton\Entity\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('packagist:clear:versions', description: 'Clears all versions from the databases')]
class ClearVersionsCommand extends Command
{
    public function __construct(protected ManagerRegistry $registry) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force execution, by default it runs in dry-run mode'),
                new InputOption('filter', null, InputOption::VALUE_NONE, 'Filter (regex) against "<version name> <version number>"'),
            ])
            ->setDescription('Clears all versions from the databases');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $filter = $input->getOption('filter');

        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        $versionRepo = $this->registry->getRepository(Version::class);

        $packages = $em->getConnection()->fetchAllAssociative('SELECT id FROM package ORDER BY id ASC');
        $ids = [];
        foreach ($packages as $package) {
            $ids[] = $package['id'];
        }

        $packageNames = [];

        while ($ids) {
            $qb = $versionRepo->createQueryBuilder('v');
            $qb
                ->join('v.package', 'p')
                ->where($qb->expr()->in('p.id', array_splice($ids, 0, 50)));
            $versions = $qb->getQuery()->iterate();

            foreach ($versions as $version) {
                $version = $version[0];
                $name = $version->getName().' '.$version->getVersion();
                if (!$filter || preg_match('{'.$filter.'}i', $name)) {
                    $output->writeln('Clearing '.$name);
                    if ($force) {
                        $packageNames[] = $version->getName();
                        $versionRepo->remove($version);
                    }
                }
            }

            $em->flush();
            $em->clear();
            unset($versions);
        }

        if ($force) {
            // mark packages as recently crawled so that they get updated
            $packageRepo = $this->registry->getRepository(Package::class);
            foreach ($packageNames as $name) {
                $package = $packageRepo->findOneByName($name);
                $package->setCrawledAt(new \DateTime);
            }

            $em->flush();
        }

        return 0;
    }
}
