<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\SubRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class SubRepositoryType extends AbstractType
{
    public function __construct(protected ManagerRegistry $registry)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $names = $this->registry->getRepository(Package::class)->getPackageNames();
        $names = array_combine($names, $names);

        $builder
            ->add('name', TextType::class)
            ->add('slug', TextType::class, [
                'constraints' => [new NotBlank(), new Regex('/^[a-zA-Z0-9\-_]+$/')],
            ])
            ->add('urls', TextareaType::class, [
                'required' => false,
            ])
            ->add('packages', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => $names
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubRepository::class,
        ]);
    }
}
