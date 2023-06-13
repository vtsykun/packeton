<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Packeton\Entity\OAuthIntegration;
use Packeton\Integrations\Model\AppConfig;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IntegrationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $repos = $options['repos'];
        $repos = PacketonUtils::buildChoices($repos, 'label', 'name');

        $builder
            ->add('globFilter', TextareaType::class, [
                'required' => false,
                'label' => 'Allowed repository filter (globs)',
                'attr' => ['rows' => 4],
                'help' => 'asdsa'
            ])
            ->add('excludedRepos', ChoiceType::class, [
                'required' => false,
                'label' => 'Excluded VCS repos',
                'choices' => $repos,
                'multiple' => true,
                'attr' => ['class'  => 'jselect2']
            ])
            ->add('clonePreference', ChoiceType::class, [
                'required' => false,
                'label' => 'Clone preference',
                'choices' => [
                    'Use global integration settings'  => null,
                    'Use API (without clone)' => 'api',
                    'Clone via https (with access token)'  => 'clone_https',
                    'Clone via ssh (use system ssh agent)'  => 'clone_ssh',
                ],
            ])
            ->add('enableSynchronization', ChoiceType::class, [
                'required' => false,
                'label' => 'Enable synchronization',
                'choices' => [
                    'Use global integration settings'  => null,
                    'Auto sync new repos' => true,
                    'Disabled'  => false,
                ],
            ])
            ->add('pullRequestReview', ChoiceType::class, [
                'required' => false,
                'label' => 'Pull request review',
                'choices' => [
                    'Use global integration settings'  => null,
                    'Enable PR Review' => true,
                    'Disabled'  => false,
                ],
            ]);

        $config = $options['api_config'];
        if ($config instanceof AppConfig) {
            if ($config->hasLoginExpression()) {
                $builder->add('useForExpressionApi', CheckboxType::class, ['required' => false]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => OAuthIntegration::class,
            'repos' => [],
            'api_config' => null,
        ]);
    }
}
