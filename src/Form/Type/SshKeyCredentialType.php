<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Composer\Json\JsonFile;
use JsonSchema\Validator;
use Packeton\Entity\SshCredentials;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class SshKeyCredentialType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [new NotBlank()]
            ])
            ->add('key', PrivateKeyType::class, [
                'attr' => ['placeholder' => "-----BEGIN RSA PRIVATE KEY-----\n....", 'rows' => 5],
                'label' => '(optional) Private SSH Key for Git',
                'required' => false,
            ]);

        $example = <<<TXT
{
  "http-basic": {
    "gitea.company.org": {
      "username": "user", 
      "password": "access_token"
    }
  ....
TXT;

        $builder
            ->add('composerConfig', JsonTextType::class, [
                'attr' => ['placeholder' => $example, 'rows' => 7],
                'label' => '(optional) Composer auth.json/config.json',
                'required' => false,
                'constraints' => [new Callback([$this, 'validateComposerJson'])]
            ]);

    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', SshCredentials::class);
        $resolver->setDefault('constraints', [new Callback([$this, 'validateCredential'])]);
    }

    public function validateComposerJson($value, ExecutionContextInterface $context): void
    {
        if (!is_array($value)) {
            return;
        }

        $config = json_decode(json_encode($value));
        $validator = new Validator();

        $schemaFile = 'file://' . JsonFile::COMPOSER_SCHEMA_PATH;
        $schemaData = (object) ['$ref' => $schemaFile.'#/properties/config', '$schema' => "https://json-schema.org/draft-04/schema#"];
        $schemaData->additionalProperties = false;

        $validator->check($config, $schemaData);
        if (!$validator->isValid()) {
            foreach ((array) $validator->getErrors() as $error) {
                $context->addViolation(($error['property'] ? $error['property'].' : ' : '').$error['message']);
            }
        }

        $keys = array_keys($value);
        if ($keys = array_intersect($keys, ['cache-dir', 'cache-files-dir', 'data-dir', 'cache-repo-dir', 'cache-vcs-dir',
            'cache-files-ttl', 'cache-files-maxsize', 'cache-read-only', 'archive-dir', 'cafile', 'capath', 'store-auths',
            'use-parent-dir', 'allow-plugins', 'use-parent-dir', 'home'])
        ) {
            $context->addViolation("This key is not allowed here: " . json_encode(array_values($keys)));
        }
    }

    public function validateCredential($value, ExecutionContextInterface $context): void
    {
        if (!$value instanceof SshCredentials) {
            return;
        }

        if (empty($value->getKey()) && empty($value->getComposerConfig())) {
            $context->addViolation('At least SSH Key or Composer auth must be not empty');
        }
    }
}
