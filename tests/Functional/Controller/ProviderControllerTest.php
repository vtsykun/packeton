<?php

namespace Packeton\Tests\Functional\Controller;

use Packeton\Tests\Functional\PacketonTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProviderControllerTest extends WebTestCase
{
    use PacketonTestTrait;

    public function testRootPackages(): void
    {
        $client = static::createClient();

        $client->request('GET', '/packages.json');
        static::assertResponseStatusCodeSame(401);

        $this->basicLogin('admin', $client);
        $client->request('GET', '/packages.json');
        static::assertResponseStatusCodeSame(200);;
    }

    #[DataProvider('aclUserProvider')]
    public function testAvailablePackages(string $user, int $count): void
    {
        $client = static::createClient();

        $this->basicLogin($user, $client);
        $client->request('GET', '/packages.json');

        $content = $this->getJsonResponse($client);
        static::assertCount($count, $content['available-packages']);
    }

    public function testV2Packages(): void
    {
        $client = static::createClient();

        $this->basicLogin('admin', $client);

        $client->request('GET', '/p2/okvpn/cron-bundle.json');
        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['packages']['okvpn/cron-bundle']);

        $client->request('GET', '/p2/okvpn/cron-bundle~dev.json');
        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['packages']['okvpn/cron-bundle']);

        $client->request('GET', '/p2/okvpn/cron-bundle222.json');
        static::assertResponseStatusCodeSame(404);
    }

    public function testV2CustomerPackages(): void
    {
        $client = static::createClient();

        $this->basicLogin('user1', $client);

        $client->request('GET', '/p2/okvpn/cron-bundle.json');
        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['packages']['okvpn/cron-bundle']);

        $client->request('GET', '/p2/okvpn/cron-bundle~dev.json');
        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['packages']['okvpn/cron-bundle']);

        $client->request('GET', '/p2/okvpn/satis-api.json');
        static::assertResponseStatusCodeSame(404);
    }

    public function testProviderIncludesV1(): void
    {
        $client = static::createClient();

        $this->basicLogin('admin', $client);

        $server = ['HTTP_USER_AGENT' => 'Composer/1.10.2 (Windows NT; 10.0; PHP 8.1.0)'];
        $client->request('GET', '/packages.json', server: $server);
        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['provider-includes']);

        $hash = reset($content['provider-includes'])['sha256'];
        $client->request('GET', "/p/providers$$hash.json", server: $server);
        static::assertEquals($hash, hash('sha256', $client->getResponse()->getContent()));

        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['providers']);

        $hash = $content['providers']['okvpn/cron-bundle']['sha256'];
        $client->request('GET', "/p/okvpn/cron-bundle$$hash.json", server: $server);
        static::assertEquals($hash, hash('sha256', $client->getResponse()->getContent()));

        $content = $this->getJsonResponse($client);
        static::assertNotEmpty($content['packages']);
        static::assertNotEmpty($content['packages']['okvpn/cron-bundle']);
        $versions = array_column($content['packages']['okvpn/cron-bundle'], 'version_normalized');
        static::assertTrue(in_array('9999999-dev', $versions));
    }

    public static function aclUserProvider(): array
    {
        return [
            ['admin', 3],
            ['dev', 3],
            ['user1', 1],
            ['user2', 3],
        ];
    }
}
