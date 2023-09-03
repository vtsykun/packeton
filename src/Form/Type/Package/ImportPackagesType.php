<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Form\Model\ImportRequest;
use Packeton\Form\Type\CredentialType;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Util\PacketonUtils;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ImportPackagesType extends AbstractType
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected IntegrationRegistry $integrations,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Packagist.com/Satis/Composer repo' => 'composer',
                    'VCS repos' => 'vcs',
                    'Integration GitHub/GitLab/Gitea/Bitbucket' => 'integration',
                ],
                'attr' => ['class' => 'package-repo-type package-repo-info']
            ])
            ->add('clone', ChoiceType::class, [
                'label' => 'Clone preference',
                'choices' => [
                    'Use SSH URL' => 'ssh',
                    'Use HTTP URL' => 'http',
                    'Keep default' => 'default',
                    'Use OAuth2 API (only for integration)' => 'api'
                ]
            ])
            ->add('filter', TextareaType::class, [
                'label' => 'Glob repository filter',
                'tooltip' => 'Applied to repository name',
                'required' => false,
                'attr' => ['placeholder' => "thephpleague/flysystem\nsymfony/*\norg1/subgroup1/*", 'rows' => 6, 'class' => 'package-repo-info']
            ])
            ->add('limit', IntegerType::class, [
                'required' => false,
                'label' => 'Max import size',
            ])
            ->add('credentials', CredentialType::class)
            ->add('integration', EntityType::class, [
                'class' => OAuthIntegration::class,
                'required' => false,
                'attr' => ['class' => 'type-hide integration package-repo-info integration-select']
            ])
            ->add('integrationInclude', ChoiceType::class, [
                'label' => 'Include / Exclude those repos',
                'choices' => [
                    'Include' => true,
                    'Exclude' => false,
                ],
                'attr' => ['class' => 'type-hide integration']
            ])
            ->add('integrationRepos', ChoiceType::class, [
                'label' => 'Import only these repos (default all)',
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'jselect2 type-hide integration integration-repo package-repo-info']
            ])
            ->add('composerUrl', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'type-hide composer package-repo-info', 'placeholder' => 'e.g. https://packagist.com/org1'],
            ])
            ->add('username', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'type-hide composer package-repo-info']
            ])
            ->add('password', TextType::class, [
                'required' => false,
                'label' => 'Password (token)',
                'attr' => ['class' => 'type-hide composer package-repo-info']
            ])
            ->add('packageFilter', TextareaType::class, [
                'label' => 'Glob package filter',
                'tooltip' => 'Applied to composer package name',
                'required' => false,
                'attr' => ['placeholder' => "phpunit/phpunit\nsymfony/*\nseld/*", 'rows' => 3, 'class' => 'type-hide composer package-repo-info']
            ])
            ->add('packageList', TextareaType::class, [
                'label' => 'Select only packages (default all packages in the repository)',
                'tooltip' => 'Put your composer.json, composer.lock, composer info output or packages names separated by spaces or comma',
                'required' => false,
                'attr' => ['rows' => 6, 'class' => 'type-hide composer package-repo-info']
            ])
            ->add('repoList', TextareaType::class, [
                'label' => 'List of VCS repos',
                'required' => false,
                'attr' => ['class' => 'type-hide vcs', 'placeholder' => "git@github.com:thephpleague/flysystem.git\ngit@github.com:vtsykun/packeton.git", 'rows' => 8],
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->updateIntegration(...));
        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->updateIntegration(...));
    }

    public function updateIntegration(FormEvent $event): void
    {
        $data = $event->getData();
        $form = $event->getForm();

        if (!$integration = $data['integration'] ?? null) {
            return;
        }
        if (is_numeric($integration)) {
            $integration = $this->registry->getRepository(OAuthIntegration::class)->find((int) $integration);
            if (!$integration instanceof OAuthIntegration) {
                return;
            }
        }

        $choices = $this->getReposChoices($integration);
        $form->add('integrationRepos', ChoiceType::class, [
            'label' => 'Repository name',
            'multiple' => true,
            'required' => false,
            'choices' => $choices,
            'attr' => ['class' => 'jselect2 type-hide integration package-repo-info']
        ]);
    }

    protected function getReposChoices(OAuthIntegration $oauth): array
    {
        $app = $this->integrations->findApp($oauth->getAlias(), false);
        $repos = $app->repositories($oauth);

        $repos = $oauth->filteredRepos($repos, true);
        return PacketonUtils::buildChoices($repos, 'text', 'id');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ImportRequest::class]);
    }
}
