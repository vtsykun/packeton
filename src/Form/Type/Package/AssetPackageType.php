<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Form\Type\CredentialType;
use Packeton\Form\Type\JsonTextType;
use Packeton\Model\PackageManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;

class AssetPackageType extends AbstractType
{
    use VcsPackageTypeTrait;

    public function __construct(private readonly PackageManager $packageManager)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->remove('pullRequestReview');

        $builder
            ->add('credentials', CredentialType::class)
            ->add('repository', TextType::class, [
                'label' => 'Repository URL (Git/Svn/Hg)',
                'attr'  => [
                    'class' => 'package-repo-info',
                    'placeholder' => 'e.g.: https://github.com/fullcalendar/fullcalendar',
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('name', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank()],
                'attr' => ['class' => 'package-repo-info', 'placeholder' => 'npm-asset/select2'],
                'disabled' => false === $options['is_created'],
            ]);

        $placeholder = [
            'name' => 'npm-asset/select2',
            'description' => 'Select2 is a jQuery-based replacement for select boxes.',
            'require' => ['php' => '>8.1'],
            'autoload' => ['psr-4' => ['Packeton\\' => 'src/']]
        ];

        $builder
            ->add('customComposerJson', JsonTextType::class, [
                'required' => false,
                'label' => 'composer.json config',
                'attr' => ['rows' => 12, 'placeholder' => json_encode($placeholder, 448)]
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->updateRepository(...), 255);
    }

    public function getParent(): string
    {
        return BasePackageType::class;
    }
}
