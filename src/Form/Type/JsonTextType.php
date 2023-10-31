<?php

namespace Packeton\Form\Type;

use Packeton\Form\DataTransformer\JsonDataTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class JsonTextType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->resetViewTransformers()
            ->resetModelTransformers()
            ->addModelTransformer(new JsonDataTransformer());
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'invalid_message' => 'This value is not a valid JSON'
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return TextareaType::class;
    }
}
