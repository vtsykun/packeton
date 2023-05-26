<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PrivateKeyType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [new Callback([__CLASS__, 'validatePrivateKey'])]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return TextareaType::class;
    }

    /**
     * @param ExecutionContextInterface $context
     * @param string|null $value
     */
    public static function validatePrivateKey($value, ExecutionContextInterface $context): void
    {
        if (empty($value)) {
            return;
        }

        if ($key = openssl_pkey_get_private($value)) {
            if ($pubInfo = openssl_pkey_get_details($key)) {
                return;
            }
        }

        $context->addViolation('This private key is not valid');
    }
}
