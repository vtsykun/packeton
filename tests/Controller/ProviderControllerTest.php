<?php

namespace Packeton\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProviderControllerTest extends WebTestCase
{
    public function testPackagist(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/about');
        static::assertResponseStatusCodeSame(302);
    }
}
