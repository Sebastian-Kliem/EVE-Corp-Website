<?php

namespace App\Controller\General;

use App\Service\CharacterOnlineService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OnlineStatusController extends AbstractController
{
    #[Route('/online-status', name: 'app_online_status', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function getOnlineStatus(CharacterOnlineService $characterOnlineService): JsonResponse
    {
        $status = $characterOnlineService->getOnlineStatus();
        return new JsonResponse($status);
    }
}
