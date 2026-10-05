<?php

namespace App\Controller\General;

use App\Service\SdeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SolarSystemSearchController extends AbstractController
{
    private const MAX_RESULTS = 15;

    // Autocomplete source for route target systems (session auth, used by Twig forms)
    #[Route('/general/solar-systems/search', name: 'app_solar_system_search', methods: ['GET'])]
    #[IsGranted('ROLE_MEMBER')]
    public function search(Request $request, SdeService $sdeService): JsonResponse
    {
        $query = trim((string)$request->query->get('q', ''));
        if (mb_strlen($query) < 1) {
            return new JsonResponse([]);
        }

        return new JsonResponse($sdeService->searchKSpaceSolarSystems($query, self::MAX_RESULTS));
    }
}
