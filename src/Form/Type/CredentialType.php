<?php

namespace Packeton\Form\Type;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\SshCredentials;
use Packeton\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class CredentialType extends AbstractType
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'class' => SshCredentials::class,
                'choice_label' => function (SshCredentials $credentials) {
                    $label = $credentials->getName();
                    if ($credentials->getFingerprint()) {
                        $label = $label . " ({$credentials->getFingerprint()})";
                    } elseif ($credentials->getComposerConfig()) {
                        $label = $label . " (Composer Auth)";
                    }

                    return $label;
                },
                'query_builder' => function (EntityRepository $er) {
                    $user = $this->getCurrentUser();
                    $qb = $er->createQueryBuilder('c');
                    if ($user && !$user->hasRole('ROLE_ADMIN')) {
                        $qb->andWhere('c.owner = :user')
                            ->setParameter('user', $user);
                    }
                    return $qb->orderBy('c.id', 'DESC');
                },
                'label' => 'Overwrite Composer/SSH Credentials',
                'tooltip' => 'Optional, SSH overwrite support only for Git 2.3+, to use other IdentityFile from env. GIT_SSH_COMMAND. By default will be used system ssh key',
                'required' => false,
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return EntityType::class;
    }

    private function getCurrentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        return $token?->getUser() instanceof User ? $token->getUser() : null;
    }
}
