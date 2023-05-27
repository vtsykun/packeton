<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Packeton\Entity\ApiToken;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class ApiTokenType extends AbstractType
{
    public function __construct(private readonly AuthorizationCheckerInterface $checker)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('expiration', ChoiceType::class, [
                'required' => false,
                'mapped' => false,
                'choices' => [
                    '3 days' => 3,
                    '30 days' => 30,
                    '60 days' => 60,
                    '120 days' => 120,
                    '365 days' => 365,
                ]
            ])
            ->add('scores', ChoiceType::class, [
                'multiple' => true,
                'expanded' => true,
                'attr' => ['with_value' => true],
                'choices' => array_flip($this->getScores())
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->postSubmit(...));
    }

    public function postSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $data = $event->getData();
        if ($data instanceof ApiToken &&
            $form->has('expiration') &&
            ($exp = $form->get('expiration')->getData())
        ) {
            $data->setExpireAt(new \DateTime('+' . $exp . ' days', new \DateTimeZone('UTC')));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', ApiToken::class);
    }

    protected function getScores(): array
    {
        $base = [
            'metadata' => 'Read composer packages.json metadata and ZIP archive access',
            'mirror:all' => 'Full access to mirrored packages',
            'mirror:read' => 'Read-only CI token to mirrored packages',
        ];

        if ($this->checker->isGranted('ROLE_MAINTAINER')) {
            $base += [
                'webhooks' => 'Update packages webhook',
                'feeds' => 'Atom/RSS feed releases',
                'packages:read' => 'Read only access to packages API',
                'packages:all' => 'Submit and read packages API',
            ];
        }
        if ($this->checker->isGranted('ROLE_ADMIN')) {
            $base += [
                'users' => 'Access to user API',
                'groups' => 'Access to groups API',
            ];
        }

        return $base;
    }
}
