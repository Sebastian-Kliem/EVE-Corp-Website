<?php

namespace App\Controller;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class EveAccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('/profile/eve-account/create', name: 'app_eve_account_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('eve_account_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile');
        }

        $name = trim((string) $request->request->get('name'));
        if (empty($name)) {
            $this->addFlash('error', 'Account-Name darf nicht leer sein.');
            return $this->redirectToRoute('app_profile');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $account = new EveAccount();
        $account->setName($name);
        $account->setUser($currentUser);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('EVE Account "%s" erfolgreich erstellt.', $name));

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/eve-account/{id}/update', name: 'app_eve_account_update', methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $account = $this->entityManager->getRepository(EveAccount::class)->find($id);

        if (!$account || $account->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_account_update_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile');
        }

        $name = trim((string) $request->request->get('name'));
        $groupName = trim((string) $request->request->get('groupName'));
        $isOmega = (bool) $request->request->get('isOmega');

        if (empty($name)) {
            $this->addFlash('error', 'Account-Name darf nicht leer sein.');
            return $this->redirectToRoute('app_profile');
        }

        $account->setName($name);
        $account->setGroupName(!empty($groupName) ? $groupName : null);
        $account->setIsOmega($isOmega);

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Account "%s" erfolgreich aktualisiert.', $name));

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/eve-account/{id}/delete', name: 'app_eve_account_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $account = $this->entityManager->getRepository(EveAccount::class)->find($id);

        if (!$account || $account->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_account_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile');
        }

        // Reassign characters to null
        foreach ($account->getCharacters() as $character) {
            $character->setAccount(null);
        }

        $accountName = $account->getName();

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Account "%s" wurde gelöscht. Zuvor verknüpfte Charaktere sind nun nicht zugewiesen.', $accountName));

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/eve-character/{id}/assign', name: 'app_eve_character_assign', methods: ['POST'])]
    public function assignCharacter(int $id, Request $request): Response
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_character_assign_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile');
        }

        $accountId = $request->request->get('accountId');

        if (empty($accountId) || $accountId === 'unassigned') {
            $character->setAccount(null);
            $this->addFlash('success', sprintf('Charakter "%s" ist nun keinem Account mehr zugewiesen.', $character->getName()));
        } else {
            $account = $this->entityManager->getRepository(EveAccount::class)->find((int) $accountId);
            if (!$account || $account->getUser() !== $this->getUser()) {
                $this->addFlash('error', 'Ausgewählter Account ist ungültig.');
                return $this->redirectToRoute('app_profile');
            }

            $character->setAccount($account);
            $this->addFlash('success', sprintf('Charakter "%s" dem Account "%s" zugewiesen.', $character->getName(), $account->getName()));
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_profile');
    }
}
