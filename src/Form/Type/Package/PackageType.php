<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Entity\Package;
use Packeton\Form\Type\CredentialType;
use Packeton\Model\PackageManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class PackageType extends AbstractType
{
    use VcsPackageTypeTrait;

    /**
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @param PackageManager $packageManager
     */
    public function __construct(PackageManager $packageManager)
    {
        $this->packageManager = $packageManager;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('credentials', CredentialType::class)
            ->add('repository', TextType::class, [
                'label' => 'Repository URL (Git/Svn/Hg)',
                'attr'  => [
                    'class' => 'package-repo-info',
                    'placeholder' => 'e.g.: https://github.com/composer/composer',
                ],
                'constraints' => [new NotBlank()],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'updateRepository'], 255);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return BasePackageType::class;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Package::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'package';
    }
}
