<?php

declare(strict_types=1);

namespace Packeton\Import;

use Packeton\Attribute\AsWorker;
use Packeton\Entity\Job;

#[AsWorker('mass:import')]
class MassImportWorker
{
    public function __invoke(Job $job): array
    {
        $payload = $job->getPayload();


    }
}
