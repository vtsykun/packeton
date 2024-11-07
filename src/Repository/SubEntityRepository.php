<?php

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Util\PacketonUtils;

class SubEntityRepository extends EntityRepository
{
    public function getSubRepositoryData(): array
    {
        $data = $this->createQueryBuilder('s')
            ->resetDQLPart('select')
            ->select(['s.id', 's.slug', 's.urls', 's.name', 's.publicAccess as public'])
            ->getQuery()
            ->getArrayResult();

        foreach ($data as $i => $item) {
            $urls = $item['urls'];
            $data[$i]['urls'] = $urls ? array_map('trim', explode("\n", $urls)) : [];
        }

        return PacketonUtils::buildChoices($data, 'id');
    }
}
