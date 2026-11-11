<?php

namespace App\Service\Character;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\User;
use App\Service\SdeService;

/**
 * Prepares the character overview: accounts sorted Omega first and by skill points, enriched with per-character stats.
 */
class CharacterOverviewBuilder
{
    public const TRAINING_ACTIVE = 'active';
    public const TRAINING_PAUSED = 'paused';
    public const TRAINING_EMPTY = 'empty';
    // Queues running out within this window are highlighted
    private const QUEUE_WARNING_SECONDS = 86400;

    public function __construct(
        private readonly CharacterListBuilder $characterListBuilder,
        private readonly SdeService $sdeService
    ) {}

    public function build(User $user, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $characterList = $this->characterListBuilder->build($user);

        $accountGroups = [];
        foreach ($characterList['accounts'] as $account) {
            $accountGroups[] = $this->_buildGroup($account, iterator_to_array($account->getCharacters(), false), $now);
        }
        usort($accountGroups, $this->_compareAccountGroups(...));

        $unassignedGroup = $this->_buildGroup(null, $characterList['unassignedCharacters'], $now);

        return [
            'accountGroups' => $accountGroups,
            'unassignedGroup' => $unassignedGroup,
            'totals' => $this->_buildTotals($accountGroups, $unassignedGroup),
            'missingScopesByCharacter' => $characterList['missingScopesByCharacter'],
        ];
    }

    /**
     * @param list<EveCharacter> $characters
     */
    private function _buildGroup(?EveAccount $account, array $characters, \DateTimeImmutable $now): array
    {
        $characterEntries = [];
        $totalSkillPoints = 0;
        $maxSkillPoints = 0;
        $totalWallet = 0.0;

        foreach ($characters as $character) {
            $characterEntries[] = $this->_buildCharacterEntry($character, $now);
            $skillPoints = $character->getTotalSkillPoints() ?? 0;
            $totalSkillPoints += $skillPoints;
            $maxSkillPoints = max($maxSkillPoints, $skillPoints);
            $totalWallet += (float) $character->getWalletBalance();
        }
        usort($characterEntries, $this->_compareCharacterEntries(...));

        return [
            'account' => $account,
            'characters' => $characterEntries,
            'totalSkillPoints' => $totalSkillPoints,
            'maxSkillPoints' => $maxSkillPoints,
            'totalWallet' => $totalWallet,
        ];
    }

    private function _buildCharacterEntry(EveCharacter $character, \DateTimeImmutable $now): array
    {
        return [
            'character' => $character,
            'totalSkillPoints' => $character->getTotalSkillPoints(),
            'unallocatedSkillPoints' => $character->getUnallocatedSkillPoints(),
            'training' => $this->_summarizeSkillQueue($character->getSkillQueue(), $now),
        ];
    }

    // Stored queue is a sync snapshot, so entries finished since then are skipped
    private function _summarizeSkillQueue(array $skillQueue, \DateTimeImmutable $now): array
    {
        $queueEntries = [];
        foreach ($skillQueue as $queueEntry) {
            if (isset($queueEntry['skill_id'])) {
                $queueEntries[(int) ($queueEntry['queue_position'] ?? count($queueEntries))] = $queueEntry;
            }
        }
        ksort($queueEntries);

        $activeEntry = null;
        $queueFinishesAt = null;
        $remainingEntries = 0;
        foreach ($queueEntries as $queueEntry) {
            if (!isset($queueEntry['finish_date'])) {
                return $this->_buildTrainingSummary(self::TRAINING_PAUSED, reset($queueEntries), null, null, count($queueEntries), $now);
            }

            $finishesAt = new \DateTimeImmutable($queueEntry['finish_date']);
            if ($finishesAt <= $now) {
                continue;
            }

            $activeEntry ??= $queueEntry;
            $queueFinishesAt = $finishesAt;
            $remainingEntries++;
        }

        if ($activeEntry === null) {
            return $this->_buildTrainingSummary(self::TRAINING_EMPTY, null, null, null, 0, $now);
        }

        $skillFinishesAt = new \DateTimeImmutable($activeEntry['finish_date']);

        return $this->_buildTrainingSummary(self::TRAINING_ACTIVE, $activeEntry, $skillFinishesAt, $queueFinishesAt, $remainingEntries, $now);
    }

    private function _buildTrainingSummary(string $status, ?array $queueEntry, ?\DateTimeImmutable $skillFinishesAt, ?\DateTimeImmutable $queueFinishesAt, int $queueLength, \DateTimeImmutable $now): array
    {
        return [
            'status' => $status,
            'skillName' => $queueEntry !== null ? $this->sdeService->getItemName($queueEntry['skill_id']) : null,
            'level' => $queueEntry['finished_level'] ?? null,
            'skillFinishesAt' => $skillFinishesAt,
            'skillRemaining' => $skillFinishesAt !== null ? $this->_formatRemaining($now, $skillFinishesAt) : null,
            'queueFinishesAt' => $queueFinishesAt,
            'queueRemaining' => $queueFinishesAt !== null ? $this->_formatRemaining($now, $queueFinishesAt) : null,
            'queueLength' => $queueLength,
            'queueEndsSoon' => $queueFinishesAt !== null && $queueFinishesAt->getTimestamp() - $now->getTimestamp() < self::QUEUE_WARNING_SECONDS,
        ];
    }

    private function _formatRemaining(\DateTimeImmutable $from, \DateTimeImmutable $until): string
    {
        $interval = $from->diff($until);
        $days = (int) $interval->days;

        if ($days > 0) {
            return sprintf('%dT %dh', $days, $interval->h);
        }
        if ($interval->h > 0) {
            return sprintf('%dh %dm', $interval->h, $interval->i);
        }

        return sprintf('%dm', max(1, $interval->i));
    }

    private function _buildTotals(array $accountGroups, array $unassignedGroup): array
    {
        $totals = [
            'characterCount' => count($unassignedGroup['characters']),
            'accountCount' => count($accountGroups),
            'omegaAccountCount' => 0,
            'totalSkillPoints' => $unassignedGroup['totalSkillPoints'],
            'totalWallet' => $unassignedGroup['totalWallet'],
        ];

        foreach ($accountGroups as $accountGroup) {
            $totals['characterCount'] += count($accountGroup['characters']);
            $totals['totalSkillPoints'] += $accountGroup['totalSkillPoints'];
            $totals['totalWallet'] += $accountGroup['totalWallet'];
            if ($accountGroup['account']->isOmega()) {
                $totals['omegaAccountCount']++;
            }
        }

        return $totals;
    }

    // Omega before Alpha, then by the account's strongest character, then by name
    private function _compareAccountGroups(array $left, array $right): int
    {
        $omegaOrder = (int) $right['account']->isOmega() <=> (int) $left['account']->isOmega();
        if ($omegaOrder !== 0) {
            return $omegaOrder;
        }

        $skillPointOrder = $right['maxSkillPoints'] <=> $left['maxSkillPoints'];
        if ($skillPointOrder !== 0) {
            return $skillPointOrder;
        }

        return strcasecmp((string) $left['account']->getName(), (string) $right['account']->getName());
    }

    private function _compareCharacterEntries(array $left, array $right): int
    {
        $skillPointOrder = ($right['totalSkillPoints'] ?? 0) <=> ($left['totalSkillPoints'] ?? 0);
        if ($skillPointOrder !== 0) {
            return $skillPointOrder;
        }

        return strcasecmp((string) $left['character']->getName(), (string) $right['character']->getName());
    }
}
