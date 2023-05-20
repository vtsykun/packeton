<?php

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\Zipball;

/**
 *
 * @method Zipball|null find($id, $lockMode = null, $lockVersion = null)
 * @method Zipball|null findOneBy(array $criteria, array $orderBy = null)
 * @method Zipball[]    findAll()
 * @method Zipball[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ZipballRepository extends EntityRepository
{
    public function ajaxSelect(): array
    {
        return $this->createQueryBuilder('z')
            ->resetDQLPart('select')
            ->select('z.id', 'z.originalFilename as filename', 'z.mimeType', 'z.fileSize as size')
            ->orderBy('z.id', 'DESC')
            ->getQuery()->getResult();
    }
}
