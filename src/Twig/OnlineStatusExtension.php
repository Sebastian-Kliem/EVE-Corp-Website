<?php

namespace App\Twig;

use App\Service\CharacterOnlineService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class OnlineStatusExtension extends AbstractExtension
{
    public function __construct(
        private readonly CharacterOnlineService $characterOnlineService
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_online_status', [$this, 'getOnlineStatus']),
        ];
    }

    /**
     * @return array{userCount: int, characterCount: int, users: array<int, mixed>}
     */
    public function getOnlineStatus(): array
    {
        return $this->characterOnlineService->getOnlineStatus();
    }
}
