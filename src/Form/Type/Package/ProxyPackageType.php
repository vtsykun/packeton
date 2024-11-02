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

class ProxyPackageType extends AbstractType
{
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
            ->add('name', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank()],
                'attr' => ['class' => 'package-repo-info', 'placeholder' => 'acme/package-name'],
                'disabled' => false === $options['is_created'],
            ])
            ->add('repository', TextType::class, [
                'label' => 'Packages.json',
                'attr'  => [
                    'class' => 'package-repo-info',
                    'placeholder' => 'e.g.: https://repo.magento.com',
                ],
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
     * @param FormEvent $event
     */
    public function updateRepository(FormEvent $event): void
    {
        $package = $event->getData();
        if ($package instanceof Package) {
            $this->packageManager->updatePackageUrl($package);
        }
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
        return 'proxy';
    }
}
