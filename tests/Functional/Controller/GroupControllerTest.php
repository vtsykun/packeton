<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\User;
use Packeton\Tests\Functional\PacketonTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GroupControllerTest extends WebTestCase
{
    use PacketonTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    private function getRegistry(): ManagerRegistry
    {
        return $this->client->getContainer()->get(ManagerRegistry::class);
    }

    private function createGroup(string $name, User $owner): Group
    {
        $group = new Group();
        $group->setName($name);
        $group->addOwner($owner);

        $em = $this->getRegistry()->getManager();
        $em->persist($group);
        $em->flush();

        return $group;
    }

    // ─── Flag OFF (default) tests ───

    public function testMaintainerCannotAccessGroupIndexWhenFlagOff(): void
    {
        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/groups');
        static::assertResponseStatusCodeSame(403);
    }

    public function testMaintainerCannotAccessGroupCreateWhenFlagOff(): void
    {
        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/groups/create');
        static::assertResponseStatusCodeSame(403);
    }

    public function testMaintainerCannotAccessGroupUpdateWhenFlagOff(): void
    {
        $group = $this->createGroup('flag-off-group-' . uniqid(), $this->getUser('dev', $this->client));

        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        static::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessGroupsRegardlessOfFlag(): void
    {
        $this->client->loginUser($this->getUser('admin', $this->client));
        $this->client->request('GET', '/groups');
        static::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotAccessGroups(): void
    {
        $this->client->loginUser($this->getUser('user1', $this->client));
        $this->client->request('GET', '/groups');
        static::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateGroupRegardlessOfFlag(): void
    {
        $this->client->loginUser($this->getUser('admin', $this->client));
        $this->client->request('GET', '/groups/create');
        static::assertResponseIsSuccessful();
    }

    public function testAdminCanEditAnyGroupRegardlessOfFlag(): void
    {
        $group = $this->createGroup('admin-edit-' . uniqid(), $this->getUser('dev', $this->client));

        $this->client->loginUser($this->getUser('admin', $this->client));
        $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        static::assertResponseIsSuccessful();
    }

    public function testExistingGroupWithNoOwnersBackwardCompat(): void
    {
        $group = new Group();
        $group->setName('no-owner-' . uniqid());

        $em = $this->getRegistry()->getManager();
        $em->persist($group);
        $em->flush();

        $this->client->loginUser($this->getUser('admin', $this->client));
        $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        static::assertResponseIsSuccessful();
    }
}
