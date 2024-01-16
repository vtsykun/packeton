<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SettingsPackageType extends AbstractType
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('disabledUpdate', CheckboxType::class, [
                'required' => false,
                'label' => 'Disable cron auto-updates',
            ])
            ->add('fullVisibility', CheckboxType::class, [
                'required' => false,
                'label' => 'Visible for all users',
            ]);

        if ($options['has_active_integration']) {
            $builder->add('pullRequestReview', ChoiceType::class, [
                'required' => false,
                'label' => 'Pull Request composer diff review',
                'choices' => [
                    'Use global config settings'  => null,
                    'Enable PR Review' => true,
                ],
                'priority' => -10,
            ]);
        }
    }

    protected function hasActiveIntegration(): bool
    {
        return (bool) $this->registry->getRepository(OAuthIntegration::class)->findOneBy([]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', Package::class);
        $resolver->setDefault('has_active_integration', $this->hasActiveIntegration());
    }
}
