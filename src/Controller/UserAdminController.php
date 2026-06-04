<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin')]
#[IsGranted('ROLE_OFFICER')]
class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('/users', name: 'app_admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->get('search', ''));
        
        if (!empty($search)) {
            $users = $this->userRepository->createQueryBuilder('u')
                ->where('u.username LIKE :search')
                ->setParameter('search', '%' . $search . '%')
                ->getQuery()
                ->getResult();
        } else {
            $users = $this->userRepository->findAll();
        }

        return $this->render('admin/user_list.html.twig', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    #[Route('/users/{id}/set-role', name: 'app_admin_set_role', methods: ['POST'])]
    public function setRole(User $user, Request $request): Response
    {
        // CSRF Protection
        if (!$this->isCsrfTokenValid('set_role_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_users');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $role = $request->request->get('role', '');

        $allowedRoles = ['ROLE_RECRUIT', 'ROLE_MEMBER', 'ROLE_OFFICER', 'ROLE_CEO'];
        if (!in_array($role, $allowedRoles, true)) {
            $this->addFlash('error', 'Ungültige Rolle.');
            return $this->redirectToRoute('app_admin_users');
        }

        // 1. Protection of the main system administrators (ROLE_ADMIN)
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $this->addFlash('error', 'Die Rechte des System-Administrators (ROLE_ADMIN) sind geschützt und können im UI nicht geändert werden.');
            return $this->redirectToRoute('app_admin_users');
        }

        // 2. Self-demotion protection
        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'Du kannst deine eigenen Rechte nicht verwalten oder entziehen.');
            return $this->redirectToRoute('app_admin_users');
        }

        // 3. Permission checks based on current user roles
        $isCeo = $this->isGranted('ROLE_CEO');
        
        if (!$isCeo) {
            // ROLE_OFFICER can only toggle between ROLE_RECRUIT and ROLE_MEMBER (members verifications)
            // They are not allowed to change users with existing ROLE_OFFICER, ROLE_CEO or ROLE_ADMIN, 
            // and cannot assign ROLE_OFFICER, ROLE_CEO or ROLE_ADMIN to anyone.
            $targetUserHasHigherRole = in_array('ROLE_ADMIN', $user->getRoles(), true)
                || in_array('ROLE_CEO', $user->getRoles(), true)
                || in_array('ROLE_OFFICER', $user->getRoles(), true);
            $actionIsToHigherRole = in_array($role, ['ROLE_ADMIN', 'ROLE_CEO', 'ROLE_OFFICER'], true);
            
            if ($targetUserHasHigherRole || $actionIsToHigherRole) {
                $this->addFlash('error', 'Du hast keine Berechtigung, erweiterte Rollen zu verwalten.');
                return $this->redirectToRoute('app_admin_users');
            }
        }

        // Apply new role
        $user->setRoles([$role]);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Die Rolle von %s wurde erfolgreich auf %s geändert.', $user->getDisplayName(), $role));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/users/{id}/reset-password', name: 'app_admin_reset_password', methods: ['POST'])]
    public function resetPassword(User $user, Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        // CSRF Protection
        if (!$this->isCsrfTokenValid('reset_password_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_users');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // 1. Protection of the main website administrator (ROLE_ADMIN)
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $this->addFlash('error', 'Das Passwort des System-Administrators (ROLE_ADMIN) ist geschützt und kann im UI nicht zurückgesetzt werden.');
            return $this->redirectToRoute('app_admin_users');
        }

        // 2. Self-reset protection (must use profile page)
        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'Du kannst dein eigenes Passwort nicht über die Administration zurücksetzen. Bitte nutze dein Profil.');
            return $this->redirectToRoute('app_admin_users');
        }

        // 3. Hierarchical validation (ROLE_OFFICER cannot reset password of ROLE_OFFICER, ROLE_CEO, or ROLE_ADMIN)
        $isCeo = $this->isGranted('ROLE_CEO');
        if (!$isCeo) {
            $targetUserHasHigherRole = in_array('ROLE_ADMIN', $user->getRoles(), true)
                || in_array('ROLE_CEO', $user->getRoles(), true)
                || in_array('ROLE_OFFICER', $user->getRoles(), true);
            if ($targetUserHasHigherRole) {
                $this->addFlash('error', 'Du hast keine Berechtigung, das Passwort von erweiterten Mitgliedern zurückzusetzen.');
                return $this->redirectToRoute('app_admin_users');
            }
        }

        // 4. Generate temporary password
        $tempPassword = 'Keepers-' . random_int(100000, 999999);
        $hashedPassword = $passwordHasher->hashPassword($user, $tempPassword);
        
        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        // Pass the temporary password via a special flash message so it's shown once
        $this->addFlash('success_temp_password', [
            'username' => $user->getUsername(),
            'displayName' => $user->getDisplayName(),
            'password' => $tempPassword
        ]);

        return $this->redirectToRoute('app_admin_users');
    }
}
