<?php

namespace App\Controller;

use App\Entity\Suggestion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suggestions')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class SuggestionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_suggestions', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Handle submission of new suggestion
        if ($request->isMethod('POST') && $request->request->has('submit_suggestion')) {
            if (!$this->isCsrfTokenValid('create_suggestion', $request->request->get('_token'))) {
                $this->addFlash('error', 'Ungültiges CSRF-Token.');
                return $this->redirectToRoute('app_suggestions');
            }

            $title = trim((string)$request->request->get('title'));
            $description = trim((string)$request->request->get('description'));

            if (empty($title) || empty($description)) {
                $this->addFlash('error', 'Bitte fülle sowohl die Überschrift als auch die Beschreibung aus.');
            } else {
                $suggestion = new Suggestion();
                $suggestion->setTitle($title);
                $suggestion->setDescription($description);
                $suggestion->setUser($currentUser);

                $this->entityManager->persist($suggestion);
                $this->entityManager->flush();

                $this->addFlash('success', 'Dein Anpassungsvorschlag wurde erfolgreich eingereicht.');
            }

            return $this->redirectToRoute('app_suggestions');
        }

        // Fetch all suggestions, order by completed (not completed first) then by date desc
        $suggestions = $this->entityManager->getRepository(Suggestion::class)->createQueryBuilder('s')
            ->orderBy('s.isCompleted', 'ASC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_CEO');

        // Check if we are currently editing a suggestion
        $editingSuggestion = null;
        $editId = $request->query->get('edit_suggestion');
        if ($editId) {
            $suggestion = $this->entityManager->getRepository(Suggestion::class)->find($editId);
            if ($suggestion) {
                if ($suggestion->getUser() === $currentUser || $isAdmin) {
                    $editingSuggestion = $suggestion;
                } else {
                    $this->addFlash('error', 'Du darfst diesen Vorschlag nicht bearbeiten.');
                }
            }
        }

        return $this->render('suggestion/index.html.twig', [
            'suggestions' => $suggestions,
            'editing_suggestion' => $editingSuggestion,
            'isAdmin' => $isAdmin
        ]);
    }

    #[Route('/{id}/update', name: 'app_suggestions_update', methods: ['POST'])]
    public function update(Suggestion $suggestion, Request $request): Response
    {
        $currentUser = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_CEO');
        
        if ($suggestion->getUser() !== $currentUser && !$isAdmin) {
            $this->addFlash('error', 'Du darfst diesen Vorschlag nicht bearbeiten.');
            return $this->redirectToRoute('app_suggestions');
        }

        if (!$this->isCsrfTokenValid('update_' . $suggestion->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_suggestions');
        }

        $title = trim((string)$request->request->get('title'));
        $description = trim((string)$request->request->get('description'));

        if (empty($title) || empty($description)) {
            $this->addFlash('error', 'Bitte fülle sowohl die Überschrift als auch die Beschreibung aus.');
            return $this->redirectToRoute('app_suggestions', ['edit_suggestion' => $suggestion->getId()]);
        }

        $suggestion->setTitle($title);
        $suggestion->setDescription($description);
        $this->entityManager->flush();

        $this->addFlash('success', 'Der Anpassungsvorschlag wurde erfolgreich aktualisiert.');

        return $this->redirectToRoute('app_suggestions');
    }

    #[Route('/{id}/toggle-complete', name: 'app_suggestions_toggle', methods: ['POST'])]
    public function toggleComplete(Suggestion $suggestion, Request $request): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_CEO');
        if (!$isAdmin) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('toggle_' . $suggestion->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_suggestions');
        }

        $suggestion->setIsCompleted(!$suggestion->isCompleted());
        $this->entityManager->flush();

        $status = $suggestion->isCompleted() ? 'erledigt' : 'offen';
        $this->addFlash('success', sprintf('Vorschlag wurde als "%s" markiert.', $status));

        return $this->redirectToRoute('app_suggestions');
    }

    #[Route('/{id}/delete', name: 'app_suggestions_delete', methods: ['POST'])]
    public function delete(Suggestion $suggestion, Request $request): Response
    {
        $currentUser = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_CEO');
        
        if ($suggestion->getUser() !== $currentUser && !$isAdmin) {
            $this->addFlash('error', 'Du darfst diesen Vorschlag nicht löschen.');
            return $this->redirectToRoute('app_suggestions');
        }

        if (!$this->isCsrfTokenValid('delete_' . $suggestion->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_suggestions');
        }

        $this->entityManager->remove($suggestion);
        $this->entityManager->flush();

        $this->addFlash('danger', 'Der Anpassungsvorschlag wurde gelöscht.');

        return $this->redirectToRoute('app_suggestions');
    }
}
