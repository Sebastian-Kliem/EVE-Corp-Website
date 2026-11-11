<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches the per-user performance ledger; warmed by the hourly asset sync and invalidated on user edits.
 */
class PerformanceLedgerCache
{
    // Fallback in case the hourly warm-up fails
    private const TTL_SECONDS = 7200;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly PerformanceEngine $performanceEngine
    ) {}

    /**
     * @return array{ledger: array, calculatedAt: string}
     */
    public function get(User $user): array
    {
        return $this->cache->get($this->_buildKey($user), function (ItemInterface $item) use ($user): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return [
                'ledger' => $this->performanceEngine->calculateDailyPerformance($user),
                'calculatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
        });
    }

    public function warm(User $user): void
    {
        $this->invalidate($user);
        $this->get($user);
    }

    public function invalidate(User $user): void
    {
        $this->cache->delete($this->_buildKey($user));
    }

    private function _buildKey(User $user): string
    {
        return 'performance_ledger_user_' . $user->getId();
    }
}
