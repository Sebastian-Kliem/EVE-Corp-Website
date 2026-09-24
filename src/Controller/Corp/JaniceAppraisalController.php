<?php

namespace App\Controller\Corp;

use App\Entity\DefenseDoctrineFit;
use App\Repository\DefenseDoctrineFitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/corp')]
#[IsGranted('ROLE_MEMBER')]
class JaniceAppraisalController extends AbstractController
{
    #[Route('/appraisal', name: 'app_corp_appraisal', methods: ['GET'])]
    public function index(DefenseDoctrineFitRepository $fitRepository): Response
    {
        $doctrineFits = array_map(function (DefenseDoctrineFit $fit) {
            return [
                'id' => $fit->getId(),
                'title' => $fit->getTitle(),
                'shipName' => $fit->getShipName(),
                'shipTypeId' => $fit->getShipTypeId(),
                'role' => $fit->getRole() ?: 'Allgemein',
                'eft' => $fit->getEft(),
            ];
        }, $fitRepository->findAllOrdered());

        return $this->render('tool/appraisal/appraisal.html.twig', [
            'doctrineFits' => $doctrineFits,
        ]);
    }
}
