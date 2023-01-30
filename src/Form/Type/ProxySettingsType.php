<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

class ProxySettingsType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('strict_mirror', ChoiceType::class, [
                'expanded' => true,
                'label' => 'Dependencies Usage Policy',
                'choices' => [
                    'Strict (new packages must be manually approved)' => true,
                    'All (new packages are automatically added when requested by composer)' => false,
                ]
            ])
            ->add('enabled_sync', CheckboxType::class, [
                'label' => 'Enable automatically synchronization',
            ]);
    }
}
