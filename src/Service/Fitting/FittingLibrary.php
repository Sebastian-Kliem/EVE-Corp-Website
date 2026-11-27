<?php

namespace App\Service\Fitting;

use App\Entity\Fitting\Fitting;
use App\Entity\User;
use App\Repository\FittingRepository;
use App\Repository\FleetCompositionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, changes and deletes library fittings while keeping them unique by content.
 */
class FittingLibrary
{
    private const MAX_TAGS = 20;
    private const MAX_TAG_LENGTH = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FittingRepository $fittingRepository,
        private readonly FleetCompositionRepository $fleetRepository,
        private readonly FittingContentBuilder $contentBuilder,
        private readonly FittingHasher $hasher,
    ) {}

    /**
     * Returns the existing fitting when the same content is already in the library.
     *
     * @return array{fitting: Fitting, created: bool, warnings: list<string>}
     * @throws FittingBuildException
     */
    public function createFromEft(string $eft, ?User $creator, ?string $name = null, ?string $description = null): array
    {
        $content = $this->contentBuilder->buildFromEft($eft);
        $fittingName = $this->_firstNonEmpty($name, $content['name'], $content['shipName']);

        $fitting = $this->findOrCreateFromContent($content['shipTypeId'], $content['items'], $fittingName, $description, $creator, $created);

        return ['fitting' => $fitting, 'created' => $created, 'warnings' => $content['warnings']];
    }

    /**
     * @param list<array{typeId: int, flag: string, quantity: int}> $items
     */
    public function findOrCreateFromContent(int $shipTypeId, array $items, string $name, ?string $description, ?User $creator, ?bool &$created = null): Fitting
    {
        $contentHash = $this->hasher->hash($shipTypeId, $items);
        $existingFitting = $this->fittingRepository->findOneByContentHash($contentHash);
        if ($existingFitting !== null) {
            $created = false;
            return $existingFitting;
        }

        $fitting = new Fitting();
        $fitting->setName($name);
        $fitting->setDescription($description);
        $fitting->setContent($shipTypeId, $items, $contentHash);
        $fitting->setCreatedBy($creator);
        $this->entityManager->persist($fitting);
        $this->entityManager->flush();
        $created = true;

        return $fitting;
    }

    /**
     * @return list<string> warnings
     * @throws FittingBuildException
     */
    public function updateContentFromEft(Fitting $fitting, string $eft): array
    {
        $content = $this->contentBuilder->buildFromEft($eft);
        $contentHash = $this->hasher->hash($content['shipTypeId'], $content['items']);

        $existingFitting = $this->fittingRepository->findOneByContentHash($contentHash);
        if ($existingFitting !== null && $existingFitting->getId() !== $fitting->getId()) {
            throw new FittingBuildException(sprintf('Dieses Fit existiert bereits als "%s".', $existingFitting->getName()));
        }

        $fitting->setContent($content['shipTypeId'], $content['items'], $contentHash);

        return $content['warnings'];
    }

    /**
     * Trims, deduplicates (case-insensitive) and limits tags.
     *
     * @param array<mixed> $rawTags
     * @return list<string>
     */
    public function normalizeTags(array $rawTags): array
    {
        $tags = [];
        foreach ($rawTags as $rawTag) {
            $tag = mb_substr(trim((string) $rawTag), 0, self::MAX_TAG_LENGTH);
            if ($tag === '') {
                continue;
            }
            $tags[mb_strtolower($tag)] ??= $tag;
        }

        return array_slice(array_values($tags), 0, self::MAX_TAGS);
    }

    /**
     * @throws FittingBuildException when the fitting is still used by a fleet
     */
    public function delete(Fitting $fitting): void
    {
        $fleetNames = $this->fleetRepository->findFleetNamesUsingFitting($fitting);
        if (!empty($fleetNames)) {
            throw new FittingBuildException(sprintf('Das Fit wird noch in Flotten verwendet: %s', implode(', ', $fleetNames)));
        }

        $this->entityManager->remove($fitting);
        $this->entityManager->flush();
    }

    private function _firstNonEmpty(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Fit';
    }
}
