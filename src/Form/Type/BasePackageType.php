<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Packeton\Entity\Package;
use Packeton\Package\RepTypes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BasePackageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if ($options['is_created']) {
            $builder->add('repoType', ChoiceType::class, [
                'choices' => [
                    'VCS (auto)' => RepTypes::VCS,
                    'MonoRepos (only GIT)' => RepTypes::MONO_REPO,
                    'Artifacts' => RepTypes::ARTIFACT,
                ],
                'attr' => ['class' => 'repo-type']
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefault('is_created', false);
        $resolver->setDefault('data_class', Package::class);
    }
}
