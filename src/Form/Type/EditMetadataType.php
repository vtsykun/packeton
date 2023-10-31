<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Composer\Json\JsonFile;
use JsonSchema\Validator;
use Packeton\Mirror\Utils\ApiMetadataUtils;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class EditMetadataType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $example = json_encode(['require' => ['php' => '>=7.2']], 448) . "\n\n" . 'Or keep empty to clear prev...';

        $versions = array_combine($options['versions'], $options['versions']);
        $builder->add('version', ChoiceType::class, [
                'choices' => $versions,
                'constraints' => [new NotBlank()]
            ])
            ->add('strategy', ChoiceType::class, [
                'choices' => [
                    'Merge Recursive' => 'merge_recursive',
                    'Merge (only keys)' => 'merge',
                    'Replace All' => 'replace',
                ]
            ])
            ->add('metadata', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 15, 'placeholder' => $example],
                'constraints' => [new Callback($this->versionValidator($options['metadata']))]
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('versions', []);
        $resolver->setDefault('metadata', []);
    }

    protected function versionValidator(array $metadata = []): callable
    {
        return function ($value, ExecutionContextInterface $context) use ($metadata) {
            $root = $context->getRoot();
            if (!$root instanceof FormInterface || empty($value)) {
                return;
            }

            $data = $root->getData();
            try {
                if (!is_array($patches = JsonFile::parseJson($value))) {
                    $context->addViolation('Metadata must be array json');
                    return;
                }
            } catch (\Exception $e) {
                $context->addViolation($e->getMessage());
                return;
            }

            $patchData = [$data['strategy'] ?? '', [($data['version'] ?? 'na') => $patches]];
            $package = (string) array_key_first($metadata['packages']);
            $metadata = ApiMetadataUtils::applyMetadataPatchV1($package, $metadata, $patchData)['packages'][$package] ?? [];

            $metadata = array_filter($metadata, fn($item) => $data['version'] === $item['version_normalized'] ?? null);
            $metadata = reset($metadata);
            if (empty($metadata)) {
                $context->addViolation('Version not found');
                return;
            }

            $config = json_decode(json_encode($metadata));
            $validator = new Validator();

            $schemaFile = 'file://' . JsonFile::COMPOSER_SCHEMA_PATH;
            $schemaData = (object) ['$ref' => $schemaFile,];
            $schemaData->additionalProperties = true;
            $schemaData->required = ['name', 'version_normalized', 'version'];

            $validator->check($config, $schemaData);
            if (!$validator->isValid()) {
                foreach ($validator->getErrors() as $error) {
                    $context->addViolation(($error['property'] ? $error['property'].' : ' : '').$error['message']);
                }
            }
        };
    }
}
