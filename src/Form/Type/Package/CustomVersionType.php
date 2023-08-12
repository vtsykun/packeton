<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Form\Type\JsonTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class CustomVersionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('version', TextType::class, [
                'constraints' => [new NotBlank()]
            ])
            ->add('definition', JsonTextType::class, [
                'required' => false,
                'attr' => ['rows' => 10, 'placeholder' => 'JSON ']
            ]);
    }
}
