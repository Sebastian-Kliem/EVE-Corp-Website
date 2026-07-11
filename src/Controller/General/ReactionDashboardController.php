<?php

namespace App\Controller\General;

use App\Entity\EveCorporationAsset;
use App\Entity\EveStructure;
use App\Entity\User;
use App\Service\ReactionPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/general/reactions')]
#[IsGranted('ROLE_MEMBER')]
class ReactionDashboardController extends AbstractController
{
    public function __construct(
        private readonly ReactionPriceService $reactionPriceService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_general_reactions', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Fetch registered corporation structures
        $dbStructures = $this->entityManager->getRepository(EveStructure::class)->findAll();
        $assetRepo = $this->entityManager->getRepository(EveCorporationAsset::class);
        $structuresList = [];

        foreach ($dbStructures as $struct) {
            $name = $struct->getName();
            $systemName = $struct->getSolarSystemName();
            $structId = (int)$struct->getId();

            // Filter out POCOs / Customs Offices
            if (preg_match('/(customs|custom-office|zollamt|poco)/i', $name)) {
                continue;
            }

            // Filter out generic "Spieler-Struktur" and unresolved systems
            if ($name === 'Spieler-Struktur') {
                continue;
            }
            if ($systemName === null || $systemName === 'Unbekannt') {
                continue;
            }

            // 1. Resolve Structure Type (Athanor vs Tatara) from Corp Assets
            $structAsset = $assetRepo->findOneBy(['itemId' => $structId]);
            $structType = 'athanor'; // default fallback
            if ($structAsset) {
                $typeId = $structAsset->getTypeId();
                if ($typeId === 35836) {
                    $structType = 'tatara';
                }
            }

            // 2. Resolve Rigs fitted to this structure (location_id matches structure itemId)
            $fittedAssets = $assetRepo->findBy(['locationId' => $structId]);
            $rigType = 'none';
            foreach ($fittedAssets as $asset) {
                $typeId = $asset->getTypeId();
                if ($typeId === 46490) {
                    $rigType = 't1';
                    break;
                } elseif ($typeId === 46491) {
                    $rigType = 't2';
                    break;
                }
            }

            $structuresList[] = [
                'id' => $struct->getId(),
                'name' => $name,
                'solarSystemId' => $struct->getSolarSystemId(),
                'solarSystemName' => $systemName,
                'structureType' => $structType,
                'rigType' => $rigType,
            ];
        }

        return $this->render('general/reactions/index.html.twig', [
            'structuresList' => $structuresList,
        ]);
    }

    #[Route('/data', name: 'app_general_reactions_data', methods: ['GET'])]
    public function getReactionData(): JsonResponse
    {
        $data = $this->reactionPriceService->getReactionCalculatorData();
        return new JsonResponse($data);
    }
}
