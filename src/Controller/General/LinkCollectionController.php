<?php

namespace App\Controller\General;

use App\Entity\LinkCollection\LinkCollectionCategory;
use App\Entity\LinkCollection\LinkCollectionItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/general/link')]
#[IsGranted('ROLE_MEMBER')]
final class LinkCollectionController extends AbstractController
{
    #[Route('/collection', name: 'link_collection', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        $categories = $entityManager->getRepository(LinkCollectionCategory::class)->findBy([], ['Name' => 'ASC']);
        $links = $entityManager->getRepository(LinkCollectionItem::class)->findBy([], ['Name' => 'ASC']);

        // Group links by category ID and name for template lookup
        $groupedLinks = [];
        foreach ($links as $link) {
            if ($link->getCategory()) {
                $groupedLinks[$link->getCategory()->getId()][] = $link;
                $groupedLinks[$link->getCategory()->getName()][] = $link;
            }
        }

        $editingLink = null;
        $editLinkId = $request->query->get('edit_link');
        if ($editLinkId) {
            $editingLink = $entityManager->getRepository(LinkCollectionItem::class)->find($editLinkId);
        }

        $editingCategory = null;
        $editCategoryId = $request->query->get('edit_category');
        if ($editCategoryId) {
            $editingCategory = $entityManager->getRepository(LinkCollectionCategory::class)->find($editCategoryId);
        }

        return $this->render('tool/link_collection/linkCollection.html.twig', [
            'groupedLinks' => $groupedLinks,
            'categories' => $categories,
            'editing_link' => $editingLink,
            'editing_category' => $editingCategory,
        ]);
    }

    #[Route('/create-link', name: 'link_create_link', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_create_link(
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        if (!$this->isCsrfTokenValid('create_link', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('link_collection');
        }

        $categoryId = (int) $request->request->get('Category');
        $category = $entityManager->getRepository(LinkCollectionCategory::class)->find($categoryId);

        if (!$category) {
            $this->addFlash('error', 'Kategorie wurde nicht gefunden.');
            return $this->redirectToRoute('link_collection');
        }

        $name = trim((string) $request->request->get('Name'));
        $url = trim((string) $request->request->get('URL'));
        $description = trim((string) $request->request->get('Description'));

        if (empty($name) || empty($url)) {
            $this->addFlash('error', 'Name und URL dürfen nicht leer sein.');
            return $this->redirectToRoute('link_collection');
        }

        $link = new LinkCollectionItem();
        $link->setName($name);
        $link->setUrl($url);
        $link->setDescription(!empty($description) ? $description : null);
        $link->setCategory($category);

        $entityManager->persist($link);
        $entityManager->flush();

        $this->addFlash('success', 'Link erfolgreich erstellt.');

        return $this->redirectToRoute('link_collection');
    }

    #[Route('/edit-link/{id}', name: 'link_edit_link', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_edit_link(
        int $id,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        if (!$this->isCsrfTokenValid('edit_link_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('link_collection');
        }

        $link = $entityManager->getRepository(LinkCollectionItem::class)->find($id);
        if (!$link) {
            $this->addFlash('error', 'Link wurde nicht gefunden.');
            return $this->redirectToRoute('link_collection');
        }

        $categoryId = (int) $request->request->get('Category');
        $category = $entityManager->getRepository(LinkCollectionCategory::class)->find($categoryId);

        if (!$category) {
            $this->addFlash('error', 'Ausgewählte Kategorie existiert nicht.');
            return $this->redirectToRoute('link_collection');
        }

        $name = trim((string) $request->request->get('Name'));
        $url = trim((string) $request->request->get('URL'));
        $description = trim((string) $request->request->get('Description'));

        if (empty($name) || empty($url)) {
            $this->addFlash('error', 'Name und URL dürfen nicht leer sein.');
            return $this->redirectToRoute('link_collection');
        }

        $link->setName($name);
        $link->setUrl($url);
        $link->setDescription(!empty($description) ? $description : null);
        $link->setCategory($category);

        $entityManager->flush();

        $this->addFlash('success', 'Link erfolgreich aktualisiert.');

        return $this->redirectToRoute('link_collection');
    }

    #[Route('/delete-link/{id}', name: 'link_delete_link', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_delete_link(
        int $id,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        if (!$this->isCsrfTokenValid('delete_link_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('link_collection');
        }

        $link = $entityManager->getRepository(LinkCollectionItem::class)->find($id);
        if (!$link) {
            $this->addFlash('error', 'Link wurde nicht gefunden.');
            return $this->redirectToRoute('link_collection');
        }

        $entityManager->remove($link);
        $entityManager->flush();

        $this->addFlash('success', 'Link erfolgreich gelöscht.');

        return $this->redirectToRoute('link_collection');
    }

    #[Route('/create-category', name: 'link_create_category', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_create_category(
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        if (!$this->isCsrfTokenValid('create_category', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('link_collection');
        }

        $name = trim((string) $request->request->get('CategoryName'));
        if (empty($name)) {
            $this->addFlash('error', 'Der Kategoriename darf nicht leer sein.');
            return $this->redirectToRoute('link_collection');
        }

        $linkCategory = new LinkCollectionCategory();
        $linkCategory->setName($name);

        $entityManager->persist($linkCategory);
        $entityManager->flush();

        $this->addFlash('success', 'Kategorie erfolgreich erstellt.');

        return $this->redirectToRoute('link_collection');
    }

    #[Route('/edit-category/{id}', name: 'link_edit_category', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_edit_category(
        int $id,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        if (!$this->isCsrfTokenValid('edit_category_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('link_collection');
        }

        $category = $entityManager->getRepository(LinkCollectionCategory::class)->find($id);
        if (!$category) {
            $this->addFlash('error', 'Kategorie wurde nicht gefunden.');
            return $this->redirectToRoute('link_collection');
        }

        $name = trim((string) $request->request->get('CategoryName'));
        if (empty($name)) {
            $this->addFlash('error', 'Der Kategoriename darf nicht leer sein.');
            return $this->redirectToRoute('link_collection');
        }

        $category->setName($name);
        $entityManager->flush();

        $this->addFlash('success', 'Kategorie erfolgreich umbenannt.');

        return $this->redirectToRoute('link_collection');
    }

    #[Route('/delete-category/{id}', name: 'link_delete_category', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_delete_category(
        int $id,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        if (!$this->isCsrfTokenValid('delete_category_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('link_collection');
        }

        $category = $entityManager->getRepository(LinkCollectionCategory::class)->find($id);
        if (!$category) {
            $this->addFlash('error', 'Kategorie wurde nicht gefunden.');
            return $this->redirectToRoute('link_collection');
        }

        if ($category->getLinkCollectionItems()->count() > 0) {
            $this->addFlash('error', 'Kategorie enthält noch Links und kann nicht gelöscht werden.');
            return $this->redirectToRoute('link_collection');
        }

        $entityManager->remove($category);
        $entityManager->flush();

        $this->addFlash('success', 'Kategorie erfolgreich gelöscht.');

        return $this->redirectToRoute('link_collection');
    }
}
