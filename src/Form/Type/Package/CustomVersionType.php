<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Form\Type\JsonTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CustomVersionType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $placeholder = [
            'require' => ['php' => '>8.1', 'cebe/markdown' => '^1.1'],
            'autoload' => ['psr-4' => ['Packeton\\' => 'src/']]
        ];

        $builder
            ->add('version', TextType::class, [
                'constraints' => [new NotBlank()]
            ]);

        if ($options['with_dist']) {
            $builder
                ->add('dist', ChoiceType::class, [
                    'required' => false,
                    'label' => 'Uploaded dist',
                    'choices' => $options['dist_choices'],
                    'attr' => ['class' => 'jselect2 archive-select']
                ]);
        }

        $builder
            ->add('definition', JsonTextType::class, [
                'required' => false,
                'label' => 'composer.json config',
                'attr' => ['rows' => 12, 'placeholder' => json_encode($placeholder, 448)]
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'dist_choices' => null,
            'with_dist' => true,
        ]);

        $resolver->addNormalizer('constraints', function(Options $options): array {
            if ($options['with_dist']) {
                return [new Callback($this->validateData(...))];
            }
            return [];
        });
    }

    public function validateData($value, ExecutionContextInterface $context): void
    {
        if (empty($value['dist']) && empty($value['definition']['dist']) && empty($value['definition']['source'])) {
            $context->addViolation('You must select at least one dist or source archive.');
        }
    }
}
