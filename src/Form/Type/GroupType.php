<?php

namespace Packeton\Form\Type;

use Packeton\Entity\Group;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class GroupType extends AbstractType
{
    public function __construct(private readonly ProxyRepositoryRegistry $registry)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $proxyChoice = $this->registry->getAllNames();
        $proxyChoice = \array_combine($proxyChoice, $proxyChoice);

        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'constraints' => [new NotBlank()]]);

        if ($proxyChoice) {
            $builder
                ->add('proxies', ChoiceType::class, [
                    'choices' => $proxyChoice,
                    'multiple' => true,
                    'label' => 'Allowed Proxies',
                    'required' => false
                ]);
        }

        $builder
            ->add('aclPermissions', GroupAclPermissionCollectionType::class);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class' => Group::class
            ]
        );
    }
}
