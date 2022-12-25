<?php

namespace Packagist\WebBundle\Form\Type;

use Composer\Semver\VersionParser;
use Packagist\WebBundle\Form\Model\PackagePermission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PackagePermissionType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add(
                'version',
                TextType::class,
                [
                    'label' => 'Version',
                    'required' => false,
                    'attr' => [
                        'placeholder' => '^1.0|^2.0'
                    ],
                    'constraints' => [new Callback($this->versionValidator())]
                ]
            )
            ->add('selected', CheckboxType::class, ['required' => false])
            ->add('name', HiddenType::class);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            [
                'data_class' => PackagePermission::class
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'package_permission';
    }

    protected function versionValidator()
    {
        return function ($value, ExecutionContextInterface $context) {
            if ($value === '' || $value === null) {
                return;
            }

            $parser = new VersionParser();
            try {
                $parser->parseConstraints($value);
            } catch (\Exception $exception) {
                $context->buildViolation($exception->getMessage())
                    ->addViolation();
            }
        };
    }
}
