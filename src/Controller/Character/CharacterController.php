<?php

namespace App\Controller\Character;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterMarketOrder;
use App\Entity\User;
use App\Service\Character\CharacterListBuilder;
use App\Service\LocationService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only views of the user's characters: overview, details and assets.
 */
#[Route('/characters')]
#[IsGranted('ROLE_MEMBER')]
class CharacterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_profile_characters', methods: ['GET'])]
    public function characters(CharacterListBuilder $characterListBuilder): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('character/overview.html.twig', $characterListBuilder->build($currentUser));
    }

    #[Route('/{id}/assets', name: 'app_dashboard_eve_character_assets', methods: ['GET'])]
    public function showAssets(int $id, SdeService $sdeService, LocationService $locationService): Response
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        $assets = $this->entityManager->getRepository(EveCharacterAsset::class)->findBy(
            ['character' => $character],
            ['locationId' => 'ASC']
        );

        $assets = array_filter($assets, function (EveCharacterAsset $a) {
            return $a->getLocationType() !== 'industry_job' && $a->getLocationType() !== 'personal_corp_asset';
        });

        // Add active sell orders as pseudo assets
        $marketOrders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
            'character' => $character,
            'isBuy' => false
        ]);
        foreach ($marketOrders as $order) {
            $pseudoAsset = new EveCharacterAsset();
            $pseudoAsset->setCharacter($character);
            $pseudoAsset->setItemId((int)$order->getOrderId());
            $pseudoAsset->setTypeId($order->getTypeId());
            $pseudoAsset->setQuantity($order->getVolumeRemain());
            $pseudoAsset->setLocationId((int)$order->getLocationId());
            $pseudoAsset->setLocationType('station');
            $pseudoAsset->setLocationFlag('MarketOrder');
            $pseudoAsset->setIsSingleton(false);
            $pseudoAsset->setCustomName('[Markt-Verkaufsorder]');
            $assets[] = $pseudoAsset;
        }

        // Group assets by location
        $groupedAssets = [];
        foreach ($assets as $asset) {
            $locationId = $asset->getLocationId();
            if (!isset($groupedAssets[$locationId])) {
                $resolved = $locationService->resolveLocation($locationId, $character);
                $groupedAssets[$locationId] = [
                    'name' => $resolved['name'],
                    'items' => [],
                ];
            }

            $groupedAssets[$locationId]['items'][] = [
                'typeId' => $asset->getTypeId(),
                'name' => $sdeService->getItemName($asset->getTypeId()),
                'customName' => $asset->getCustomName(),
                'quantity' => $asset->getQuantity(),
                'locationFlag' => $asset->getLocationFlag(),
                'isBlueprintCopy' => $asset->isBlueprintCopy(),
                'isBlueprint' => $sdeService->isBlueprint($asset->getTypeId()),
                'isSingleton' => $asset->isSingleton(),
            ];
        }

        // Sort items inside each location (ships first, then containers, then others)
        foreach ($groupedAssets as &$group) {
            usort($group['items'], function ($a, $b) use ($sdeService) {
                $aIsShip = $sdeService->isShip($a['typeId']);
                $bIsShip = $sdeService->isShip($b['typeId']);
                if ($aIsShip && !$bIsShip) return -1;
                if (!$aIsShip && $bIsShip) return 1;

                $aIsContainer = $sdeService->isContainer($a['typeId']);
                $bIsContainer = $sdeService->isContainer($b['typeId']);
                if ($aIsContainer && !$bIsContainer) return -1;
                if (!$aIsContainer && $bIsContainer) return 1;

                return strcasecmp($a['name'], $b['name']);
            });
        }
        unset($group);

        // Sort groups by location name
        uasort($groupedAssets, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->render('character/assets.html.twig', [
            'character' => $character,
            'groupedAssets' => $groupedAssets,
        ]);
    }

    #[Route('/{id}/details', name: 'app_dashboard_eve_character_details', methods: ['GET'])]
    public function showDetails(
        int $id,
        SdeService $sdeService,
        LocationService $locationService,
        \App\Service\Esi\EsiClient $esiClient
    ): Response {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        //todo save corpname and alliancename to db

        // Fetch corporation and alliance names from ESI
        $corporationName = null;
        if ($character->getCorporationId()) {
            try {
                $corpData = $esiClient->request('GET', sprintf('corporations/%d/', $character->getCorporationId()));
                $corporationName = $corpData['name'] ?? null;
            } catch (\Exception $e) {
                // Keep null
            }
        }

        $allianceName = null;
        if ($character->getAllianceId()) {
            try {
                $allianceData = $esiClient->request('GET', sprintf('alliances/%d/', $character->getAllianceId()));
                $allianceName = $allianceData['name'] ?? null;
            } catch (\Exception $e) {
                // Keep null
            }
        }

        // Fetch active ship and current location from ESI if token is valid
        $currentLocation = null;
        if ($character->isTokenValid()) {
            try {
                $locData = $esiClient->request('GET', sprintf('characters/%d/location/', $character->getId()), [], $character);
                if (isset($locData['solar_system_id'])) {
                    $resolved = $locationService->resolveLocation($locData['solar_system_id'], $character);
                    $currentLocation = $resolved['name'];
                }
            } catch (\Exception $e) {

            }
        }

        // Resolve implant names
        $implantNames = [];
        foreach ($character->getImplants() as $typeId) {
            $implantNames[$typeId] = $sdeService->getItemName($typeId);
        }

        // Resolve skill names in queue
        $skillNames = [];
        foreach ($character->getSkillQueue() as $item) {
            if (isset($item['skill_id'])) {
                $skillNames[$item['skill_id']] = $sdeService->getItemName($item['skill_id']);
            }
        }

        return $this->render('character/details.html.twig', [
            'character' => $character,
            'corporationName' => $corporationName,
            'allianceName' => $allianceName,
            'currentLocation' => $currentLocation,
            'implantNames' => $implantNames,
            'skillNames' => $skillNames,
        ]);
    }
}
