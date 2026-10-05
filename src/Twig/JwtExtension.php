<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\JwtService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class JwtExtension extends AbstractExtension
{
    public function __construct(
        private readonly JwtService $jwtService
    ) {}

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
}
