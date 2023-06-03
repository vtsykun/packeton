<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\Model\IntegrationUtils;
use Packeton\Model\PackageManager;
use Packeton\Util\PacketonUtils;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

class IntegrationPackageType extends AbstractType
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected IntegrationRegistry $integrations,
        protected PackageManager $packageManager
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->remove('credentials');

        $builder->add('integration', EntityType::class, [
            'class' => OAuthIntegration::class,
            'required' => false,
            'constraints' => [new NotBlank()],
            'disabled' => false === $options['is_created'],
            'attr' => ['class' => 'integration']
        ]);

        $builder->add('externalRef', ChoiceType::class, [
            'label' => 'Repository name',
            'constraints' => [new NotBlank()],
            'disabled' => false === $options['is_created'],
            'attr' => ['class' => 'jselect2 package-repo-info']
        ]);

        if (!$options['is_created']) {
            $builder->add('repository', TextType::class, [
                'label' => 'Repository URL',
                'attr'  => [
                    'class' => 'package-repo-info',
                    'placeholder' => 'e.g.: https://github.com/composer/composer',
                ],
                'constraints' => [new NotBlank()],
            ]);
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, function ($event) use ($options) {
            $this->onSetData($event, $options);
        });

        if ($options['is_created']) {
            $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->onPreSubmit(...));
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->updateRepository(...), 255);
    }

    public function updateRepository(FormEvent $event): void
    {
        $package = $event->getData();
        if (!$package instanceof Package) {
            return;
        }

        if ($package->getIntegration() !== null && $package->getRepository() === null && $package->getExternalRef()) {
            $oauth = $package->getIntegration();
            $app = $this->integrations->findApp($oauth->getAlias(), false);
            $url = IntegrationUtils::findUrl($package->getExternalRef(), $oauth, $app);
            $package->setRepository($url);
        }

        $this->packageManager->updatePackageUrl($package);
    }

    public function onPreSubmit(FormEvent $event): void
    {
        $data = $event->getData();
        $form = $event->getForm();

        if (!is_numeric($data['integration'] ?? false)) {
            return;
        }

        $oauth = $this->registry->getRepository(OAuthIntegration::class)->find((int) $data['integration']);
        if (!$oauth instanceof OAuthIntegration) {
            return;
        }
        $choices = $this->getReposChoices($oauth);
        $form->add('externalRef', ChoiceType::class, [
            'label' => 'Repository name',
            'constraints' => [new NotNull()],
            'choices' => $choices,
            'attr' => ['class' => 'jselect2 package-repo-info']
        ]);
    }

    public function onSetData(FormEvent $event, array $options): void
    {
        $data = $event->getData();
        $form = $event->getForm();
        if (!$data instanceof Package) {
            return;
        }

        if (!$oauth = $data->getIntegration()) {
            return;
        }

        if ($form->has('externalRef')) {
            $form->remove('externalRef');
        }

        $choices = [];
        try {
            $choices = $this->getReposChoices($oauth);
        } catch (\Exception $e) {
        }

        if (!in_array($data->getExternalRef(), $choices)) {
            $choices[$data->getExternalRef()] = $data->getExternalRef();
        }

        $form->add('externalRef', ChoiceType::class, [
            'label' => 'Repository name',
            'constraints' => [new NotNull()],
            'choices' => $choices,
            'disabled' => false === $options['is_created'],
            'attr' => ['class' => 'jselect2 package-repo-info']
        ]);
    }

    protected function getReposChoices(OAuthIntegration $oauth): array
    {
        $app = $this->integrations->findApp($oauth->getAlias(), false);
        $repos = $app->repositories($oauth);

        $repos = $oauth->filteredRepos($repos, true);
        return PacketonUtils::buildChoices($repos, 'text', 'id');
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'integration_package';
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return BasePackageType::class;
    }
}
