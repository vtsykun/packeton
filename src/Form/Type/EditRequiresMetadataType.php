<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Composer\Package\Version\VersionParser;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class EditRequiresMetadataType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $example = json_encode(['php' => '>=7.2', "symfony/yaml" => '^5.0 || ^6.0 || ^7.0', 'pragmarx/random' => 'unset'], 448) . "\n\n" . 'Or keep empty to clear prev...';

        $builder
            ->add('version', TextType::class, [
                'constraints' => [new NotBlank(), new Callback($this->versionValidator(...))],
                'attr' => ['placeholder' => '4.0.*']
            ])
            ->add('metadata', JsonTextType::class, [
                'required' => false,
                'attr' => ['rows' => 15, 'placeholder' => $example],
            ]);
    }

    protected function versionValidator($value, ExecutionContextInterface $context): void
    {
        if (!$value) {
            return;
        }

        $parser = new VersionParser();
        try {
            $parser->parseConstraints($value);
        } catch (\Exception $exception) {
            $context->buildViolation($exception->getMessage())
                ->addViolation();
        }
    }
}
