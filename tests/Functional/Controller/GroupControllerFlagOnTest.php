<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\GroupAclPermission;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Tests\Functional\PacketonTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GroupControllerFlagOnTest extends WebTestCase
{
    use PacketonTestTrait;

    private KernelBrowser $client;
    private array $trackedEntities = [];

    private static string $origEnv = '';
    private static string $origServer = '';

    public static function setUpBeforeClass(): void
    {
        self::$origEnv = $_ENV['ALLOW_MAINTAINER_GROUPS'] ?? '';
        self::$origServer = $_SERVER['ALLOW_MAINTAINER_GROUPS'] ?? '';
        $_ENV['ALLOW_MAINTAINER_GROUPS'] = 'true';
        $_SERVER['ALLOW_MAINTAINER_GROUPS'] = 'true';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$origEnv !== '') {
            $_ENV['ALLOW_MAINTAINER_GROUPS'] = self::$origEnv;
        } else {
            unset($_ENV['ALLOW_MAINTAINER_GROUPS']);
        }
        if (self::$origServer !== '') {
            $_SERVER['ALLOW_MAINTAINER_GROUPS'] = self::$origServer;
        } else {
            unset($_SERVER['ALLOW_MAINTAINER_GROUPS']);
        }
    }

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if (!empty($this->trackedEntities)) {
            $em = $this->getRegistry()->getManager();
            foreach ($this->trackedEntities as $entity) {
                if ($em->contains($entity)) {
                    $em->remove($entity);
                } elseif (method_exists($entity, 'getId') && $entity->getId()) {
                    $found = $em->getRepository(get_class($entity))->find($entity->getId());
                    if ($found) {
                        $em->remove($found);
                    }
                }
            }
            $em->flush();
            $this->trackedEntities = [];
        }
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

        $this->trackedEntities[] = $group;

        return $group;
    }

    private function createPackage(string $vendorName): Package
    {
        $package = new Package();
        $package->setName($vendorName);
        $package->setRepository("https://example.com/$vendorName");

        $em = $this->getRegistry()->getManager();
        $em->persist($package);
        $em->flush();

        $this->trackedEntities[] = $package;

        return $package;
    }

    private function addPackageToGroup(Group $group, Package $package): GroupAclPermission
    {
        $permission = new GroupAclPermission();
        $permission->setPackage($package);
        $permission->setGroup($group);

        $group->addAclPermissions($permission);

        $em = $this->getRegistry()->getManager();
        $em->flush();

        return $permission;
    }

    private function getListItemText(): string
    {
        $crawler = $this->client->request('GET', '/groups');
        $texts = [];
        foreach ($crawler->filter('ul.packages li') as $node) {
            $texts[] = trim($node->textContent);
        }
        return implode("\n", $texts);
    }

    // ─── Access control (flag ON) ───

    public function testMaintainerCanAccessGroupIndexWhenFlagOn(): void
    {
        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/groups');
        static::assertResponseIsSuccessful();
    }

    public function testMaintainerCanAccessGroupCreateWhenFlagOn(): void
    {
        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/groups/create');
        static::assertResponseIsSuccessful();
    }

    public function testMaintainerCanAccessGroupUpdateWhenFlagOn(): void
    {
        $group = $this->createGroup('flag-on-update-' . uniqid(), $this->getUser('dev', $this->client));

        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        static::assertResponseIsSuccessful();
    }

    public function testMaintainerSeesOnlyOwnGroupsInIndex(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $ownGroup = $this->createGroup('own-index-' . uniqid(), $dev);
        $otherGroup = $this->createGroup('other-index-' . uniqid(), $this->getUser('admin', $this->client));

        $this->client->loginUser($dev);
        $content = $this->getListItemText();
        static::assertStringContainsString($ownGroup->getName(), $content);
        static::assertStringNotContainsString($otherGroup->getName(), $content);
    }

    public function testAdminSeesAllGroupsInIndex(): void
    {
        $admin = $this->getUser('admin', $this->client);
        $group1 = $this->createGroup('admin-idx-1-' . uniqid(), $admin);
        $group2 = $this->createGroup('admin-idx-2-' . uniqid(), $this->getUser('dev', $this->client));

        $this->client->loginUser($admin);
        $content = $this->getListItemText();
        static::assertStringContainsString($group1->getName(), $content);
        static::assertStringContainsString($group2->getName(), $content);
    }

    // ─── Group creation ───

    public function testMaintainerCanCreateGroupWhenFlagOn(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $this->client->loginUser($dev);

        $name = 'new-group-' . uniqid();
        $crawler = $this->client->request('GET', '/groups/create');
        $form = $crawler->filter('form[name="group"]')->form();
        $this->client->submit($form, [
            'group[name]' => $name,
        ]);

        static::assertResponseRedirects();
        $this->client->followRedirect();
        static::assertResponseIsSuccessful();

        $group = $this->getRegistry()->getRepository(Group::class)->findOneBy(['name' => $name]);
        static::assertNotNull($group);
    }

    // ─── Group ownership ───

    public function testMaintainerCanEditOwnGroupWhenFlagOn(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $group = $this->createGroup('own-edit-' . uniqid(), $dev);

        $this->client->loginUser($dev);
        $crawler = $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        $form = $crawler->filter('form[name="group"]')->form();
        $newName = 'renamed-' . uniqid();
        $this->client->submit($form, [
            'group[name]' => $newName,
        ]);

        static::assertResponseRedirects();
        $this->client->followRedirect();
        static::assertResponseIsSuccessful();
    }

    public function testMaintainerCannotEditOthersGroupWhenFlagOn(): void
    {
        $admin = $this->getUser('admin', $this->client);
        $dev = $this->getUser('dev', $this->client);
        $group = $this->createGroup('other-edit-' . uniqid(), $admin);

        $this->client->loginUser($dev);
        $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        static::assertResponseStatusCodeSame(403);
    }

    public function testMaintainerCannotDeleteGroupWhenFlagOn(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $group = $this->createGroup('del-group-' . uniqid(), $dev);

        $this->client->loginUser($dev);
        $this->client->request('DELETE', '/groups/' . $group->getId() . '/delete', [
            '_token' => 'dummy',
        ]);

        static::assertResponseStatusCodeSame(403);
    }

    // ─── Package management via group ───

    public function testMaintainerCanManagePackageThroughGroupWhenFlagOn(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $group = $this->createGroup('pkg-group-' . uniqid(), $dev);
        $package = $this->createPackage('vendor/pkg-manage-' . uniqid());
        $this->addPackageToGroup($group, $package);

        $this->client->loginUser($dev);
        $this->client->request('GET', '/packages/' . $package->getName() . '/edit');
        static::assertResponseIsSuccessful();
    }

    public function testMaintainerCannotManagePackageNotInAnyOwnedGroup(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $package = $this->createPackage('vendor/no-access-' . uniqid());

        $this->client->loginUser($dev);
        $this->client->request('GET', '/packages/' . $package->getName() . '/edit');
        static::assertResponseStatusCodeSame(403);
    }

    // ─── Backward compat ───

    public function testExistingGroupWithNoOwnersBackwardCompatFlagOn(): void
    {
        $group = new Group();
        $group->setName('no-owner-flag-on-' . uniqid());

        $em = $this->getRegistry()->getManager();
        $em->persist($group);
        $em->flush();

        $this->trackedEntities[] = $group;

        $this->client->loginUser($this->getUser('admin', $this->client));
        $this->client->request('GET', '/groups/' . $group->getId() . '/update');
        static::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotAccessGroupsEvenWhenFlagOn(): void
    {
        $this->client->loginUser($this->getUser('user1', $this->client));
        $this->client->request('GET', '/groups');
        static::assertResponseStatusCodeSame(403);
    }
}
