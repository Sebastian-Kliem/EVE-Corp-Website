<?php

namespace App\Service\Fitting;

use App\Entity\EveCharacter;
use App\Entity\Fitting\Fitting;
use App\Entity\Fitting\FittingIngameCopy;
use App\Repository\FittingIngameCopyRepository;
use App\Repository\FittingRepository;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Imports the personal in-game fittings of characters and writes fittings back to the game via ESI.
 */
class FittingIngameSync
{
    public const READ_SCOPE = 'esi-fittings.read_fittings.v1';
    public const WRITE_SCOPE = 'esi-fittings.write_fittings.v1';

    private const MAX_NAME_LENGTH = 50;
    private const MAX_DESCRIPTION_LENGTH = 500;
    private const DEFAULT_DESCRIPTION = 'WH-Toolbox';
    // Copies pushed recently survive an import that still sees a cached ESI list
    private const FRESH_COPY_GRACE_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly FittingLibrary $library,
        private readonly FittingHasher $hasher,
        private readonly FittingRepository $fittingRepository,
        private readonly FittingIngameCopyRepository $copyRepository,
    ) {}

    public function canRead(EveCharacter $character): bool
    {
        return $this->esiClient->hasScope($character, self::READ_SCOPE);
    }

    public function canWrite(EveCharacter $character): bool
    {
        return $this->esiClient->hasScope($character, self::WRITE_SCOPE);
    }

    /**
     * @return array{total: int, created: int, removed: int}
     */
    public function importForCharacter(EveCharacter $character): array
    {
        $ingameFits = $this->esiClient->request('GET', $this->_fittingsPath($character), [], $character);
        if (!is_array($ingameFits)) {
            $ingameFits = [];
        }

        $copiesByEsiId = $this->copyRepository->findByCharacterIndexedByEsiId($character);
        $now = new \DateTimeImmutable();
        $seenEsiIds = [];
        $createdCount = 0;

        foreach ($ingameFits as $ingameFit) {
            $esiFittingId = (int) ($ingameFit['fitting_id'] ?? 0);
            $shipTypeId = (int) ($ingameFit['ship_type_id'] ?? 0);
            if ($esiFittingId === 0 || $shipTypeId === 0) {
                continue;
            }

            $created = false;
            $copy = $this->_syncIngameFit($character, $ingameFit, $copiesByEsiId[$esiFittingId] ?? null, $created);
            $copy->setSyncedAt($now);
            $seenEsiIds[$esiFittingId] = true;
            $createdCount += $created === true ? 1 : 0;
        }

        $removedCount = $this->_removeVanishedCopies($copiesByEsiId, $seenEsiIds, $now);
        $this->entityManager->flush();

        return ['total' => count($seenEsiIds), 'created' => $createdCount, 'removed' => $removedCount];
    }

    /**
     * @throws FittingBuildException
     */
    public function saveToGame(Fitting $fitting, EveCharacter $character): FittingIngameCopy
    {
        foreach ($fitting->getIngameCopies() as $existingCopy) {
            if ($existingCopy->getCharacter()->getId() === $character->getId()) {
                throw new FittingBuildException(sprintf('Das Fit ist bei %s bereits ingame gespeichert.', $character->getName()));
            }
        }

        $esiFittingId = $this->_pushToGame($fitting, $character);

        $copy = new FittingIngameCopy($fitting, $character, $esiFittingId);
        $copy->setIngameName(mb_substr($fitting->getName(), 0, self::MAX_NAME_LENGTH));
        $copy->setContentHash($fitting->getContentHash());
        $fitting->getIngameCopies()->add($copy);
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    /**
     * Replaces an outdated in-game copy with the current library content.
     *
     * @throws FittingBuildException
     */
    public function updateInGame(FittingIngameCopy $copy): void
    {
        $fitting = $copy->getFitting();
        $character = $copy->getCharacter();
        $newEsiFittingId = $this->_pushToGame($fitting, $character);
        $this->_deleteIngameFit($character, $copy->getEsiFittingId());

        $copy->setEsiFittingId($newEsiFittingId);
        $copy->setIngameName(mb_substr($fitting->getName(), 0, self::MAX_NAME_LENGTH));
        $copy->setContentHash($fitting->getContentHash());
        $copy->setSyncedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * @throws FittingBuildException
     */
    public function deleteFromGame(FittingIngameCopy $copy): void
    {
        $this->_deleteIngameFit($copy->getCharacter(), $copy->getEsiFittingId());

        $copy->getFitting()->getIngameCopies()->removeElement($copy);
        $this->entityManager->remove($copy);
        $this->entityManager->flush();
    }

    private function _syncIngameFit(EveCharacter $character, array $ingameFit, ?FittingIngameCopy $copy, ?bool &$created): FittingIngameCopy
    {
        $shipTypeId = (int) $ingameFit['ship_type_id'];
        $items = $this->_normalizeItems($ingameFit['items'] ?? []);
        $contentHash = $this->hasher->hash($shipTypeId, $items);
        $ingameName = trim((string) ($ingameFit['name'] ?? '')) ?: 'Fit';

        if ($copy === null) {
            $description = trim((string) ($ingameFit['description'] ?? ''));
            $fitting = $this->library->findOrCreateFromContent($shipTypeId, $items, $ingameName, $description !== '' ? $description : null, $character->getUser(), $created);
            $copy = new FittingIngameCopy($fitting, $character, (int) $ingameFit['fitting_id']);
            $fitting->getIngameCopies()->add($copy);
            $this->entityManager->persist($copy);
        } elseif ($copy->getFitting()->getContentHash() !== $contentHash) {
            // Re-attach to a matching library fit; otherwise the copy stays as outdated
            $matchingFitting = $this->fittingRepository->findOneByContentHash($contentHash);
            if ($matchingFitting !== null) {
                $copy->getFitting()->getIngameCopies()->removeElement($copy);
                $copy->setFitting($matchingFitting);
                $matchingFitting->getIngameCopies()->add($copy);
            }
        }

        $copy->setIngameName($ingameName);
        $copy->setContentHash($contentHash);

        return $copy;
    }

    /**
     * @param array<int, FittingIngameCopy> $copiesByEsiId
     * @param array<int, bool> $seenEsiIds
     */
    private function _removeVanishedCopies(array $copiesByEsiId, array $seenEsiIds, \DateTimeImmutable $now): int
    {
        $removedCount = 0;
        foreach ($copiesByEsiId as $esiFittingId => $copy) {
            if (isset($seenEsiIds[$esiFittingId])) {
                continue;
            }
            if ($now->getTimestamp() - $copy->getSyncedAt()->getTimestamp() < self::FRESH_COPY_GRACE_SECONDS) {
                continue;
            }
            $copy->getFitting()->getIngameCopies()->removeElement($copy);
            $this->entityManager->remove($copy);
            $removedCount++;
        }

        return $removedCount;
    }

    /**
     * @return list<array{typeId: int, flag: string, quantity: int}>
     */
    private function _normalizeItems(array $esiItems): array
    {
        $items = [];
        foreach ($esiItems as $esiItem) {
            $typeId = (int) ($esiItem['type_id'] ?? 0);
            if ($typeId === 0) {
                continue;
            }
            $items[] = [
                'typeId' => $typeId,
                'flag' => FittingSlots::normalize($esiItem['flag'] ?? FittingSlots::CARGO),
                'quantity' => max(1, (int) ($esiItem['quantity'] ?? 1)),
            ];
        }

        return $items;
    }

    /**
     * @throws FittingBuildException
     */
    private function _pushToGame(Fitting $fitting, EveCharacter $character): int
    {
        if (!$this->canWrite($character)) {
            throw new FittingBuildException(sprintf('%s fehlt die Berechtigung zum Speichern von Fits (Re-Auth nötig).', $character->getName()));
        }

        $items = [];
        foreach ($fitting->getItems() as $item) {
            $items[] = ['type_id' => $item['typeId'], 'flag' => $item['flag'], 'quantity' => $item['quantity']];
        }
        $description = trim((string) $fitting->getDescription());

        $response = $this->_callEsi('POST', $this->_fittingsPath($character), [
            'json' => [
                'name' => mb_substr($fitting->getName(), 0, self::MAX_NAME_LENGTH),
                'description' => mb_substr($description !== '' ? $description : self::DEFAULT_DESCRIPTION, 0, self::MAX_DESCRIPTION_LENGTH),
                'ship_type_id' => $fitting->getShipTypeId(),
                'items' => $items,
            ],
        ], $character);

        $esiFittingId = (int) ($response['fitting_id'] ?? 0);
        if ($esiFittingId === 0) {
            throw new FittingBuildException('ESI hat keine Fitting-ID zurückgegeben.');
        }

        return $esiFittingId;
    }

    /**
     * @throws FittingBuildException
     */
    private function _deleteIngameFit(EveCharacter $character, int $esiFittingId): void
    {
        if (!$this->canWrite($character)) {
            throw new FittingBuildException(sprintf('%s fehlt die Berechtigung zum Löschen von Fits (Re-Auth nötig).', $character->getName()));
        }

        try {
            $this->esiClient->request('DELETE', sprintf('%s%d/', $this->_fittingsPath($character), $esiFittingId), [], $character);
        } catch (HttpExceptionInterface $exception) {
            // Already deleted in game
            if ($exception->getResponse()->getStatusCode() !== 404) {
                throw new FittingBuildException('ESI-Fehler beim Löschen: ' . $exception->getMessage(), 0, $exception);
            }
        } catch (\RuntimeException $exception) {
            throw new FittingBuildException('ESI ist gerade nicht erreichbar. Bitte später erneut versuchen.', 0, $exception);
        } finally {
            $this->esiClient->forgetCachedResponse($this->_fittingsPath($character), [], $character);
        }
    }

    /**
     * @throws FittingBuildException
     */
    private function _callEsi(string $method, string $path, array $options, EveCharacter $character): mixed
    {
        try {
            // No retries: a repeated POST could create the fit twice
            return $this->esiClient->requestWithHeaders($method, $path, $options, $character, 1)['data'];
        } catch (HttpExceptionInterface $exception) {
            throw new FittingBuildException('ESI-Fehler: ' . $exception->getMessage(), 0, $exception);
        } catch (\RuntimeException $exception) {
            throw new FittingBuildException('ESI ist gerade nicht erreichbar. Bitte später erneut versuchen.', 0, $exception);
        } finally {
            $this->esiClient->forgetCachedResponse($this->_fittingsPath($character), [], $character);
        }
    }

    private function _fittingsPath(EveCharacter $character): string
    {
        return sprintf('characters/%d/fittings/', $character->getId());
    }
}
