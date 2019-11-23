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
                'label' => 'SSH Credentials (optional, uses for Git to set GIT_SSH_COMMAND)',
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
