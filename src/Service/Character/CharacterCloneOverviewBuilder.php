<?php

namespace App\Service\Character;

use App\Entity\EveCharacter;
use App\Service\LocationService;
use App\Service\SdeService;

/**
 * Resolves the stored ESI clones answer into names for the character details page.
 */
class CharacterCloneOverviewBuilder
{
    private const INFOMORPH_SYNCHRONIZING_SKILL_ID = 33399;
    private const BASE_JUMP_COOLDOWN_HOURS = 24;

    public function __construct(
        private readonly LocationService $locationService,
        private readonly SdeService $sdeService
    ) {}

    public function build(EveCharacter $character, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $clones = $character->getClones();
        if ($clones === []) {
            return ['hasData' => false, 'homeLocation' => null, 'jumpClones' => [], 'lastJumpAt' => null, 'nextJumpAt' => null];
        }

        $jumpClones = [];
        foreach ($clones['jump_clones'] ?? [] as $jumpClone) {
            $jumpClones[] = [
                'name' => $jumpClone['name'] ?? null,
                'location' => $this->_resolveLocationName($jumpClone['location_id'] ?? null, $character),
                'implants' => $this->_resolveImplantNames($jumpClone['implants'] ?? []),
            ];
        }

        $lastJumpAt = isset($clones['last_clone_jump_date']) ? new \DateTimeImmutable($clones['last_clone_jump_date']) : null;

        return [
            'hasData' => true,
            'homeLocation' => $this->_resolveLocationName($clones['home_location']['location_id'] ?? null, $character),
            'jumpClones' => $jumpClones,
            'lastJumpAt' => $lastJumpAt,
            'nextJumpAt' => $this->_getNextJumpAt($character, $lastJumpAt, $now),
        ];
    }

    // Null when a jump is possible right now
    private function _getNextJumpAt(EveCharacter $character, ?\DateTimeImmutable $lastJumpAt, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($lastJumpAt === null) {
            return null;
        }

        $cooldownHours = self::BASE_JUMP_COOLDOWN_HOURS - $character->getSkillLevel(self::INFOMORPH_SYNCHRONIZING_SKILL_ID);
        $nextJumpAt = $lastJumpAt->modify(sprintf('+%d hours', $cooldownHours));

        return $nextJumpAt > $now ? $nextJumpAt : null;
    }

    private function _resolveLocationName(mixed $locationId, EveCharacter $character): ?string
    {
        if (!is_numeric($locationId)) {
            return null;
        }

        return $this->locationService->resolveLocation((int) $locationId, $character)['name'];
    }

    private function _resolveImplantNames(array $implantTypeIds): array
    {
        $implantNames = [];
        foreach ($implantTypeIds as $implantTypeId) {
            $implantNames[] = $this->sdeService->getItemName($implantTypeId);
        }

        return $implantNames;
    }
}
