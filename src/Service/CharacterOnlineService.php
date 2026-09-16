<?php

namespace App\Service;

use App\Entity\EveCharacter;
use App\Repository\EveCharacterRepository;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class CharacterOnlineService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Checks online status for all characters via ESI (GET /characters/{id}/online/).
     * Respects the 60-second ESI cache timer and token scopes.
     */
    public function syncOnlineStatus(): array
    {
        if ($this->esiClient->isOffline()) {
            $this->logger->info('[OnlineCheck] ESI is offline. Skipping online check.');
            return ['skipped' => true, 'reason' => 'esi_offline'];
        }

        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findBy([
            'tokenValid' => true,
        ]);

        $checked = 0;
        $onlineCount = 0;
        $errors = 0;
        $now = new \DateTimeImmutable();

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            // Check if access token contains the required esi-location.read_online.v1 scope
            $accessToken = $character->getAccessToken();
            if ($accessToken) {
                try {
                    $payload = $this->esiClient->decodeTokenPayload($accessToken);
                    $scopes = $payload['scopes'] ?? [];
                    if (!in_array('esi-location.read_online.v1', $scopes, true)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // In case of JWT decoding issues, continue to attempt request
                }
            }

            try {
                // EsiClient handles token refresh and caches the GET response for 60s per Expires header
                $data = $this->esiClient->request('GET', 'characters/' . $character->getId() . '/online/', [], $character);
                $checked++;

                if (is_array($data) && isset($data['online'])) {
                    $isOnline = (bool) $data['online'];
                    $character->setIsOnline($isOnline);

                    if (!empty($data['last_login'])) {
                        try {
                            $character->setLastLogin(new \DateTimeImmutable($data['last_login']));
                        } catch (\Throwable) {}
                    }

                    if (!empty($data['last_logout'])) {
                        try {
                            $character->setLastLogout(new \DateTimeImmutable($data['last_logout']));
                        } catch (\Throwable) {}
                    }

                    if ($isOnline) {
                        $onlineCount++;
                    }
                }
                $character->setLastOnlineCheck($now);
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                $errors++;
                $statusCode = $e->getResponse()->getStatusCode();
                if ($statusCode === 403) {
                    $this->logger->debug(sprintf('[OnlineCheck] Character %s (%d) lacks online scope (403).', $character->getName(), $character->getId()));
                } elseif ($statusCode === 401) {
                    $this->logger->warning(sprintf('[OnlineCheck] Character %s (%d) token unauthorized (401).', $character->getName(), $character->getId()));
                } else {
                    $this->logger->warning(sprintf('[OnlineCheck] HTTP %d for character %s (%d): %s', $statusCode, $character->getName(), $character->getId(), $e->getMessage()));
                }
                $character->setLastOnlineCheck($now);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->warning(sprintf('[OnlineCheck] Error checking online status for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
                $character->setLastOnlineCheck($now);
            }
        }

        $this->entityManager->flush();

        return [
            'checked' => $checked,
            'online' => $onlineCount,
            'errors' => $errors,
        ];
    }

    /**
     * Retrieves current online status statistics grouped by User.
     *
     * @return array{userCount: int, characterCount: int, users: array<int, array{userId: int, username: string, characters: array<int, mixed>}>}
     */
    public function getOnlineStatus(bool $autoSyncIfEmpty = true): array
    {
        /** @var EveCharacterRepository $characterRepository */
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);

        // Consider character online if check happened within the last 15 minutes (with 5-minute cron interval)
        $threshold = (new \DateTimeImmutable())->modify('-15 minutes');
        $onlineCharacters = $characterRepository->findOnlineCharacters($threshold);

        // If no character has ever been checked, trigger an initial sync
        if (empty($onlineCharacters) && $autoSyncIfEmpty) {
            $anyChecked = $characterRepository->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->where('c.lastOnlineCheck IS NOT NULL')
                ->getQuery()
                ->getSingleScalarResult();

            if ((int)$anyChecked === 0) {
                $this->syncOnlineStatus();
                $onlineCharacters = $characterRepository->findOnlineCharacters($threshold);
            }
        }

        $usersMap = [];
        $totalCharacters = count($onlineCharacters);

        foreach ($onlineCharacters as $char) {
            $user = $char->getUser();
            if (!$user) {
                continue;
            }

            $userId = $user->getId();
            if (!isset($usersMap[$userId])) {
                $usersMap[$userId] = [
                    'userId' => $userId,
                    'username' => $user->getUsername(),
                    'characters' => [],
                ];
            }

            $lastLogin = $char->getLastLogin();
            $lastLoginFormatted = $lastLogin ? $lastLogin->format('d.m. H:i') . ' UTC' : null;
            $lastLoginTime = $lastLogin ? $lastLogin->format('H:i') . ' UTC' : null;

            $usersMap[$userId]['characters'][] = [
                'id' => $char->getId(),
                'name' => $char->getName(),
                'lastLogin' => $lastLogin?->format('c'),
                'lastLoginFormatted' => $lastLoginFormatted,
                'lastLoginTime' => $lastLoginTime,
            ];
        }

        $users = array_values($usersMap);
        usort($users, fn($a, $b) => strcasecmp($a['username'], $b['username']));

        return [
            'userCount' => count($users),
            'characterCount' => $totalCharacters,
            'users' => $users,
        ];
    }
}
