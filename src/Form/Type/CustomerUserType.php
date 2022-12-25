<?php

namespace Packeton\Form\Type;

use Packeton\Entity\Group;
use Packeton\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomerUserType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'form.email', 'translation_domain' => 'FOSUserBundle'])
            ->add('username', null, ['label' => 'form.username', 'translation_domain' => 'FOSUserBundle'])
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->add('plainPassword', RepeatedType::class, [
                'required' => false,
                'type' => PasswordType::class,
                'options' => ['translation_domain' => 'FOSUserBundle'],
                'first_options' => ['label' => 'form.password'],
                'second_options' => ['label' => 'form.password_confirmation'],
                'invalid_message' => 'fos_user.password.mismatch',
            ])
            ->add('expiresAt', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'Access expiration date',
            ])
            ->add('expiredUpdatesAt', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'Update expiration',
                'tooltip' => 'A new release updates will be frozen after this date. But the user can uses the versions released before'
            ])
            ->add('groups', EntityType::class, [
                'choice_label' => 'name',
                'class' => Group::class,
                'multiple' => true,
                'required' => false
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => User::class
        ]);
    }
}
