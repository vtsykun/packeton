<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Form\Type\Package;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class AbandonedType
 *
 * Form used to acquire replacement Package information for abandoned package.
 *
 * @package Packeton\Form\Type
 */
class AbandonedType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'replacement',
            TextType::class,
            array(
                'required' => false,
                'label'    => 'Replacement package',
                'attr'     => array('placeholder' => 'optional package name')
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'package';
    }
}
