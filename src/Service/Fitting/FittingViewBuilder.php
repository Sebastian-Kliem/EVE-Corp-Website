<?php

namespace App\Service\Fitting;

use App\Entity\EveCharacter;
use App\Entity\Fitting\Fitting;
use App\Entity\Fitting\FleetComposition;
use App\Entity\User;
use App\Repository\FittingRepository;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Builds the JSON props of the fitting library and fleet pages.
 */
class FittingViewBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FittingRepository $fittingRepository,
        private readonly FittingSkillChecker $skillChecker,
        private readonly FittingIngameSync $ingameSync,
        private readonly EftFormatter $eftFormatter,
        private readonly FittingContentBuilder $contentBuilder,
        private readonly FittingHasher $hasher,
        private readonly SdeService $sdeService,
        private readonly Security $security,
        private readonly DefenseDoctrineFleet $doctrineFleet,
    ) {}

    /**
     * @return array{fittings: list<array>, characters: list<array>, users: list<array>, ownCharacters: list<array>, currentUserId: ?int}
     */
    public function buildLibrary(User $currentUser): array
    {
        $fittings = $this->fittingRepository->findAllWithCopies();
        $characters = $this->_findMemberCharacters();

        return [
            'fittings' => $this->summarizeFittings($fittings, $characters, $currentUser),
            'characters' => $this->_serializeCharacters($characters),
            'users' => $this->_serializeUsers($characters),
            'ownCharacters' => $this->_serializeOwnCharacters($characters, $currentUser),
            'currentUserId' => $currentUser->getId(),
        ];
    }

    /**
     * @param Fitting[] $fittings
     * @param EveCharacter[]|null $characters
     * @return list<array>
     */
    public function summarizeFittings(array $fittings, ?array $characters, User $currentUser): array
    {
        $characters ??= $this->_findMemberCharacters();
        $pilotsByFitting = $this->skillChecker->findPilots($fittings, $characters);

        $shipTypeIds = [];
        foreach ($fittings as $fitting) {
            $shipTypeIds[] = $fitting->getShipTypeId();
        }
        $shipDetails = $this->sdeService->getItemsDetails($shipTypeIds);

        $summaries = [];
        foreach ($fittings as $fitting) {
            $summaries[] = $this->_summarizeFitting($fitting, $shipDetails[$fitting->getShipTypeId()] ?? [], $pilotsByFitting[(int) $fitting->getId()] ?? [], $currentUser);
        }

        return $summaries;
    }

    /**
     * Slot layout, EFT, skill requirements and the skill gaps of the user's own characters.
     */
    public function buildDetail(Fitting $fitting, User $currentUser): array
    {
        $requirements = $this->skillChecker->getRequirements([$fitting])[(int) $fitting->getId()] ?? [];

        return [
            'id' => $fitting->getId(),
            'eft' => $this->eftFormatter->format($fitting),
            'slots' => $this->_buildSlots($fitting->getItems()),
            'requiredSkills' => $this->_serializeRequirements($requirements),
            'ownCharacterSkills' => $this->_buildOwnSkillStatus($requirements, $currentUser),
        ];
    }

    /**
     * Parses EFT without saving; reports an existing library fit with the same content.
     *
     * @throws FittingBuildException
     */
    public function buildPreview(string $eft): array
    {
        $content = $this->contentBuilder->buildFromEft($eft);
        $existingFitting = $this->fittingRepository->findOneByContentHash($this->hasher->hash($content['shipTypeId'], $content['items']));

        return [
            'shipTypeId' => $content['shipTypeId'],
            'shipName' => $content['shipName'],
            'name' => $content['name'],
            'slots' => $this->_buildSlots($content['items']),
            'warnings' => $content['warnings'],
            'existingFitting' => $existingFitting !== null ? ['id' => $existingFitting->getId(), 'name' => $existingFitting->getName()] : null,
        ];
    }

    /**
     * @param FleetComposition[] $fleets
     * @return list<array>
     */
    public function serializeFleets(array $fleets, User $currentUser): array
    {
        $serializedFleets = [];
        foreach ($fleets as $fleet) {
            $entries = [];
            foreach ($fleet->getEntries() as $entry) {
                $entries[] = [
                    'id' => $entry->getId(),
                    'fittingId' => $entry->getFitting()->getId(),
                    'role' => $entry->getRole() ?? '',
                    'quantity' => $entry->getQuantity(),
                    'notes' => $entry->getNotes() ?? '',
                ];
            }

            $serializedFleets[] = [
                'id' => $fleet->getId(),
                'name' => $fleet->getName(),
                'description' => $fleet->getDescription() ?? '',
                'createdByName' => $fleet->getCreatedBy()?->getDisplayName(),
                'updatedAt' => $fleet->getUpdatedAt()->format('d.m.Y H:i'),
                'canEdit' => $this->canEdit($fleet->getCreatedBy(), $currentUser),
                'isDefenseDoctrine' => $this->doctrineFleet->isDoctrineFleet($fleet),
                'entries' => $entries,
            ];
        }

        return $serializedFleets;
    }

    public function canEdit(?User $owner, User $currentUser): bool
    {
        return $this->security->isGranted('ROLE_OFFICER') || ($owner !== null && $owner->getId() === $currentUser->getId());
    }

    private function _summarizeFitting(Fitting $fitting, array $shipDetail, array $pilotIds, User $currentUser): array
    {
        $copies = [];
        foreach ($fitting->getIngameCopies() as $copy) {
            $character = $copy->getCharacter();
            $copies[] = [
                'id' => $copy->getId(),
                'characterId' => $character->getId(),
                'characterName' => $character->getName(),
                'userId' => $character->getUser()?->getId(),
                'ingameName' => $copy->getIngameName(),
                'outdated' => $copy->isOutdated(),
                'isOwn' => $character->getUser()?->getId() === $currentUser->getId(),
            ];
        }

        return [
            'id' => $fitting->getId(),
            'name' => $fitting->getName(),
            'description' => $fitting->getDescription() ?? '',
            'shipTypeId' => $fitting->getShipTypeId(),
            'shipName' => $shipDetail['name'] ?? ('#' . $fitting->getShipTypeId()),
            'shipGroup' => $shipDetail['groupName'] ?? '',
            'tags' => $fitting->getTags(),
            'corpSaved' => $fitting->isCorpSaved(),
            'createdById' => $fitting->getCreatedBy()?->getId(),
            'createdByName' => $fitting->getCreatedBy()?->getDisplayName(),
            'updatedAt' => $fitting->getUpdatedAt()->format('d.m.Y H:i'),
            'canEdit' => $this->canEdit($fitting->getCreatedBy(), $currentUser),
            'copies' => $copies,
            'pilotIds' => $pilotIds,
        ];
    }

    /**
     * @param list<array{typeId: int, flag: string, quantity: int}> $items
     * @return array<string, list<array{typeId: int, name: string, quantity: int, slotIndex: int}>>
     */
    private function _buildSlots(array $items): array
    {
        $slots = [];
        foreach ($items as $item) {
            $slotGroup = FittingSlots::groupOf($item['flag']);
            $slots[$slotGroup][] = [
                'typeId' => $item['typeId'],
                'name' => $this->sdeService->getItemName($item['typeId']),
                'quantity' => $item['quantity'],
                'slotIndex' => FittingSlots::slotIndex($item['flag']),
            ];
        }

        foreach ($slots as $slotGroup => $slotItems) {
            usort($slotItems, static fn (array $left, array $right): int => $left['slotIndex'] <=> $right['slotIndex']);
            $slots[$slotGroup] = $slotItems;
        }

        return $slots;
    }

    /**
     * @param array<int, int> $requirements
     * @return list<array{skillId: int, name: string, level: int}>
     */
    private function _serializeRequirements(array $requirements): array
    {
        $skills = [];
        foreach ($requirements as $skillId => $level) {
            $skills[] = ['skillId' => $skillId, 'name' => $this->sdeService->getItemName($skillId), 'level' => $level];
        }
        usort($skills, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $skills;
    }

    /**
     * @param array<int, int> $requirements
     * @return list<array>
     */
    private function _buildOwnSkillStatus(array $requirements, User $currentUser): array
    {
        $status = [];
        foreach ($this->_findCharactersOfUser($currentUser) as $character) {
            $skillLevels = $this->skillChecker->getSkillLevels($character);
            $missingSkills = [];
            foreach ($this->skillChecker->findMissingSkills($requirements, $skillLevels ?? []) as $skillId => $levels) {
                $missingSkills[] = ['name' => $this->sdeService->getItemName($skillId)] + $levels;
            }

            $status[] = [
                'characterId' => $character->getId(),
                'characterName' => $character->getName(),
                'skillsKnown' => $skillLevels !== null,
                'missingSkills' => $missingSkills,
            ];
        }

        return $status;
    }

    /**
     * @return EveCharacter[]
     */
    private function _findMemberCharacters(): array
    {
        return $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
            ->join('c.user', 'u')
            ->addSelect('u')
            ->orderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return EveCharacter[]
     */
    private function _findCharactersOfUser(User $user): array
    {
        return $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $user], ['name' => 'ASC']);
    }

    /**
     * @param EveCharacter[] $characters
     */
    private function _serializeCharacters(array $characters): array
    {
        $serialized = [];
        foreach ($characters as $character) {
            $serialized[] = [
                'id' => $character->getId(),
                'name' => $character->getName(),
                'userId' => $character->getUser()?->getId(),
            ];
        }

        return $serialized;
    }

    /**
     * @param EveCharacter[] $characters
     */
    private function _serializeUsers(array $characters): array
    {
        $usersById = [];
        foreach ($characters as $character) {
            $user = $character->getUser();
            if ($user !== null) {
                $usersById[$user->getId()] = ['id' => $user->getId(), 'name' => $user->getDisplayName()];
            }
        }
        usort($usersById, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $usersById;
    }

    /**
     * @param EveCharacter[] $characters
     */
    private function _serializeOwnCharacters(array $characters, User $currentUser): array
    {
        $ownCharacters = [];
        foreach ($characters as $character) {
            if ($character->getUser()?->getId() !== $currentUser->getId()) {
                continue;
            }
            $ownCharacters[] = [
                'id' => $character->getId(),
                'name' => $character->getName(),
                'canRead' => $this->ingameSync->canRead($character),
                'canWrite' => $this->ingameSync->canWrite($character),
            ];
        }

        return $ownCharacters;
    }
}
