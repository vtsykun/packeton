<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\SshCredentials;
use Packeton\Entity\User;
use Packeton\Tests\Functional\PacketonTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CredentialControllerTest extends WebTestCase
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

    private function createCredential(string $name, User $owner): SshCredentials
    {
        $credential = new SshCredentials();
        $credential->setName($name);
        $credential->setOwner($owner);

        $em = $this->getRegistry()->getManager();
        $em->persist($credential);
        $em->flush();

        return $credential;
    }

    private function getListItemText(): string
    {
        $crawler = $this->client->request('GET', '/users/sshkey');
        $texts = [];
        foreach ($crawler->filter('.panel-body') as $node) {
            $texts[] = trim($node->textContent);
        }
        return implode("\n", $texts);
    }

    // ─── Access control ───

    public function testMaintainerCanAccessCredentialPage(): void
    {
        $this->client->loginUser($this->getUser('dev', $this->client));
        $this->client->request('GET', '/users/sshkey');
        static::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotAccessCredentialPage(): void
    {
        $this->client->loginUser($this->getUser('user1', $this->client));
        $this->client->request('GET', '/users/sshkey');
        static::assertResponseStatusCodeSame(403);
    }

    // ─── Credential creation ───

    public function testMaintainerCanCreateCredential(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $this->client->loginUser($dev);

        $name = 'cred-create-' . uniqid();
        $crawler = $this->client->request('GET', '/users/sshkey');
        $form = $crawler->filter('form[name="ssh_key_credential"]')->form();
        $this->client->submit($form, [
            'ssh_key_credential[name]' => $name,
            'ssh_key_credential[composerConfig]' => '{"http-basic": {"example.org": {"username": "u", "password": "p"}}}',
        ]);

        static::assertResponseRedirects();
        $this->client->followRedirect();
        static::assertResponseIsSuccessful();

        $credential = $this->getRegistry()->getRepository(SshCredentials::class)->findOneBy(['name' => $name]);
        static::assertNotNull($credential);
        static::assertEquals($dev->getId(), $credential->getOwner()?->getId());
    }

    // ─── Credential ownership ───

    public function testMaintainerCanEditOwnCredential(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $credential = $this->createCredential('own-cred-' . uniqid(), $dev);

        $this->client->loginUser($dev);
        $crawler = $this->client->request('GET', '/users/sshkey/' . $credential->getId());
        $form = $crawler->filter('form[name="ssh_key_credential"]')->form();
        $newName = 'renamed-cred-' . uniqid();
        $this->client->submit($form, [
            'ssh_key_credential[name]' => $newName,
            'ssh_key_credential[composerConfig]' => '{"http-basic": {"example.org": {"username": "u", "password": "p"}}}',
        ]);

        static::assertResponseRedirects();
        $this->client->followRedirect();
        static::assertResponseIsSuccessful();
    }

    public function testMaintainerCannotEditOthersCredential(): void
    {
        $admin = $this->getUser('admin', $this->client);
        $dev = $this->getUser('dev', $this->client);
        $credential = $this->createCredential('other-cred-' . uniqid(), $admin);

        $this->client->loginUser($dev);
        $this->client->request('GET', '/users/sshkey/' . $credential->getId());
        static::assertResponseStatusCodeSame(403);
    }

    public function testMaintainerCanDeleteOwnCredential(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $credential = $this->createCredential('del-cred-' . uniqid(), $dev);

        $this->client->loginUser($dev);
        $crawler = $this->client->request('GET', '/users/sshkey/' . $credential->getId());
        $tokenInput = $crawler->filter('input[name="_token"]')->first();
        static::assertGreaterThan(0, $tokenInput->count());
        $token = $tokenInput->attr('value');

        $this->client->request('DELETE', '/users/sshkey/' . $credential->getId() . '/delete', [
            '_token' => $token,
        ]);

        static::assertResponseRedirects();
    }

    public function testMaintainerCannotDeleteOthersCredential(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $admin = $this->getUser('admin', $this->client);

        $ownCred = $this->createCredential('own-del-' . uniqid(), $dev);
        $otherCred = $this->createCredential('other-del-' . uniqid(), $admin);

        $this->client->loginUser($dev);
        $crawler = $this->client->request('GET', '/users/sshkey/' . $ownCred->getId());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('DELETE', '/users/sshkey/' . $otherCred->getId() . '/delete', [
            '_token' => $token,
        ]);

        static::assertResponseStatusCodeSame(403);
    }

    // ─── Credential list visibility ───

    public function testMaintainerSeesOnlyOwnCredentialsInList(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $ownCred = $this->createCredential('list-own-' . uniqid(), $dev);
        $otherCred = $this->createCredential('list-other-' . uniqid(), $this->getUser('admin', $this->client));

        $this->client->loginUser($dev);
        $content = $this->getListItemText();
        static::assertStringContainsString($ownCred->getName(), $content);
        static::assertStringNotContainsString($otherCred->getName(), $content);
    }

    public function testAdminSeesAllCredentialsInList(): void
    {
        $admin = $this->getUser('admin', $this->client);
        $cred1 = $this->createCredential('admin-list-1-' . uniqid(), $admin);
        $cred2 = $this->createCredential('admin-list-2-' . uniqid(), $this->getUser('dev', $this->client));

        $this->client->loginUser($admin);
        $content = $this->getListItemText();
        static::assertStringContainsString($cred1->getName(), $content);
        static::assertStringContainsString($cred2->getName(), $content);
    }

    public function testAdminCanEditAnyCredential(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $admin = $this->getUser('admin', $this->client);
        $credential = $this->createCredential('admin-edit-' . uniqid(), $dev);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/users/sshkey/' . $credential->getId());
        static::assertResponseIsSuccessful();
    }

    public function testAdminCanDeleteAnyCredential(): void
    {
        $dev = $this->getUser('dev', $this->client);
        $admin = $this->getUser('admin', $this->client);
        $credential = $this->createCredential('admin-del-' . uniqid(), $dev);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/users/sshkey/' . $credential->getId());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('DELETE', '/users/sshkey/' . $credential->getId() . '/delete', [
            '_token' => $token,
        ]);

        static::assertResponseRedirects();
    }
}
