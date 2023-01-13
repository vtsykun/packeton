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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomerUserType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', EmailType::class)
            ->add('username', null)
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->add('plainPassword', RepeatedType::class, [
                'required' => false,
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat Password'],
                'invalid_message' => 'The entered passwords do not match.',
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
            ->add('fullAccess', CheckboxType::class, [
                'required' => false,
                'label' => 'Full read access',
                'mapped' => false,
                'tooltip' => 'Read access to all packages without ACL Group restriction'
            ])
            ->add('groups', EntityType::class, [
                'choice_label' => 'name',
                'class' => Group::class,
                'label' => 'ACL Groups',
                'multiple' => true,
                'required' => false
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->postSetData(...));
    }

    public function postSetData(FormEvent $event)
    {
        $user = $event->getData();
        if (!$user instanceof User) {
            return;
        }

        if ($user->hasRole('ROLE_FULL_CUSTOMER')) {
            $event->getForm()->get('fullAccess')->setData(true);
        }
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
