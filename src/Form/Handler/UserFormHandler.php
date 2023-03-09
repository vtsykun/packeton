<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFormHandler
{
    public function __construct(
        protected UserPasswordHasherInterface $passwordHasher,
        protected ManagerRegistry $registry,
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

            $form->get('fullAccess')->getData()
                ? $user->addRole('ROLE_FULL_CUSTOMER') :
                $user->removeRole('ROLE_FULL_CUSTOMER');

            $em->persist($user);
            $em->flush();
            return true;
        }

        return false;
    }
}
