<?php

namespace App\Service\Esi;

use App\Entity\EveCharacter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the characters that may read a corporation endpoint, based on their in-game roles and granted token scopes.
 */
class CorporationAccessResolver
{
    // Directors hold every corporation role implicitly
    private const DIRECTOR_ROLE = 'Director';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
    ) {}

    /**
     * Returns usable characters grouped by corporation ID, directors first.
     *
     * @param string[] $acceptedRoles
     * @return array<int, EveCharacter[]>
     */
    public function getCharactersByCorporation(array $acceptedRoles, string $requiredScope): array
    {
        /** @var EveCharacter[] $characters */
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['tokenValid' => true], ['id' => \SortDirection::Ascending]);

        $charactersByCorporation = [];
        foreach ($characters as $character) {
            if (!$this->_canAccess($character, $acceptedRoles, $requiredScope)) {
                continue;
            }
            $charactersByCorporation[$character->getCorporationId()][] = $character;
        }

        foreach ($charactersByCorporation as $corporationId => $corporationCharacters) {
            $charactersByCorporation[$corporationId] = $this->_sortDirectorsFirst($corporationCharacters);
        }

        return $charactersByCorporation;
    }

    private function _canAccess(EveCharacter $character, array $acceptedRoles, string $requiredScope): bool
    {
        if (empty($character->getRefreshToken()) || !$character->getCorporationId() || $character->isInNpcCorporation()) {
            return false;
        }

        return $this->_hasAnyRole($character, $acceptedRoles) && $this->esiClient->hasScope($character, $requiredScope);
    }

    private function _hasAnyRole(EveCharacter $character, array $acceptedRoles): bool
    {
        foreach ($character->getRoles() as $role) {
            if ($role === self::DIRECTOR_ROLE || in_array($role, $acceptedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param EveCharacter[] $characters
     * @return EveCharacter[]
     */
    private function _sortDirectorsFirst(array $characters): array
    {
        $directors = [];
        $others = [];
        foreach ($characters as $character) {
            if ($character->isDirector()) {
                $directors[] = $character;
            } else {
                $others[] = $character;
            }
        }

        return array_merge($directors, $others);
    }
}
