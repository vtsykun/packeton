<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class IntegrationGitHubAppType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('installationId', NumberType::class, [
            'required' => true,
            'constraints' => [new NotBlank()],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return IntegrationSettingsType::class;
    }
}
