<?php

namespace App\Service;

use App\Entity\EveCharacter;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves the names of a corporation's hangar divisions (CorpSAG1-7) via ESI.
 */
class CorpDivisionService
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * @return array<int, string> Division number => name, empty if ESI is not accessible
     */
    public function getHangarDivisionNames(int $corporationId, ?EveCharacter $character = null): array
    {
        $character ??= $this->findSyncCharacter($corporationId);
        if ($character === null) {
            return [];
        }

        $divisionNames = [];
        try {
            $divisionData = $this->esiClient->request('GET', sprintf('corporations/%d/divisions/', $corporationId), [], $character);
            if (isset($divisionData['hangar']) && is_array($divisionData['hangar'])) {
                foreach ($divisionData['hangar'] as $division) {
                    $name = (string)($division['name'] ?? '');
                    // Same normalization as the corp asset views: strip a trailing number from custom names
                    if (!preg_match('/^Hangar\s*\d+$/ui', $name)) {
                        $name = (string)preg_replace('/\s*\d+$/u', '', $name);
                    }
                    $divisionNames[(int)$division['division']] = $name;
                }
            }
        } catch (\Exception $e) {
            // Division names are optional, callers fall back to "Hangar N"
        }

        return $divisionNames;
    }

    /**
     * The character that synced the corp assets last; it has the roles needed for corp endpoints.
     */
    public function findSyncCharacter(int $corporationId): ?EveCharacter
    {
        return $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
            ->where('c.corporationId = :corpId')
            ->andWhere('c.lastCorpAssetsUpdate IS NOT NULL')
            ->setParameter('corpId', $corporationId)
            ->orderBy('c.lastCorpAssetsUpdate', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
