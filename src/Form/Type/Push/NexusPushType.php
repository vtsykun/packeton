<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Push;

use Packeton\Form\Model\NexusPushRequestDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NexusPushType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('src-type', TextType::class, ['property_path' => 'srcType'])
            ->add('src-url', TextType::class, ['property_path' => 'srcUrl'])
            ->add('src-ref', TextType::class, ['property_path' => 'srcRef'])
            ->add('package', FileType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            'data_class' => NexusPushRequestDto::class,
        ]);
    }
}
