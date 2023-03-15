<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

class MonoRepoPackageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('glob', TextareaType::class, [
                'required' => false,
                'attr' => ['placeholder' => '{src,proto}/*/*.json', 'class' => 'package-repo-info'],
                'label' => 'Glob expression',
            ])
            ->add('excludedGlob', TextareaType::class, [
                'required' => false,
                'label' => 'List of excluded paths',
            ])
            ->add('skipNotModifyTag', CheckboxType::class, [
                'required' => false,
                'label' => 'Skip not modified packages tags/releases'
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return PackageType::class;
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'mono_repo_package';
    }
}
