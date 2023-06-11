<?php

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\Zipball;

class ZipballRepository extends EntityRepository
{
    public function ajaxSelect(bool $onlyUnused = false, array $withIds = []): array
    {
        $qb = $this->createQueryBuilder('z')
            ->resetDQLPart('select')
            ->select('z.id', 'z.originalFilename as filename', 'z.mimeType', 'z.fileSize as size')
            ->orderBy('z.id', 'DESC');

        if (true === $onlyUnused) {
            $qb->where('z.used IS NULL OR z.used = false');
            if ($withIds) {
                $qb->orWhere('z.id IN (:ids)')
                    ->setParameter('ids', $withIds);
            }
        }

        return $qb
            ->getQuery()->getResult();
    }

    public function freeZipballData($limit = 25): array
    {
        return $this->createQueryBuilder('z')
            ->resetDQLPart('select')
            ->select('z.id', 'z.originalFilename as filename', 'z.mimeType', 'z.fileSize as size')
            ->where('z.used IS NULL OR z.used = false')
            ->orderBy('z.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
