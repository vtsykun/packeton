<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Entity\OAuthIntegration;
use Packeton\Form\Type\CredentialType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class ImportPackagesType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Packagist.com/Satis/Composer repo' => 'composer',
                    'VCS repos' => 'vcs',
                    'Integration GitHub/GitLab/Gitea/Bitbucket' => 'integration',
                ],
                'attr' => ['class' => 'package-repo-type']
            ])
            ->add('clone', ChoiceType::class, [
                'label' => 'Clone preference',
                'choices' => [
                    'Use SSH URL' => 'ssh',
                    'Use HTTP URL' => 'http',
                    'Use OAuth2 API (only for integration)' => 'api'
                ]
            ])
            ->add('filter', TextareaType::class, [
                'label' => 'Glob filter',
                'tooltip' => 'Applied to repo name',
                'required' => false,
                'attr' => ['placeholder' => "thephpleague/flysystem\nsymfony/*\norg1/subgroup1/*", 'rows' => 6]
            ])
            ->add('limit', IntegerType::class, [
                'required' => false,
                'label' => 'Max import size',
            ])
            ->add('credentials', CredentialType::class)
            ->add('integration', EntityType::class, [
                'class' => OAuthIntegration::class,
                'required' => false,
                'attr' => ['class' => 'type-hide integration']
            ])
            ->add('username', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'type-hide composer']
            ])
            ->add('password', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'type-hide composer']
            ])
            ->add('list', TextareaType::class, [
                'label' => 'List of VCS repos',
                'required' => false,
                'attr' => ['class' => 'type-hide vcs', 'placeholder' => "git@github.com:thephpleague/flysystem.git\ngit@github.com:vtsykun/packeton.git", 'rows' => 8],
            ]);
    }
}
