<?php

declare(strict_types=1);

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\WebhookSecret;

/**
 * @extends EntityRepository<WebhookSecret>
 */
class WebhookSecretRepository extends EntityRepository
{
    /**
     * @return WebhookSecret[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('secret')
            ->orderBy('secret.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, string>
     */
    public function findSecretValues(): array
    {
        $secrets = [];
        foreach ($this->findAll() as $secret) {
            if (null !== $secret->getId() && null !== $secret->getSecret()) {
                $secrets[$secret->getId()] = $secret->getSecret();
            }
        }

        return $secrets;
    }
}
