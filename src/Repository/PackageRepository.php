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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Version;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageRepository extends EntityRepository
{
    public function findOneByName(string $name):?Package
    {
        return $this->createQueryBuilder('p')
            ->where('p.name = :name')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function findProviders($name)
    {
        $query = $this->createQueryBuilder('p')
            ->select('p')
            ->leftJoin('p.versions', 'pv')
            ->leftJoin('pv.provide', 'pr')
            ->where('pv.development = true')
            ->andWhere('pr.packageName = :name')
            ->orderBy('p.name')
            ->getQuery()
            ->setParameters(array('name' => $name));

        return $query->getResult();
    }

    public function getPackageNames(array $allowed = null)
    {
        $query = $this->createQueryBuilder('p')
            ->resetDQLPart('select')
            ->select('p.name');

        if (is_array($allowed) && empty($allowed)) {
            return [];
        }

        if ($allowed) {
            $query->where('p.id IN (:ids)')->setParameter('ids', $allowed);
        }

        $names = $this->getPackageNamesForQuery($query->getQuery());

        return array_map('strtolower', $names);
    }

    /**
     * @param Package $package
     * @return Package[]
     */
    public function getChildPackages(Package $package): array
    {
        return $this->createQueryBuilder('p')
            ->where('IDENTITY(p.parentPackage) = :pid')
            ->setParameter('pid', $package->getId())
            ->getQuery()
            ->getResult();
    }

    public function getWebhookDataForUpdate(): array
    {
        $qb = $this->createQueryBuilder('p')
            ->resetDQLPart('select')
            ->select(['p.id', 'p.name', 'p.repository'])
            ->where('p.repository IS NOT NULL')
            ->andWhere('p.parentPackage IS NULL');

        return $qb->getQuery()->getArrayResult();
    }

    public function getProvidedNames()
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.packageName AS name
                FROM Packeton\Entity\ProvideLink p
                LEFT JOIN p.version v
                WHERE v.development = true
                GROUP BY p.packageName");

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    public function getPackageNamesByType($type)
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packeton\Entity\Package p WHERE p.type = :type")
            ->setParameters(['type' => $type]);

        return $this->getPackageNamesForQuery($query);
    }

    public function getPackageNamesByVendor($vendor)
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packeton\Entity\Package p WHERE p.name LIKE :vendor")
            ->setParameters(['vendor' => $vendor.'/%']);

        return $this->getPackageNamesForQuery($query);
    }

    public function getPackagesWithFields($filters, $fields)
    {
        $selector = '';
        foreach ($fields as $field) {
            $selector .= ', p.'.$field;
        }
        $where = '';

        $vendor = $filters['vendor'] ?? null;
        unset($filters['vendor']);

        foreach ($filters as $filter => $val) {
            $where .= ' p.'.$filter.' = :'.$filter;
        }
        if ($vendor) {
            $where .= ' p.name LIKE :vendor ';
            $filters['vendor'] = $vendor .'/%';
        }

        if ($where) {
            $where = 'WHERE '.$where;
        }
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name $selector  FROM Packeton\Entity\Package p $where")
            ->setParameters($filters);

        $result = [];
        foreach ($query->getScalarResult() as $row) {
            $name = $row['name'];
            unset($row['name']);
            $result[$name] = $row;
        }

        return $result;
    }

    private function getPackageNamesForQuery($query)
    {
        $names = [];
        foreach ($query->getScalarResult() as $row) {
            $names[] = $row['name'];
        }

        sort($names, SORT_STRING | SORT_FLAG_CASE);
        return $names;
    }

    public function getStalePackages($interval = null)
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchFirstColumn(
            "SELECT p.id FROM package p
            WHERE p.abandoned = false
            AND p.parent_id is NULL
            AND (p.repo_type NOT IN ('artifact', 'custom') OR p.repo_type IS NULL)
            AND (
                p.crawledAt IS NULL
                OR (p.autoUpdated = false AND p.crawledAt < :crawled)
                OR (p.crawledAt < :autocrawled)
            )
            ORDER BY p.id ASC",
            [
                // crawl packages without auto-update once a hour
                'crawled' => date('Y-m-d H:i:s', time() - ($interval ?: 14400)),
                // crawl auto-updated packages once a week just in case
                'autocrawled' => date('Y-m-d H:i:s', strtotime('-7day')),
            ]
        );
    }

    public function getStalePackagesForIndexing()
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative('SELECT p.id FROM package p WHERE p.indexedAt IS NULL OR p.indexedAt <= p.crawledAt ORDER BY p.id ASC');
    }

    public function getStalePackagesForDumping()
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative('SELECT p.id FROM package p WHERE p.dumpedAt IS NULL OR p.dumpedAt <= p.crawledAt AND p.crawledAt < NOW() ORDER BY p.id ASC');
    }

    public function getPartialPackageByNameWithVersions($name)
    {
        // first fetch a partial package including joined versions/maintainers, that way
        // the join is cheap and heavy data (description, readme) is not duplicated for each joined row
        //
        // fetching everything partial here to avoid fetching tons of data,
        // this helps for packages like https://packagist.org/packages/ccxt/ccxt
        // with huge amounts of versions
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('partial p.{id}', 'partial v.{id, version, normalizedVersion, development, releasedAt}', 'partial m.{id, username, email}')
            ->from(Package::class, 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('p.maintainers', 'm')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC')
            ->where('p.name = ?0')
            ->setParameters([$name]);

        $pkg = $qb->getQuery()->getSingleResult();

        if ($pkg) {
            // then refresh the package to complete its data and inject the previously fetched versions/maintainers to
            // get a complete package
            $versions = $pkg->getVersions();
            $maintainers = $pkg->getMaintainers();
            $this->getEntityManager()->refresh($pkg);

            $prop = new \ReflectionProperty($pkg, 'versions');
            $prop->setAccessible(true);
            $prop->setValue($pkg, $versions);

            $prop = new \ReflectionProperty($pkg, 'maintainers');
            $prop->setAccessible(true);
            $prop->setValue($pkg, $maintainers);
        }

        return $pkg;
    }

    public function getPackageByName($name)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'm')
            ->from('Packeton\Entity\Package', 'p')
            ->leftJoin('p.maintainers', 'm')
            ->where('p.name = ?0')
            ->setParameters(array($name));

        return $qb->getQuery()->getSingleResult();
    }

    public function getPackagesWithVersions(array $ids = null, $filters = [])
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'v')
            ->from('Packeton\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC');

        if (null !== $ids) {
            $qb->where($qb->expr()->in('p.id', ':ids'))
                ->setParameter('ids', $ids);
        }

        $this->addFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    public function getGitHubStars(array $ids)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p.gitHubStars', 'p.id')
            ->from('Packeton\Entity\Package', 'p')
            ->where($qb->expr()->in('p.id', ':ids'))
            ->setParameter('ids', $ids);

        return $qb->getQuery()->getResult();
    }

    public function getFilteredQueryBuilder(array $filters = [], $orderByName = false)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('Packeton\Entity\Package', 'p');

        if (isset($filters['tag'])) {
            $qb->leftJoin('p.versions', 'v');
            $qb->leftJoin('v.tags', 't');
        }

        $qb->orderBy('p.abandoned');
        if (true === $orderByName) {
            $qb->addOrderBy('p.name');
        } else {
            $qb->addOrderBy('p.id', 'DESC');
        }

        $this->addFilters($qb, $filters);

        return $qb;
    }

    public function isVendorTaken($vendor, User $user)
    {
        $query = $this->getEntityManager()
            ->createQuery(
                "SELECT p.name, m.id user_id
                FROM Packeton\Entity\Package p
                JOIN p.maintainers m
                WHERE p.name LIKE :vendor")
            ->setParameters(array('vendor' => $vendor.'/%'));

        $rows = $query->getArrayResult();
        if (!$rows) {
            return false;
        }

        foreach ($rows as $row) {
            if ($row['user_id'] === $user->getId()) {
                return false;
            }
        }

        return true;
    }

    public function getDependentCount($name)
    {
        $sql = 'SELECT COUNT(*) count FROM (
                SELECT pv.package_id FROM link_require r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = true) WHERE r.packageName = :name
                UNION
                SELECT pv.package_id FROM link_require_dev r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = true) WHERE r.packageName = :name
            ) x';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['name' => $name], [], new QueryCacheProfile(86400, sha1('dependents_count_'.$name), $this->getEntityManager()->getConfiguration()->getResultCacheImpl()));
        $result = $stmt->fetchAllAssociative();

        return (int) $result[0]['count'];
    }

    public function getDependents($name, $offset = 0, $limit = 15)
    {
        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM package p INNER JOIN (
                SELECT pv.package_id FROM link_require r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = true) WHERE r.packageName = :name
                UNION
                SELECT pv.package_id FROM link_require_dev r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = true) WHERE r.packageName = :name
            ) x ON x.package_id = p.id ORDER BY p.name ASC LIMIT '.((int)$limit).' OFFSET '.((int)$offset);

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                ['name' => $name],
                [],
                new QueryCacheProfile(86400, sha1('dependents_'.$name.$offset.'_'.$limit), $this->getEntityManager()->getConfiguration()->getResultCacheImpl())
            );

        return $stmt->fetchAllAssociative();
    }

    public function getSuggestCount($name)
    {
        $sql = 'SELECT COUNT(DISTINCT pv.package_id) count
            FROM link_suggest s
            INNER JOIN package_version pv ON (pv.id = s.version_id AND pv.development = true)
            WHERE s.packageName = :name';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery($sql, ['name' => $name], [], new QueryCacheProfile(86400, sha1('suggesters_count_'.$name), $this->getEntityManager()->getConfiguration()->getResultCacheImpl()));
        $result = $stmt->fetchAllAssociative();

        return (int) $result[0]['count'];
    }

    public function getSuggests($name, $offset = 0, $limit = 15)
    {
        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM link_suggest s
            INNER JOIN package_version pv ON (pv.id = s.version_id AND pv.development = true)
            INNER JOIN package p ON (p.id = pv.package_id)
            WHERE s.packageName = :name
            GROUP BY pv.package_id
            ORDER BY p.name ASC LIMIT '.((int)$limit).' OFFSET '.((int)$offset);

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                ['name' => $name],
                [],
                new QueryCacheProfile(86400, sha1('suggesters_'.$name.$offset.'_'.$limit), $this->getEntityManager()->getConfiguration()->getResultCacheImpl())
            );
        $result = $stmt->fetchAllAssociative();

        return $result;
    }

    private function addFilters(QueryBuilder $qb, array $filters)
    {
        foreach ($filters as $name => $value) {
            if (null === $value) {
                continue;
            }

            switch ($name) {
                case 'tag':
                    $qb->andWhere($qb->expr()->in('t.name', ':'.$name));
                    break;

                case 'maintainer':
                    $qb->leftJoin('p.maintainers', 'm');
                    $qb->andWhere($qb->expr()->in('m.id', ':'.$name));
                    break;

                case 'vendor':
                    $qb->andWhere('p.name LIKE :vendor');
                    break;

                default:
                    $qb->andWhere($qb->expr()->in('p.'.$name, ':'.$name));
                    break;
            }

            $qb->setParameter($name, $value);
        }
    }

    /**
     * Gets the most recent packages created
     *
     * @return QueryBuilder
     */
    public function getQueryBuilderForNewestPackages()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('Packeton\Entity\Package', 'p')
            ->where('p.abandoned = false')
            ->orderBy('p.id', 'DESC');

        return $qb;
    }

    public function getPackagesStatisticsByMonthAndYear()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select(
                [
                    'COUNT(p.id) as pcount',
                    'YEAR(p.createdAt) as year',
                    'MONTH(p.createdAt) as month'
                ]
            )
            ->from(Package::class, 'p')
            ->groupBy('year, month');

        return $qb->getQuery()->getResult();
    }

    public function searchPackage(string $search, int $limit = 10, int $page = 0, array $allowed = null)
    {
        $conn = $this->getEntityManager()->getConnection();
        if ($conn->getDatabasePlatform() instanceof PostgreSqlPlatform && $this->checkExtension('fuzzystrmatch')) {
            $params = ['search' => $search, 'limit' => $limit, 'ofs' => $page * $limit];
            $sql = 'SELECT levenshtein(name, :search)/(1.0 + length(name)) AS idx, id'
                . ' FROM package ';

            if (null !== $allowed) {
                $sql .= ' WHERE id IN (:ids) ';
                $params['ids'] = $allowed;
            }
            $sql .= 'ORDER BY idx LIMIT :limit OFFSET :ofs';

            return array_map(
                fn ($item) => $this->find($item['id']),
                $conn->executeQuery($sql, $params, ['ids' => Connection::PARAM_INT_ARRAY])->fetchAllAssociative()
            );
        }

        $qb = $this->createQueryBuilder('p');
        if (null !== $allowed) {
            $qb->andWhere('p.id IN (:ids)')
                ->setParameter('ids', $allowed);
        }

        $packages = $qb->andWhere('p.name LIKE :name')
            ->setParameter('name', '%' . $search . '%')
            ->setMaxResults($limit)
            ->setFirstResult($limit * $page)
            ->getQuery()->getResult();

        // Use levenshtein search.
        if ($page === 0 && count($packages) < $limit -1) {
            $excPackages = array_map(fn (Package $p) => $p->getName(), $packages);
            $packageNames = $this->getPackageNames($allowed);
            $alternatives = [];
            foreach ($packageNames as $name) {
                $score = levenshtein($name, $search)/(1+strlen($name));
                if (!in_array($name, $excPackages) && $score <= 0.8) {
                    $alternatives[$name] = $score;
                }
            }

            asort($alternatives);
            $count = min($limit - 1 - count($packages), count($alternatives));

            if ($count > 0) {
                $alternatives = array_slice(array_keys($alternatives), 0, $count);
                $alternatives = $this->createQueryBuilder('p')
                    ->where('LOWER(p.name) in (:packs)')
                    ->setParameter('packs', $alternatives)
                    ->getQuery()->getResult();

                $packages = array_merge($packages, $alternatives);
            }
        }

        return $packages;
    }

    /**
     * @param int|\DateTime $since
     * @param int|\DateTime|null $now
     * @param
     *
     * @return array
     */
    public function getMetadataChanges($since, $now = null, ?bool $stability = true)
    {
        if (is_numeric($since)) {
            $since = (new \DateTime())->setTimestamp($since);
        }

        if (is_numeric($now) || null === $now) {
            $now = $now ? (new \DateTime())->setTimestamp($now) : new \DateTime();
        }

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select(['v.name', 'MAX(v.releasedAt) as time1', 'MAX(v.createdAt) as time2'])
            ->from(Version::class, 'v')
            ->where('v.createdAt > :since OR v.releasedAt > :since')
            ->andWhere('v.createdAt <= :now AND v.releasedAt <= :now')
            ->groupBy('v.name')
            ->setParameter('now', $now)
            ->setParameter('since', $since);

        if (true === $stability) {
            $qb->andWhere("v.version NOT LIKE 'dev-%'");
        }
        if (false === $stability) {
            $qb->andWhere("v.version LIKE 'dev-%'");
        }

        $packages = $qb
            ->getQuery()->getArrayResult();

        $updates = [];
        foreach ($packages as $package) {
            $unix = max(strtotime($package['time1']), strtotime($package['time2']));
            $packageName = $stability === false ? $package['name'] . '~dev' : $package['name'];
            $updates[] = ['type' => 'update', 'package' => $packageName, 'time' => $unix];
        }

        return $updates;
    }

    private function checkExtension(string $extensionName)
    {
        $conn = $this->getEntityManager()->getConnection();
        try {
            $count = $conn
                ->executeQuery(
                    'SELECT COUNT(1) as v FROM pg_extension WHERE extname = :name',
                    [
                        'name' => $extensionName
                    ]
                )->fetchAssociative();
        } catch (\Exception) {
            return false;
        }

        return isset($count['v']) && $count['v'] === 1;
    }
}
