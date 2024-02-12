<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Packeton\Tests\Functional\PacketonTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BaseAclControllerTest extends WebTestCase
{
    use PacketonTestTrait;

    #[DataProvider('aclUserUrlProvider')]
    public function testACLAccess(string $user, string $url, int $code): void
    {
        $client = static::createClient();

        $user = $this->getUser($user);
        $client->loginUser($user);

        $client->request('GET', $url);
        static::assertResponseStatusCodeSame($code);
    }

    #[DataProvider('adminUrlProvider')]
    public function testAdminAccess(string $url): void
    {
        $client = static::createClient();

        $admin = $this->getUser('admin');

        $client->loginUser($admin);

        $client->request('GET', $url);
        static::assertResponseIsSuccessful();
    }

    public function testAnonymousAccess(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        static::assertResponseRedirects();
    }

    public static function aclUserUrlProvider(): array
    {
        return [
            ['dev', '/', 200],
            ['dev', '/', 200],
            ['dev', '/packages/okvpn/cron-bundle', 200],
            ['dev', '/packages/okvpn/satis-api', 200],
            ['dev', '/packages/okvpn/cron-bundle/stats', 200],
            ['dev', '/packages/submit', 200],
            ['dev', '/groups', 403],

            ['user1', '/', 200],
            ['user1', '/profile', 200],
            ['user1', '/packages/okvpn/cron-bundle', 200],
            ['user1', '/packages/okvpn/satis-api', 404],
            ['user1', '/about', 403],

            ['user2', '/packages/okvpn/satis-api', 200],
        ];
    }

    public static function adminUrlProvider(): array
    {
        return [
            ['/'],
            ['/packages/okvpn/cron-bundle'],
            ['/packages/okvpn/cron-bundle/stats'],
            ['/packages/submit'],
            ['/users/'],
            ['/users/dev'],
            ['/users/dev/update'],
            ['/groups'],
            ['/groups/1/update'],
            ['/users/sshkey'],
            ['/profile'],
            ['/statistics'],
            ['/explore'],
            ['/about'],
            ['/users/admin/packages'],
            ['/users/admin/favorites'],
            ['/apidoc'],
            ['/proxies'],
            ['/feeds/'],
            ['/feeds/releases.rss'],
        ];
    }
}
