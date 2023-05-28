<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Event\FormHandlerEvent;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class UserFormHandler
{
    public function __construct(
        protected UserPasswordHasherInterface $passwordHasher,
        protected ManagerRegistry $registry,
        protected EventDispatcherInterface $dispatcher,
        protected MailerInterface $mailer
    ){
    }

    public function handle(Request|array $request, FormInterface $form, bool $patch = false): bool
    {
        if ($request instanceof Request) {
            $form->handleRequest($request);
        } else {
            $form->submit($request, !$patch);
        }

        $em = $this->registry->getManager();
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            if ($user->getEmail() === null) {
                $user->setEmail($user->getUsernameCanonical() . '@example.com');
            }
            if ($user->getId() === null) {
                $user->generateApiToken();
            }

            if ($planPassword = $user->getPlainPassword()) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $planPassword));
            }

            $em->persist($user);
            $em->flush();

            $this->sendInvitation($form, $user);

            $this->dispatcher->dispatch(new FormHandlerEvent($form, User::class), FormHandlerEvent::NAME);

            return true;
        }

        return false;
    }

    protected function sendInvitation(FormInterface $form, User $user): void
    {
        if (!$form->has('invitation') || !$form->get('invitation')->getData()) {
            return;
        }

        $user->setPassword(null);
        $user->setConfirmationToken($token = 'invite_' . sha1(random_bytes(40)));
        $this->registry->getManager()->flush();

        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('[Packeton] Invitation confirmation')
            ->textTemplate('user/invite.txt.twig')
            ->context(['token' => $token]);

        $this->mailer->send($email);
    }
}
