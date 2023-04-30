<?php

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\ApiToken;
use Packeton\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

class ApiTokenRepository extends EntityRepository
{

    /**
     * @param UserInterface $user
     * @return ApiToken[]
     */
    public function findAllTokens(UserInterface $user)
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.userIdentifier = :username')
            ->setParameter('username', $user->getUserIdentifier())
            ->orderBy('t.id', 'DESC');

        if ($user instanceof User) {
            $qb->orWhere('IDENTITY(t.owner) = :uid')
                ->setParameter('uid', $user->getId());
        }

        return $qb->getQuery()->getResult();
    }
}
