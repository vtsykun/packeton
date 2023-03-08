<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UnstructuredType extends TextType
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'multiple' => true,
        ]);
    }
}
