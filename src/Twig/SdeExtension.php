<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\JwtService;
use App\Service\SdeService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class SdeExtension extends AbstractExtension
{
    public function __construct(
        private readonly SdeService $sdeService,
        private readonly JwtService $jwtService
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('eve_item_name', [$this, 'getEveItemName']),
            new TwigFilter('eve_item_icon', [$this, 'getEveItemIcon'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jwt_token', [$this, 'getJwtToken']),
        ];
    }

    /**
     * Generates a signed JWT token for the given user.
     */
    public function getJwtToken(?User $user): ?string
    {
        if (!$user) {
            return null;
        }
        return $this->jwtService->createToken($user);
    }

    /**
     * Translates a numeric item ID into the actual item name using the SdeService.
     */
    public function getEveItemName(mixed $itemId): string
    {
        return $this->sdeService->getItemName($itemId);
    }

    /**
     * Renders a sleek HTML image tag for the EVE Online item icon using CCP's image server.
     * If the itemId is not numeric (legacy data), it returns an empty string.
     */
    public function getEveItemIcon(mixed $itemId, int $displaySize = 32): string
    {
        if (empty($itemId) || !is_numeric($itemId)) {
            return '';
        }

        $itemId = (int)$itemId;

        // Determine correct variation based on item group
        $variation = 'icon';
        if ($this->sdeService->isBlueprint($itemId)) {
            $variation = 'bp';
        }

        // The CCP Image Server only accepts powers of two (e.g. 32, 64, 128, 256, 512)
        // We find the best matching power of two that is greater or equal to displaySize
        $validSizes = [32, 64, 128, 256, 512];
        $requestSize = 32; // Default fallback
        foreach ($validSizes as $size) {
            if ($size >= $displaySize) {
                $requestSize = $size;
                break;
            }
        }

        $url = sprintf('/eve/image/types/%d/%s?size=%d', $itemId, $variation, $requestSize);

        return sprintf(
            '<img src="%s" style="width: %dpx; height: %dpx; vertical-align: middle; border-radius: 4px; margin-right: 8px;" alt="Icon" loading="lazy">',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            $displaySize,
            $displaySize
        );
    }
}
