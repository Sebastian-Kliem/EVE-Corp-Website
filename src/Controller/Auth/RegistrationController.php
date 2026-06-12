<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        // Redirect already logged in users to the home page
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }

        $errors = [];
        $username = '';
        $success = false;

        if ($request->isMethod('POST')) {
            $username = trim($request->request->get('username', ''));
            $password = $request->request->get('password', '');
            $passwordConfirm = $request->request->get('password_confirm', '');

            // Basic validation
            if (empty($username)) {
                $errors[] = 'Username is required.';
            } elseif (strlen($username) < 3) {
                $errors[] = 'Username must be at least 3 characters long.';
            }

            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters long.';
            }

            if ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match.';
            }

            // Check if user already exists
            if (empty($errors)) {
                $existingUser = $userRepository->findOneBy(['username' => $username]);
                if ($existingUser) {
                    $errors[] = 'An account with this username already exists.';
                }
            }

            // Create user if no validation errors
            if (empty($errors)) {
                $user = new User();
                $user->setUsername($username);
                $user->setRoles(['ROLE_RECRUIT']);
                $user->setPassword(
                    $passwordHasher->hashPassword($user, $password)
                );

                $entityManager->persist($user);
                $entityManager->flush();

                $success = true;
            }
        }

        $response = new Response();
        if (!empty($errors) && $request->isMethod('POST')) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('registration/register.html.twig', [
            'errors' => $errors,
            'last_username' => $username,
            'success' => $success,
        ], $response);
    }
}
