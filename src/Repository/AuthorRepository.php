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

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\Package;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AuthorRepository extends EntityRepository
{
    public function findOneByNameAndPackage($author, Package $package)
    {
        $qb = $this->createQueryBuilder('a');
        $qb->select('a')
            ->leftJoin('a.versions', 'v')
            ->leftJoin('v.package', 'p')
            ->where('p.id = :packageId')
            ->andWhere('a.name = :author')
            ->setMaxResults(1)
            ->setParameters(array('author' => $author, 'packageId' => $package->getId()));

        return $qb->getQuery()->getOneOrNullResult();
    }
}
