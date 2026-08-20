<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\WebhookSecret;
use Packeton\Tests\Functional\PacketonTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WebhookSecretControllerTest extends WebTestCase
{
    use PacketonTestTrait;

    public function testOnlyAdminsCanManageWebhookSecrets(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getUser('user1'));

        $client->request('GET', '/webhook-secrets');

        static::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateEncryptedWebhookSecret(): void
    {
        $client = static::createClient();
        $registry = static::getContainer()->get(ManagerRegistry::class);
        $registry->getConnection()->executeStatement('DELETE FROM webhook_secret');

        $client->loginUser($this->getUser('admin'));
        $crawler = $client->request('GET', '/webhook-secrets/create');
        $form = $crawler->selectButton('Create secret')->form([
            'webhook_secret[name]' => 'GitHub organization',
        ]);
        $crawler = $client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $plainSecret = trim($crawler->filter('#generated-webhook-secret')->text());
        static::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plainSecret);

        $storedSecret = $registry->getConnection()->fetchOne('SELECT secret FROM webhook_secret');
        static::assertIsString($storedSecret);
        static::assertNotSame($plainSecret, $storedSecret);

        $registry->getManager()->clear();
        $secret = $registry->getRepository(WebhookSecret::class)->findOneBy(['name' => 'GitHub organization']);
        static::assertInstanceOf(WebhookSecret::class, $secret);
        static::assertSame($plainSecret, $secret->getSecret());
    }
}
