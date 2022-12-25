<?php

namespace Packagist\WebBundle\Form\Type;

use Packagist\WebBundle\Entity\SshCredentials;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CredentialType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setDefaults([
                'class' => SshCredentials::class,
                'choice_label' => function (SshCredentials $credentials) {
                    return $credentials->getName() . ($credentials->getFingerprint() ?
                        (' (' . $credentials->getFingerprint() . ')') : '');
                },
                'label' => 'SSH Credentials',
                'tooltip' => 'Optional, support only for Git 2.3+, to use other IdentityFile from env. GIT_SSH_COMMAND. By default will be used system ssh key',
                'required' => false,
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return EntityType::class;
    }
}
