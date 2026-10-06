<?php

namespace App\Service\Character;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\User;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Collects a user's characters grouped by account folder, shared by the character overview and the management page.
 */
class CharacterListBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient
    ) {}

    /**
     * @return array{accounts: list<EveAccount>, unassignedCharacters: list<EveCharacter>, missingScopesByCharacter: array<int, list<string>>}
     */
    public function build(User $user): array
    {
        $unassignedCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy([
            'user' => $user,
            'account' => null,
        ]);

        $accounts = iterator_to_array($user->getEveAccounts(), false);
        usort($accounts, function($a, $b) {
            return strcasecmp($a->getName(), $b->getName());
        });

        return [
            'accounts' => $accounts,
            'unassignedCharacters' => $unassignedCharacters,
            'missingScopesByCharacter' => $this->_collectMissingScopes($user),
        ];
    }

    // Characters linked before new scopes were added need a re-auth
    private function _collectMissingScopes(User $user): array
    {
        $missingScopesByCharacter = [];
        foreach ($this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $user]) as $character) {
            $missingScopes = $this->esiClient->getMissingScopes($character);
            if ($missingScopes !== []) {
                $missingScopesByCharacter[$character->getId()] = $missingScopes;
            }
        }

        return $missingScopesByCharacter;
    }
}
