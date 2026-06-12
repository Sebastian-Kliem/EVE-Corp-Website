<?php

namespace App\Controller\Tool;

use App\Entity\LinkCollection\LinkCollectionCategory;
use App\Entity\LinkCollection\LinkCollectionItem;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/link')]
#[IsGranted('ROLE_USER')]
final class LinkCollectionController extends AbstractController
{
    #[Route('/collection', name: 'link_collection')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $links = $entityManager->getRepository(LinkCollectionItem::class)->findAll();
        $categories = $entityManager->getRepository(LinkCollectionCategory::class)->findAll();

        //group links by category
        $groupedLinks = [];
        foreach ($links as $link) {
            $groupedLinks[$link->getCategory()->getName()][] = $link;
        }

        return $this->render('link_collection/linkCollection.html.twig', [
            'groupedLinks' => $groupedLinks,
            'categories' => $categories,
        ]);
    }

    #[Route('/create-link', name: 'link_create_link', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_create_link(
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response
    {
        $categoryRepository = $entityManager->getRepository(LinkCollectionCategory::class);
        $category = $categoryRepository->find($request->get('Category'));

        $link = new LinkCollectionItem();
        $link->setName($request->get('Name'));
        $link->setUrl($request->get('URL'));
        $link->setDescription($request->get('Description'));
        $link->setCategory($category);

        $entityManager->persist($link);
        $entityManager->flush();

        return $this->redirectToRoute('link_collection');
    }

    #[Route('/create-category', name: 'link_create_category', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function link_create_category(
        EntityManagerInterface $entityManager,
        Request $request
    ): Response
    {
        $linkCategory = new LinkCollectionCategory();
        $linkCategory->setName($request->get('CategoryName'));

        $entityManager->persist($linkCategory);
        $entityManager->flush();

        return $this->redirectToRoute('link_collection');
    }
}
