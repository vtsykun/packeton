<?php

declare(strict_types=1);

namespace Packeton\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TooltipExtension extends AbstractTypeExtension
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefined('tooltip');
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        if (isset($options['tooltip'])) {
            $view->vars['tooltip'] = $options['tooltip'];
        }
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }
}
