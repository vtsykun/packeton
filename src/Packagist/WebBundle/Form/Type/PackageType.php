<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Form\Type;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Model\PackageManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PackageType extends AbstractType
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
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('credentials', CredentialType::class)
            ->add('repository', TextType::class, [
                'label' => 'Repository URL (Git/Svn/Hg)',
                'attr'  => [
                    'placeholder' => 'e.g.: https://github.com/composer/composer',
                ]
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'updateRepository']);
    }

    /**
     * @param FormEvent $event
     */
    public function updateRepository(FormEvent $event)
    {
        $package = $event->getData();
        if ($package instanceof Package) {
            $this->packageManager->updatePackageUrl($package);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Package::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'package';
    }
}
