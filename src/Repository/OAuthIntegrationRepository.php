<?php

declare(strict_types=1);

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\OAuthIntegration;

class OAuthIntegrationRepository extends EntityRepository
{
    public function findForExpressionUsage(string $alias): ?OAuthIntegration
    {
        /** @var OAuthIntegration[] $list */
        $list = $this->createQueryBuilder('e')
            ->where('e.alias = :alias')
            ->setParameter('alias', $alias)
            ->orderBy('e.id')
            ->getQuery()->getResult();

        foreach ($list as $item) {
            if ($item->isUseForExpressionApi()) {
                return $item;
            }
        }

        return reset($list) ?: null;
    }
}
