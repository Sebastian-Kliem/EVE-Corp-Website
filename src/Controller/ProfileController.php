<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            // CSRF Protection
            if (!$this->isCsrfTokenValid('change_password', $request->request->get('_token'))) {
                $errors[] = 'Ungültiges CSRF-Token. Bitte versuche es erneut.';
            }

            $currentPassword = $request->request->get('current_password', '');
            $newPassword = $request->request->get('new_password', '');
            $newPasswordConfirm = $request->request->get('new_password_confirm', '');

            if (empty($errors)) {
                // 1. Verify current password
                if (!$this->passwordHasher->isPasswordValid($currentUser, $currentPassword)) {
                    $errors[] = 'Dein aktuelles Passwort ist nicht korrekt.';
                }

                // 2. Validate new password length
                if (strlen($newPassword) < 6) {
                    $errors[] = 'Das neue Passwort muss mindestens 6 Zeichen lang sein.';
                }

                // 3. Confirm password match
                if ($newPassword !== $newPasswordConfirm) {
                    $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                }

                // If all checks pass, save the new password
                if (empty($errors)) {
                    $hashedPassword = $this->passwordHasher->hashPassword($currentUser, $newPassword);
                    $currentUser->setPassword($hashedPassword);
                    
                    $this->entityManager->flush();
                    $success = true;
                    
                    $this->addFlash('success', 'Dein Passwort wurde erfolgreich geändert!');
                }
            }
        }

        $response = new Response();
        if (!empty($errors) && $request->isMethod('POST')) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('profile/profile.html.twig', [
            'user' => $currentUser,
            'errors' => $errors,
            'success' => $success,
        ], $response);
    }
}
