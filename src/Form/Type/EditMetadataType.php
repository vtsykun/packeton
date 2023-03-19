<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EditMetadataType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $example = ['require' => ['php' => '>=7.2']];

        $versions = array_combine($options['versions'], $options['versions']);
        $builder->add('version', ChoiceType::class, [
                'choices' => $versions
            ])
            ->add('strategy', ChoiceType::class, [
                'choices' => [
                    'Merge Recursive' => 'merge_recursive',
                    'Merge (only keys)' => 'merge',
                    'Replace All' => 'replace',
                ]
            ])
            ->add('metadata', TextareaType::class, [
                'attr' => ['rows' => 15, 'placeholder' => json_encode($example, 448)]
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefault('versions', []);
    }
}
