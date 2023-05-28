<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Constraints as Assert;

class SecurityController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ManagerRegistry $registry,
    ) {
    }

    #[Route('/login', name: 'login')]
    public function loginAction(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        if ($this->getUser()) {
            return $this->redirect('/');
        }

        return $this->render('user/login.html.twig', [
            'lastUsername' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/reset-password', name: 'request_pwd_reset')]
    public function request(Request $request, MailerInterface $mailer)
    {
        $form = $this->createFormBuilder()
            ->add('email', TextType::class, [
                'required' => true,
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Email or Username',
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->processSendingPasswordResetEmail($form->get('email')->getData(), $mailer);
            return $this->redirect($this->generateUrl('request_pwd_check_email'));
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/reset-password/check-email', name: 'request_pwd_check_email')]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig');
    }

    #[Route(path: '/reset-password/invite/{token}', name: 'do_user_invite')]
    public function invite(Request $request, string $token): Response
    {
        if (strlen($token) < 40 || !str_starts_with($token, 'invite')) {
            throw $this->createNotFoundException('The invite user token is not a valid.');
        }

        $user = $this->getEM()->getRepository(User::class)->findOneBy(['confirmationToken' => $token]);
        if (!$user instanceof User) {
            throw $this->createNotFoundException('The invite user token is not a valid.');
        }

        return $this->processPasswordReset($request, $user, function (bool $result, $form) use ($user) {
            if ($result) {
                $user->setEnabled(true);
                $this->getEM()->flush();
                $this->addFlash('success', 'Invention was accepted successfully. You can login now');
                return $this->redirectToRoute('login');
            }

            return $this->render('reset_password/invite.html.twig', [
                'resetForm' => $form,
            ]);
        });
    }

    #[Route(path: '/reset-password/reset/{token}', name: 'do_pwd_reset')]
    public function reset(Request $request, string $token): Response
    {
        if (strlen($token) < 40) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        $user = $this->getEM()->getRepository(User::class)->findOneBy(['confirmationToken' => $token]);
        if (null === $user || !$user->isPasswordRequestNonExpired(86400)) {
            $this->addFlash('reset_password_error', 'Your password reset request has expired.');
            return $this->redirectToRoute('request_pwd_reset');
        }

        return $this->processPasswordReset($request, $user, function (bool $result, $form) {
            if ($result) {
                $this->addFlash('success', 'Your password was reset successfully.');
                return $this->redirectToRoute('login');
            }

            return $this->render('reset_password/reset.html.twig', [
                'resetForm' => $form,
            ]);
        });
    }

    private function processPasswordReset(Request $request, User $user, callable $handler)
    {
        $form = $this->createFormBuilder()
            ->add('plainPassword', PasswordType::class, [
                'label' => 'New password',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['min' => 6]),
                    new Assert\Type('string')
                ],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPasswordRequestedAt();
            $user->setConfirmationToken();

            // Encode the plain password, and set it.
            $encodedPassword = $this->hasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );

            $user->setPassword($encodedPassword);
            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $handler(true, $form);
        }

        return $handler(false, $form);

    }

    private function processSendingPasswordResetEmail(string $userEmail, MailerInterface $mailer): void
    {
        $user = $this->getEM()->getRepository(User::class)->findOneByUsernameOrEmail($userEmail);
        if (!$user instanceof User) {
            return;
        }

        // Limit number of sending emails - only one per 10 min
        if (null !== $user->getConfirmationToken() && $user->isPasswordRequestNonExpired(600)) {
            return;
        }

        if (null === $user->getConfirmationToken() || !$user->isPasswordRequestNonExpired(86400)) {
            // only regenerate a new token once every 24h or as needed
            $user->setConfirmationToken(substr(hash('sha256', random_bytes(40)), 0, 40));
            $user->setPasswordRequestedAt(new \DateTime());
            $this->getEM()->flush();
        }

        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Your password reset request')
            ->textTemplate('reset_password/email.txt.twig')
            ->context(['token' => $user->getConfirmationToken()]);

        $mailer->send($email);
    }

    #[Route('/logout', name: 'logout')]
    public function logout()
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
