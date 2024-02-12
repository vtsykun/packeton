<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait PacketonTestTrait
{
    private function getUser($username): User
    {
        return static::getContainer()->get(ManagerRegistry::class)
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);
    }

    private function basicLogin(string $username, KernelBrowser $client): void
    {
        $client->setServerParameter('PHP_AUTH_USER', $username);
        $client->setServerParameter('PHP_AUTH_PW', 'token123');
    }

    private function getJsonResponse(KernelBrowser $client, $statusCode = 200): array
    {
        static::assertResponseStatusCodeSame($statusCode);
        $response = $client->getResponse();

        $content = json_decode($response->getContent(), true);
        static::assertNotNull($content);
        return $content;
    }
}
