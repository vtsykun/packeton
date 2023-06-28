<?php

namespace Packeton\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\SubRepository;
use Packeton\Entity\User;
use Packeton\Util\PacketonUtils;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class CustomerUserType extends AbstractType
{
    public function __construct(protected ManagerRegistry $registry)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['required' => false])
            ->add('username', null, [
                'disabled' => !$options['is_created'],
                'constraints' => [new NotBlank(), new Regex('/^[a-zA-Z0-9\-_]+$/')]
            ])
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->add('plainPassword', RepeatedType::class, [
                'required' => false,
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Password (Keep empty for only API access)'],
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
                'label' => 'Full read access (ROLE_FULL_CUSTOMER)',
                'mapped' => false,
                'tooltip' => 'Read access to all packages without ACL Group restriction'
            ])
            ->add('isMaintainer', CheckboxType::class, [
                'required' => false,
                'label' => 'Maintainer (ROLE_MAINTAINER)',
                'mapped' => false,
                'tooltip' => 'Can submit and read all packages. (ACL Group is ignored)'
            ]);

        if ($options['is_created']) {
            $builder
                ->add('invitation', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Send an invitation to the user\'s email',
                    'mapped' => false,
                ]);
        }

        $builder
            ->add('groups', EntityType::class, [
                'choice_label' => 'name',
                'class' => Group::class,
                'label' => 'ACL Groups',
                'multiple' => true,
                'required' => false,
                'attr' => ['class'  => 'jselect2']
            ]);

        $subRepos = $this->registry->getRepository(SubRepository::class)->getSubRepositoryData();
        if ($subRepos) {
            $subRepos = PacketonUtils::buildChoices($subRepos, 'name', 'id');
            $subRepos = array_merge(['root' => 0], $subRepos);

            $builder
                ->add('subReposView', ChoiceType::class, [
                    'label' => 'Allowed subrepositories',
                    'multiple' => true,
                    'required' => false,
                    'choices' => $subRepos,
                    'attr' => ['class'  => 'jselect2']
                ]);
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->postSetData(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->postSubmit(...));
    }

    public function postSetData(FormEvent $event): void
    {
        $user = $event->getData();
        if (!$user instanceof User) {
            return;
        }

        if ($user->hasRole('ROLE_FULL_CUSTOMER')) {
            $event->getForm()->get('fullAccess')->setData(true);
        }
        if ($user->hasRole('ROLE_MAINTAINER')) {
            $event->getForm()->get('isMaintainer')->setData(true);
        }
    }

    public function postSubmit(FormEvent $event)
    {
        $user = $event->getData();
        $form = $event->getForm();
        if (!$user instanceof User) {
            return;
        }

        if ($form->has('fullAccess')) {
            $form->get('fullAccess')->getData()
                ? $user->addRole('ROLE_FULL_CUSTOMER') :
                $user->removeRole('ROLE_FULL_CUSTOMER');
        }

        if ($form->has('isMaintainer')) {
            $form->get('isMaintainer')->getData()
                ? $user->addRole('ROLE_MAINTAINER') :
                $user->removeRole('ROLE_MAINTAINER');
        }

        if ($form->has('invitation') && $form->get('invitation')->getData() && empty($user->getEmail())) {
            $form->get('email')->addError(new FormError('You must set a valid email to send invitation.'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_created' => false,
        ]);
    }
}
