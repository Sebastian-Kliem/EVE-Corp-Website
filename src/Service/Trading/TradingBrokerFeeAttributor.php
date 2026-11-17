<?php

namespace App\Service\Trading;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Assigns broker fee bookings to item types; the journal carries no order reference.
 *
 * First by the order state logged with the same `issued` second, otherwise by the open order of the character
 * whose value best fits the fee (earlier price changes between two syncs leave no own state).
 */
class TradingBrokerFeeAttributor
{
    // Orders run at most 90 days, older states cannot belong to a fee in the range
    private const MAX_ORDER_DAYS = 90;
    // Effective fee rate when a character has no exactly matched fee to learn from
    private const DEFAULT_FEE_RATE = 0.015;
    // A fee further off than this factor from the expected value is left unassigned
    private const MAX_FEE_DEVIATION = 3.0;

    public function __construct(private readonly Connection $connection) {}

    /**
     * Every broker fee booking in the range with the order it most likely belongs to.
     *
     * @param int[] $characterIds
     * @return list<array{characterId: int, timestamp: int, amount: float, typeId: ?int, isBuy: ?bool, isExact: bool}> TypeId null when unassigned
     */
    public function attribute(array $characterIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($characterIds === []) {
            return [];
        }

        $fees = $this->_loadFees($characterIds, $from, $to);
        $orderStates = $this->_loadOrderStates($characterIds, $from->modify(sprintf('-%d days', self::MAX_ORDER_DAYS)), $to);

        $statesBySecond = [];
        $ordersByCharacter = [];
        foreach ($orderStates as $state) {
            $statesBySecond[$state['characterId'] . '|' . $state['issued']] ??= $state;
            $ordersByCharacter[$state['characterId']][$state['orderId']][] = $state;
        }

        $attributedFees = [];
        $unmatchedFees = [];
        $feeRates = [];
        foreach ($fees as $fee) {
            $state = $statesBySecond[$fee['characterId'] . '|' . $fee['date']] ?? null;
            if ($state === null) {
                $unmatchedFees[] = $fee;
                continue;
            }
            $attributedFees[] = $this->_attributedFee($fee, $state, true);
            if ($state['value'] > 0) {
                $feeRates[$fee['characterId']][] = $fee['amount'] / $state['value'];
            }
        }

        foreach ($unmatchedFees as $fee) {
            $feeRate = $this->_median($feeRates[$fee['characterId']] ?? []) ?? self::DEFAULT_FEE_RATE;
            $state = $this->_estimateState($fee, $ordersByCharacter[$fee['characterId']] ?? [], $feeRate);
            $attributedFees[] = $this->_attributedFee($fee, $state, false);
        }

        return $attributedFees;
    }

    /**
     * @param array{characterId: int, date: string, amount: float} $fee
     * @param ?array<string, mixed> $state
     * @return array{characterId: int, timestamp: int, amount: float, typeId: ?int, isBuy: ?bool, isExact: bool}
     */
    private function _attributedFee(array $fee, ?array $state, bool $isExact): array
    {
        return [
            'characterId' => $fee['characterId'],
            'timestamp' => (int)strtotime($fee['date'] . ' UTC'),
            'amount' => $fee['amount'],
            'typeId' => $state === null ? null : (int)$state['typeId'],
            'isBuy' => $state === null ? null : (bool)$state['isBuy'],
            'isExact' => $isExact,
        ];
    }

    /**
     * Running order of the character whose value times the fee rate is closest to the fee.
     *
     * @param array{characterId: int, date: string, amount: float} $fee
     * @param array<string, list<array<string, mixed>>> $orders Order ID => logged states, oldest first
     * @return ?array<string, mixed>
     */
    private function _estimateState(array $fee, array $orders, float $feeRate): ?array
    {
        $feeTimestamp = strtotime($fee['date'] . ' UTC');
        $bestState = null;
        $bestDeviation = log(self::MAX_FEE_DEVIATION);

        foreach ($orders as $states) {
            $nearestState = $this->_nearestRunningState($states, $feeTimestamp);
            if ($nearestState === null || $nearestState['value'] <= 0) {
                continue;
            }
            $deviation = abs(log($fee['amount'] / ($nearestState['value'] * $feeRate)));
            if ($deviation < $bestDeviation) {
                $bestDeviation = $deviation;
                $bestState = $nearestState;
            }
        }

        return $bestState;
    }

    /**
     * State closest in time, as long as the order can have been open at that moment.
     *
     * @param list<array<string, mixed>> $states
     * @return ?array<string, mixed>
     */
    private function _nearestRunningState(array $states, int $timestamp): ?array
    {
        $firstIssued = $states[0]['timestamp'];
        $lastState = $states[count($states) - 1];
        // The placement can lie before the first logged state, by up to one sync interval and earlier changes
        $isRunning = $timestamp >= $firstIssued - 86400 && $timestamp <= $lastState['timestamp'] + $lastState['duration'] * 86400;
        if (!$isRunning) {
            return null;
        }

        $nearestState = null;
        $nearestDistance = PHP_INT_MAX;
        foreach ($states as $state) {
            $distance = abs($state['timestamp'] - $timestamp);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestState = $state;
            }
        }

        return $nearestState;
    }

    /**
     * @param int[] $characterIds
     * @return list<array{characterId: int, date: string, amount: float}>
     */
    private function _loadFees(array $characterIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT character_id, date, amount FROM eve_character_wallet_journal_entry
             WHERE character_id IN (:characterIds) AND ref_type = 'brokers_fee' AND date >= :from AND date < :to",
            ['characterIds' => $characterIds, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        $fees = [];
        foreach ($rows as $row) {
            $fees[] = ['characterId' => (int)$row['character_id'], 'date' => (string)$row['date'], 'amount' => abs((float)$row['amount'])];
        }

        return $fees;
    }

    /**
     * @param int[] $characterIds
     * @return list<array<string, mixed>> Oldest first
     */
    private function _loadOrderStates(array $characterIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT character_id, order_id, type_id, issued, price, volume_remain, is_buy, duration FROM eve_character_market_order_log
             WHERE character_id IN (:characterIds) AND issued >= :from AND issued < :to ORDER BY issued',
            ['characterIds' => $characterIds, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        $states = [];
        foreach ($rows as $row) {
            $states[] = [
                'characterId' => (int)$row['character_id'],
                'orderId' => (string)$row['order_id'],
                'typeId' => (int)$row['type_id'],
                'issued' => (string)$row['issued'],
                'timestamp' => (int)strtotime($row['issued'] . ' UTC'),
                'value' => (float)$row['price'] * (int)$row['volume_remain'],
                'isBuy' => (bool)$row['is_buy'],
                'duration' => (int)$row['duration'],
            ];
        }

        return $states;
    }

    /**
     * @param float[] $values
     */
    private function _median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
