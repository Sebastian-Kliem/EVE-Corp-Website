<?php

namespace App\Repository;

use App\Entity\Market\PublicContract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicContract>
 * Bulk writes with plain SQL: The Forge alone lists ~35,000 contracts every 30 minutes.
 */
class PublicContractRepository extends ServiceEntityRepository
{
    private const INSERT_CHUNK_SIZE = 500;

    private const CONTRACT_COLUMNS = ['contract_id', 'region_id', 'start_location_id', 'issuer_id', 'issuer_corporation_id', 'for_corporation', 'price', 'volume', 'title', 'date_issued', 'date_expired', 'first_seen_at', 'last_seen_at'];
    private const ITEM_COLUMNS = ['contract_id', 'record_id', 'type_id', 'quantity', 'is_included', 'is_singleton', 'is_blueprint_copy', 'material_efficiency', 'time_efficiency', 'runs', 'item_id'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicContract::class);
    }

    /**
     * Inserts new contracts and marks known ones as seen again (a contract that came back is no longer gone).
     *
     * @param list<array<string, mixed>> $contracts Rows with the columns of self::CONTRACT_COLUMNS
     */
    public function upsertSeen(array $contracts): void
    {
        $connection = $this->_connection();
        foreach (array_chunk($contracts, self::INSERT_CHUNK_SIZE) as $chunk) {
            $this->_insertRows($connection, 'public_contract', self::CONTRACT_COLUMNS, $chunk, 'ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), gone_since = NULL');
        }
    }

    /**
     * Contracts of the region that were not in the latest complete list are accepted, expired or deleted.
     *
     * @return int Number of contracts newly marked as gone
     */
    public function markGone(int $regionId, \DateTimeImmutable $listedAt): int
    {
        return (int)$this->_connection()->executeStatement(
            'UPDATE public_contract SET gone_since = ? WHERE region_id = ? AND gone_since IS NULL AND last_seen_at < ?',
            [$this->_format($listedAt), $regionId, $this->_format($listedAt)]
        );
    }

    /**
     * Newest contracts first: they are the most interesting ones and still exist for a while.
     *
     * @return int[]
     */
    public function findContractIdsWithoutItems(int $limit): array
    {
        $contractIds = [];
        $rows = $this->_connection()->fetchFirstColumn(
            sprintf('SELECT contract_id FROM public_contract WHERE items_fetched_at IS NULL AND gone_since IS NULL ORDER BY date_issued DESC LIMIT %d', $limit)
        );
        foreach ($rows as $contractId) {
            $contractIds[] = (int)$contractId;
        }

        return $contractIds;
    }

    /**
     * Stores the items of several contracts and marks them as loaded, also contracts without items (gone before loading).
     *
     * @param array<int, list<array<string, mixed>>> $itemsByContractId Contract ID => item rows with the columns of self::ITEM_COLUMNS
     */
    public function saveItems(array $itemsByContractId, \DateTimeImmutable $fetchedAt): void
    {
        if ($itemsByContractId === []) {
            return;
        }

        $connection = $this->_connection();
        $connection->transactional(function () use ($connection, $itemsByContractId, $fetchedAt): void {
            $itemRows = [];
            foreach ($itemsByContractId as $items) {
                foreach ($items as $item) {
                    $itemRows[] = $item;
                }
            }
            foreach (array_chunk($itemRows, self::INSERT_CHUNK_SIZE) as $chunk) {
                // Ignores records that are already stored when a contract is loaded twice
                $this->_insertRows($connection, 'public_contract_item', self::ITEM_COLUMNS, $chunk, 'ON DUPLICATE KEY UPDATE record_id = record_id');
            }

            $connection->executeStatement(
                'UPDATE public_contract SET items_fetched_at = ? WHERE contract_id IN (?)',
                [$this->_format($fetchedAt), array_keys($itemsByContractId)],
                [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
        });
    }

    /**
     * @return int Number of deleted contracts, their items go with them (ON DELETE CASCADE)
     */
    public function deleteGoneBefore(\DateTimeImmutable $cutoff): int
    {
        return (int)$this->_connection()->executeStatement(
            'DELETE FROM public_contract WHERE gone_since < ?',
            [$this->_format($cutoff)]
        );
    }

    /**
     * @param string[] $columns
     * @param list<array<string, mixed>> $rows
     */
    private function _insertRows(Connection $connection, string $table, array $columns, array $rows, string $onDuplicate): void
    {
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $parameters = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $parameters[] = $row[$column];
            }
        }

        $connection->executeStatement(
            sprintf('INSERT INTO %s (%s) VALUES %s %s', $table, implode(', ', $columns), implode(', ', array_fill(0, count($rows), $rowPlaceholder)), $onDuplicate),
            $parameters
        );
    }

    private function _connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private function _format(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
