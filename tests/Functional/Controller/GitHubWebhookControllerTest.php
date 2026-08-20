<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\WebhookSecret;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GitHubWebhookControllerTest extends WebTestCase
{
    public function testSignedPingAuthentication(): void
    {
        $client = static::createClient();
        $registry = static::getContainer()->get(ManagerRegistry::class);
        $registry->getConnection()->executeStatement('DELETE FROM webhook_secret');

        $payload = '{"zen":"Keep it logically awesome."}';
        $this->requestWebhook($client, $payload, 'ping', 'sha256='.str_repeat('0', 64));
        static::assertResponseStatusCodeSame(503);

        $plainSecret = WebhookSecret::generateSecret();
        $secret = (new WebhookSecret())
            ->setName('GitHub organization')
            ->setSecret($plainSecret);
        $registry->getManager()->persist($secret);
        $registry->getManager()->flush();

        $this->requestWebhook($client, $payload, 'ping');
        static::assertResponseStatusCodeSame(401);

        $this->requestWebhook($client, $payload, 'ping', 'sha256=invalid');
        static::assertResponseStatusCodeSame(403);

        $this->requestWebhook($client, $payload, 'ping', 'sha256='.str_repeat('0', 64));
        static::assertResponseStatusCodeSame(403);

        $signature = 'sha256='.hash_hmac('sha256', $payload, $plainSecret);
        $this->requestWebhook($client, $payload, 'ping', $signature);
        static::assertResponseIsSuccessful();
        static::assertJsonStringEqualsJsonString(
            '{"status":"success","message":"Webhook configured successfully"}',
            (string) $client->getResponse()->getContent(),
        );

        $registry = static::getContainer()->get(ManagerRegistry::class);
        $registry->getManager()->clear();
        $secret = $registry->getRepository(WebhookSecret::class)->findOneBy(['name' => 'GitHub organization']);
        static::assertInstanceOf(WebhookSecret::class, $secret);
        static::assertNotNull($secret->getLastUsedAt());

        $package = $registry->getRepository(Package::class)->findOneBy(['name' => 'okvpn/cron-bundle']);
        static::assertInstanceOf(Package::class, $package);
        $pushPayload = json_encode(['repository' => ['url' => $package->getRepository()]], JSON_THROW_ON_ERROR);
        $pushSignature = 'sha256='.hash_hmac('sha256', $pushPayload, $plainSecret);

        $this->requestWebhook($client, $pushPayload, 'push', $pushSignature);
        static::assertResponseStatusCodeSame(202);
        $response = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        static::assertSame('success', $response['status']);
        static::assertNotEmpty($response['jobs']);
    }

    private function requestWebhook(
        KernelBrowser $client,
        string $payload,
        string $event,
        ?string $signature = null,
    ): void {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
        ];
        if (null !== $signature) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        $client->request('POST', '/api/hooks/github', [], [], $server, $payload);
    }
}
