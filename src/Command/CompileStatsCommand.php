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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class CompileStatsCommand extends Command
{
    protected static $defaultName = 'packagist:stats:compile';

    public function __construct(
        protected ManagerRegistry $registry,
        protected \Redis $redis,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-build of all stats'),
            ])
            ->setDescription('Updates the redis stats indices');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');


        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        $minMax = $em->getConnection()->fetchAssociative('SELECT MAX(id) maxId, MIN(id) minId FROM package');
        if (!isset($minMax['minId'])) {
            return 0;
        }

        $ids = range($minMax['minId'], $minMax['maxId']);
        $res = $em->getConnection()->fetchAssociative('SELECT MIN(createdAt) minDate FROM package');
        $date = new \DateTime($res['minDate']);
        $date->modify('00:00:00');
        $yesterday = new \DateTime('yesterday 00:00:00');

        if ($force) {
            if ($verbose) {
                $output->writeln('Clearing aggregated DB');
            }
            $clearDate = clone $date;
            $keys = [];
            while ($clearDate <= $yesterday) {
                $keys['downloads:'.$clearDate->format('Ymd')] = true;
                $keys['downloads:'.$clearDate->format('Ym')] = true;
                $clearDate->modify('+1day');
            }
            $this->redis->del(array_keys($keys));
        }

        while ($date <= $yesterday) {
            // skip months already computed
            if (null !== $this->getMonthly($date) && $date->format('m') !== $yesterday->format('m')) {
                $date->setDate($date->format('Y'), ((int)$date->format('m')) + 1, 1);
                continue;
            }

            // skip days already computed
            if (null !== $this->getDaily($date) && $date != $yesterday) {
                $date->modify('+1day');
                continue;
            }

            $sum = $this->sum($date->format('Ymd'), $ids);
            $this->redis->set('downloads:'.$date->format('Ymd'), $sum);

            if ($verbose) {
                $output->writeln('Wrote daily data for '.$date->format('Y-m-d').': '.$sum);
            }

            $nextDay = clone $date;
            $nextDay->modify('+1day');
            // update the monthly total if we just computed the last day of the month or the last known day
            if ($date->format('Ymd') === $yesterday->format('Ymd') || $date->format('Ym') !== $nextDay->format('Ym')) {
                $sum = $this->sum($date->format('Ym'), $ids);
                $this->redis->set('downloads:'.$date->format('Ym'), $sum);

                if ($verbose) {
                    $output->writeln('Wrote monthly data for '.$date->format('Y-m').': '.$sum);
                }
            }

            $date = $nextDay;
        }

        $packages = $em->getConnection()->fetchAllAssociative('SELECT id FROM package ORDER BY id ASC');
        $ids = [];
        foreach ($packages as $row) {
            $ids[] = $row['id'];
        }

        if ($verbose) {
            $output->writeln('Writing new trendiness data into redis');
        }

        while ($id = array_shift($ids)) {
            $trendiness = $this->sumLastNDays(7, $id, $yesterday);

            $this->redis->zadd('downloads:trending:new', $trendiness, $id);
            $this->redis->zadd('downloads:absolute:new', $this->redis->get('dl:'.$id) ?: 0, $id);
        }

        $this->redis->rename('downloads:trending:new', 'downloads:trending');
        $this->redis->rename('downloads:absolute:new', 'downloads:absolute');

        return 0;
    }

    // TODO could probably run faster with lua scripting
    protected function sumLastNDays($days, $id, \DateTime $yesterday)
    {
        $date = clone $yesterday;
        $keys = [];
        for ($i = 0; $i < $days; $i++) {
            $keys[] = 'dl:'.$id.':'.$date->format('Ymd');
            $date->modify('-1day');
        }

        return array_sum($this->redis->mget($keys));
    }

    protected function sum($date, array $ids)
    {
        $sum = 0;

        while ($ids) {
            $batch = array_splice($ids, 0, 500);
            $keys = [];
            foreach ($batch as $id) {
                $keys[] = 'dl:'.$id.':'.$date;
            }
            $sum += array_sum($res = $this->redis->mget($keys));
        }

        return $sum;
    }

    protected function getMonthly(\DateTime $date)
    {
        return $this->redis->get('downloads:'.$date->format('Ym'));
    }

    protected function getDaily(\DateTime $date)
    {
        return $this->redis->get('downloads:'.$date->format('Ymd'));
    }
}
