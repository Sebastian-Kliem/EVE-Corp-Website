<?php

namespace App\Service;

use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Corp hangar divisions a member may look into, as configured under Admin > Hangar-Sichtbarkeit.
 */
class CorpHangarVisibilityService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * @return array<int|string, array<string, true>> station/structure ID => hangar flag => true
     */
    public function getVisibleHangars(User $user): array
    {
        $visibleHangars = [];
        $visibilities = $this->entityManager->getRepository(CorpAssetVisibility::class)->findBy(['isVisible' => true]);
        foreach ($visibilities as $visibility) {
            if ($this->_isAllowedFor($visibility, $user)) {
                $visibleHangars[$visibility->getLocationId()][$visibility->getLocationFlag()] = true;
            }
        }

        return $visibleHangars;
    }

    private function _isAllowedFor(CorpAssetVisibility $visibility, User $user): bool
    {
        $allowedUsers = $visibility->getUsers();
        if ($allowedUsers->isEmpty()) {
            return true;
        }

        foreach ($allowedUsers as $allowedUser) {
            if ($allowedUser->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
