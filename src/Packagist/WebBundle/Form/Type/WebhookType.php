<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Form\Type;

use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Validator\Constraint\ValidRegex;
use Packagist\WebBundle\Webhook\PayloadRenderer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class WebhookType extends AbstractType
{
    /**
     * @var TokenStorage
     */
    private $tokenStorage;

    /**
     * @var PayloadRenderer
     */
    private $renderer;

    /**
     * @param TokenStorage $tokenStorage
     * @param PayloadRenderer $renderer
     */
    public function __construct(TokenStorage $tokenStorage, PayloadRenderer $renderer)
    {
        $this->tokenStorage = $tokenStorage;
        $this->renderer = $renderer;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank()]
            ])
            ->add('url', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank(), new Url()]
            ])
            ->add('method', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    'POST' => 'POST',
                    'GET' => 'GET',
                    'DELETE' => 'DELETE',
                    'PUT' => 'PUT'
                ]
            ])
            ->add('packageRestriction', TextType::class, [
                'required' => false,
                'tooltip' => 'Must be a valid regex to filter by a package name.',
                'constraints' => [new ValidRegex()]
            ])
            ->add('versionRestriction', TextType::class, [
                'required' => false,
                'tooltip' => 'Must be a valid regex to filter by version.',
                'constraints' => [new ValidRegex()]
            ])
            ->add('options', JsonTextType::class, [
                'required' => false,
                'label' => 'Request options',
                'tooltip' => 'webhooks.options.tooltip',
                'attr' => [
                    'rows' => 6,
                    'style' => 'resize: none;'
                ]
            ])
            ->add('payload', TextareaType::class, [
                'required' => false,
                'tooltip' => 'webhooks.payload.tooltip',
                'attr' => [
                    'rows' => 10,
                    'style' => 'resize: none;'
                ],
                'constraints' => [new Callback([$this, 'checkPayload'])]
            ])
            ->add('visibility', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    'Visible for all admin users' => Webhook::GLOBAL_VISIBLE,
                    'Only visible for you' => Webhook::USER_VISIBLE,
                ]
            ]);

        $builder
            ->add('events', ChoiceType::class, [
                'required' => false,
                'label' => 'Trigger',
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'A new stability release' => Webhook::HOOK_RL_NEW,
                    'Update existing stability release' => Webhook::HOOK_RL_UPDATE,
                    'Update repo failed' => Webhook::HOOK_REPO_FAIL,
                    'Remove stability release' => Webhook::HOOK_RL_DELETE,
                    'Push a new tag/branch' => Webhook::HOOK_PUSH_NEW,
                    'Update any tag/branch hash' => Webhook::HOOK_PUSH_UPDATE,
                    'Created a new repository' => Webhook::HOOK_REPO_NEW,
                    'Remove repository' => Webhook::HOOK_REPO_DELETE,
                ]
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, [$this, 'onSetData']);
    }

    public function onSetData(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$user = $this->getCurrentUser()) {
            $form->remove('visibility');
            return;
        }

        $data = $event->getData();
        if (!$data instanceof Webhook) {
            return;
        }
        if ($data->getId() === null) {
            $data->setOwner($user);
            return;
        }
        if ($data->getOwner() && $data->getOwner()->getId() !== $user->getId()) {
            $form->remove('visibility');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' =>  Webhook::class,
            'visibility_field' => false,
        ]);
    }

    /**
     * @param string|null $value
     * @param ExecutionContextInterface $context
     */
    public function checkPayload($value, ExecutionContextInterface $context): void
    {
        if (empty($value)) {
            return;
        }

        try {
            $this->renderer->createTemplate($value);
        } catch (\Throwable $exception) {
            $context->addViolation('This value is not a valid twig. ' . $exception->getMessage());
        }
    }

    /**
     * @return object|null|User
     */
    protected function getCurrentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        return $token && $token->getUser() instanceof User ? $token->getUser() : null;
    }
}
