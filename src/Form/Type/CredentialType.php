<?php

namespace Packeton\Form\Type;

use Packeton\Entity\SshCredentials;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CredentialType extends AbstractType
{
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
                        $label = $label . "(SSH_KEY {$credentials->getFingerprint()})";
                    } elseif ($credentials->getComposerConfig()) {
                        $label = $label . "(Composer Auth)";
                    }

                    return $label;
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
}
