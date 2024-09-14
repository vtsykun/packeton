<?php

declare(strict_types=1);

namespace Packeton\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Version;
use Packeton\Service\DistManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('packagist:download:versions', description: 'Download all versions from the remote packagist')]
class DownloadCommand extends Command
{
    public function __construct(
        protected DistManager            $distManager,
        protected ManagerRegistry        $registry
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Downloads all versions from the remote packagist');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        $versionRepo = $this->registry->getRepository(Version::class);

        /** @var Version[] $versions */
        $versions = $versionRepo->findAll();

        foreach ($versions as $version) {
            $output->writeln("Try to download package: " . $version->getName() . ' with version: ' . $version->getVersion());
            try  {
                $this->distManager->getDist($version->getReference(), $version->getPackage());
            } catch (\Exception $e) {
                continue;
            }
            $output->writeln("Successfully downloaded package: " . $version->getName() . ' with version: ' . $version->getVersion());
        }

        return 0;
    }
}
