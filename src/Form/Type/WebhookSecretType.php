<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Packeton\Entity\WebhookSecret;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class WebhookSecretType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Name',
            'help' => 'Use a descriptive name for the GitHub organization or webhook.',
            'constraints' => [
                new NotBlank(),
                new Length(max: 255),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WebhookSecret::class,
        ]);
    }
}
