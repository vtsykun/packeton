<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Cron\CronExpression;
use Packeton\DBAL\OpensslCrypter;
use Packeton\Entity\User;
use Packeton\Entity\Webhook;
use Packeton\Validator\Constraint\ValidRegex;
use Packeton\Webhook\Twig\PayloadRenderer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class WebhookType extends AbstractType
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly PayloadRenderer $renderer,
        private readonly OpensslCrypter $crypter,
    ) {}

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
                'constraints' => [new NotBlank(),]
            ])
            ->add('method', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    'POST' => 'POST',
                    'GET' => 'GET',
                    'DELETE' => 'DELETE',
                    'PUT' => 'PUT',
                    'PATCH' => 'PATCH'
                ]
            ])
            ->add('packageRestriction', TextType::class, [
                'required' => false,
                'label' => 'Name restriction',
                'tooltip' => 'Must be a valid regex to filter by a package name/path name.',
                'constraints' => [new ValidRegex()]
            ])
            ->add('versionRestriction', TextType::class, [
                'required' => false,
                'tooltip' => 'Must be a valid regex to filter by version.',
                'constraints' => [new ValidRegex()]
            ])
            ->add('cron', TextType::class, [
                'required' => false,
                'tooltip' => 'Must be a valid cron expression to trigger by cron.',
                'constraints' => [new Callback([$this, 'checkCron'])]
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
                'choices' => self::getEventsChoices(),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->onSetData(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->postSubmit(...));
    }

    public function postSubmit(FormEvent $event): void
    {
        $data = $event->getData();
        if (!$data instanceof Webhook || empty($secrets = $data->getOptions()['secrets'] ?? null)) {
            return;
        }

        if (is_array($secrets)) {
            $secrets = $this->crypter->encryptData(json_encode($secrets));
            $data->setOptions(array_merge($data->getOptions(), ['secrets' => $secrets]));
        }
    }

    /**
     * @param FormEvent $event
     */
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
            'constraints' => [new Callback([$this, 'validateWebhook'])],
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
            $this->renderer->init();
            $this->renderer->createTemplate($value);
        } catch (\Throwable $exception) {
            $context->addViolation('This value is not a valid twig. ' . $exception->getMessage());
        }
    }

    public function validateWebhook($value, ExecutionContextInterface $context)
    {
        if (!$value instanceof Webhook) {
            return;
        }

        if ($options = $value->getOptions() and $diff = array_diff(array_keys($options), $this->allowedClientOptions())) {
            $context->addViolation(sprintf('This options is not allowed. "%s"', implode(",", $diff)));
        }

        if (!$hostname = parse_url($value->getUrl(), PHP_URL_HOST)) {
            $context->addViolation("Hostname can not be is empty.");
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            $flag = \FILTER_FLAG_IPV4 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE;
            if (!filter_var($hostname, FILTER_VALIDATE_IP, $flag)) {
                $context->addViolation("This is not a valid IP address $hostname");
            }
        }
    }


    /**
     * @param string|null $value
     * @param ExecutionContextInterface $context
     */
    public function checkCron($value, ExecutionContextInterface $context)
    {
        if (empty($value)) {
            return;
        }

        if (false === CronExpression::isValidExpression($value)) {
            $context->addViolation('This value is not a valid cron expression.');
        }
    }

    public static function getEventsChoices(): array
    {
        return [
            'New stability release' => Webhook::HOOK_RL_NEW,
            'Update stability release' => Webhook::HOOK_RL_UPDATE,
            'Remove stability release' => Webhook::HOOK_RL_DELETE,
            'Update repo failed' => Webhook::HOOK_REPO_FAILED,
            'New tag/branch' => Webhook::HOOK_PUSH_NEW,
            'Update tag/branch' => Webhook::HOOK_PUSH_UPDATE,
            'Created a new repository' => Webhook::HOOK_REPO_NEW,
            'Remove repository' => Webhook::HOOK_REPO_DELETE,
            'By HTTP requests to https://APP_URL/api/webhook-invoke/{name}' => Webhook::HOOK_HTTP_REQUEST,
            'User login event' => Webhook::HOOK_USER_LOGIN,
            'By cron' => Webhook::HOOK_CRON,
        ];
    }

    /**
     * Get allowed options for client https://symfony.com/doc/current/http_client.html
     */
    protected function allowedClientOptions(): array
    {
        return [
            'headers', 'verify_peer', 'verify_host', 'secrets',
            'auth_ntlm', 'auth_basic', 'auth_bearer',
        ];
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
