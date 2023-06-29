<?php

declare(strict_types=1);

namespace Packeton\Service;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\SubRepository;
use Packeton\Entity\Version;
use Packeton\Model\PacketonUserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Cache\CacheInterface;

class SubRepositoryHelper
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected CacheInterface $cache,
        protected RequestStack $requestStack,
    ) {
    }

    public function getByHost(string $hostName): ?int
    {
        foreach ($this->getData() as $item) {
            if (in_array($hostName, $item['urls'] ?? [])) {
                return $item['id'];
            }
        }

        return null;
    }

    public function getBySlug(string $slug): ?int
    {
        foreach ($this->getData() as $item) {
            if ($slug === $item['slug']) {
                return $item['id'];
            }
        }

        return null;
    }

    public function allowedPackageNames(): ?array
    {
        $entity = $this->getCurrentSubrepository();
        $packages = $entity?->getPackages();

        return $entity === null ? null : (empty($packages) ? [] : $packages);
    }

    public function allowedPackageIds(array $moreAllowed = null): ?array
    {
        $entity = $this->getCurrentSubrepository();
        if (null === $entity) {
            return $moreAllowed;
        }

        if (!$packages = $entity->getPackages()) {
            return [];
        }

        $ids = $entity->getCachedIds();
        $ids ??= $this->registry->getRepository(Package::class)
            ->createQueryBuilder('p')
            ->resetDQLPart('select')
            ->select(['p.id'])
            ->where('p.name IN (:names)')
            ->setParameter('names', $packages)
            ->getQuery()
            ->getSingleColumnResult();
        $entity->setCachedIds($ids);

        if (null !== $moreAllowed) {
            $ids = array_intersect($ids, $moreAllowed);
        }
        return $ids;
    }

    public function isAutoHost(): bool
    {
        if (!$req = $this->requestStack->getMainRequest()) {
            return false;
        }

        return $req->attributes->get('_sub_repo_type') === SubRepository::AUTO_HOST;
    }

    public static function applyCondition(QueryBuilder $qb, ?array $allowed): QueryBuilder
    {
        if ($allowed === null) {
            return $qb;
        }

        $alias = $qb->getRootAliases()[0];
        $rootEntity = $qb->getRootEntities()[0] ?? null;

        if ($rootEntity === Version::class) {
            $qb->andWhere("IDENTITY($alias.package) IN (:pids10)")->setParameter('pids10', $allowed ?: [-1]);
        } else {
            $qb->andWhere("$alias.id IN (:pids10)")->setParameter('pids10', $allowed ?: [-1]);
        }

        return $qb;
    }

    public function applySubRepository(QueryBuilder $qb): QueryBuilder
    {
        $allowed = $this->allowedPackageIds();
        return self::applyCondition($qb, $allowed);
    }

    public function getCurrentSubrepository(): ?SubRepository
    {
        if (!$req = $this->requestStack->getMainRequest()) {
            return null;
        }

        if (!$entity = $req->attributes->get('_sub_repo_entity')) {
            $subRepo = $req->attributes->get('_sub_repo');
            $entity = $subRepo > 0 ? $this->registry->getRepository(SubRepository::class)->find($subRepo) : null;
        }
        return $entity;
    }

    public function getSubrepositoryId(): ?int
    {
        if (!$req = $this->requestStack->getMainRequest()) {
            return null;
        }
        return $req->attributes->get('_sub_repo');
    }

    public function getTwigData(UserInterface $user = null): array
    {
        $data = $this->getData();
        if (empty($data)) {
            return [];
        }

        $currentSubRepo = 'root';
        if ($req = $this->requestStack->getMainRequest()) {
            $subRepo = $req->attributes->get('_sub_repo');
            $currentSubRepo = $subRepo ? ($data[$subRepo]['name'] ?? 'root') : $currentSubRepo;
        }

        if ($user instanceof PacketonUserInterface) {
            $allowed = $user->getSubRepos();
            $data = array_filter($data, fn ($item) => in_array($item['id'], $allowed, true));
        }

        return [
            'repos' => array_values($data),
            'current' => $currentSubRepo,
        ];
    }

    protected function getData(): array
    {
        return $this->cache->get('sub_repos_list', function () {
            return $this->registry->getRepository(SubRepository::class)->getSubRepositoryData();
        });
    }
}
