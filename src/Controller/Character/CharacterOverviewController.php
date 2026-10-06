<?php

namespace App\Controller\Character;

use App\Entity\EveCharacter;
use App\Entity\User;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/characters')]
#[IsGranted('ROLE_MEMBER')]
class CharacterOverviewController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_profile_characters', methods: ['GET'])]
    public function characters(EsiClient $esiClient): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $unassignedCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy([
            'user' => $currentUser,
            'account' => null,
        ]);

        $accounts = iterator_to_array($currentUser->getEveAccounts());
        usort($accounts, function($a, $b) {
            return strcasecmp($a->getName(), $b->getName());
        });

        // Characters linked before new scopes were added need a re-auth
        $missingScopesByCharacter = [];
        foreach ($this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]) as $character) {
            $missingScopes = $esiClient->getMissingScopes($character);
            if ($missingScopes !== []) {
                $missingScopesByCharacter[$character->getId()] = $missingScopes;
            }
        }

        return $this->render('profile/eve_account/characters.html.twig', [
            'user' => $currentUser,
            'unassignedCharacters' => $unassignedCharacters,
            'accounts' => $accounts,
            'missingScopesByCharacter' => $missingScopesByCharacter,
        ]);
    }
}
