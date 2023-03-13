<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

class MonoRepoPackageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('glob', TextareaType::class, [
                'required' => false,
                'attr' => ['placeholder' => '{src,proto}/*/*.json'],
                'label' => 'Glob expression'
            ])
            ->add('skipNotModifyTag', CheckboxType::class, [
                'required' => false,
            ]);
    }

    public function getParent()
    {
        return PackageType::class;
    }
}
