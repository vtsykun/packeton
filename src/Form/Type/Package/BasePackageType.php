<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Packeton\Package\RepTypes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BasePackageType extends AbstractType
{
    public function __construct(protected ManagerRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['is_created']) {
            $choices = [
                'VCS (auto)' => RepTypes::VCS,
                'MonoRepos (only GIT)' => RepTypes::MONO_REPO,
                'Artifacts' => RepTypes::ARTIFACT,
            ];

            if ($options['has_active_integration']) {
                $choices['Integration'] = RepTypes::INTEGRATION;
            }

            $builder->add('repoType', ChoiceType::class, [
                'choices' => $choices,
                'attr' => ['class' => 'repo-type']
            ]);
        }

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
        $resolver->setDefault('is_created', false);
        $resolver->setDefault('data_class', Package::class);
        $resolver->setDefault('has_active_integration', $this->hasActiveIntegration());
    }
}
