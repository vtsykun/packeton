<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Loader\ScheduleLoaderInterface;

class MirrorCronLoader implements ScheduleLoaderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getSchedules(array $options = []): iterable
    {
        return [];
    }
}
