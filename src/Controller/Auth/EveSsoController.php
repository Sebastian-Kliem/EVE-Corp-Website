<?php

namespace App\Controller\Auth;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\User;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class EveSsoController extends AbstractController
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('/auth/eve/login', name: 'app_eve_sso_login')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function login(Request $request): Response
    {
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('eve_sso_state', $state);

        $authUrl = $this->esiClient->getAuthorizationUrl($state);

        return $this->redirect($authUrl);
    }

    #[Route('/auth/eve/callback', name: 'app_eve_sso_callback')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $savedState = $session->get('eve_sso_state');
        $requestState = $request->query->get('state');

        // Clear state from session immediately
        $session->remove('eve_sso_state');

        if (!$savedState || $savedState !== $requestState) {
            $this->addFlash('error', 'Ungültiger CSRF-Status (State). Bitte versuche es erneut.');
            return $this->redirectToRoute('app_profile');
        }

        $code = $request->query->get('code');
        if (!$code) {
            $this->addFlash('error', 'Kein Autorisierungscode von EVE Online erhalten.');
            return $this->redirectToRoute('app_profile');
        }

        try {
            // Exchange code for tokens
            $tokenData = $this->esiClient->exchangeCode($code);
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn = (int) ($tokenData['expires_in'] ?? 1200);

            if (!$accessToken || !$refreshToken) {
                throw new \RuntimeException('Es wurden keine gültigen Tokens von EVE Online empfangen.');
            }

            // Decode character details from the JWT payload
            $decoded = $this->esiClient->decodeTokenPayload($accessToken);
            $characterId = $decoded['character_id'];
            $characterName = $decoded['name'];
            $ownerHash = $decoded['owner_hash'];

            // Get current logged-in user
            $currentUser = $this->getUser();
            if (!$currentUser instanceof User) {
                throw new \RuntimeException('Du musst eingeloggt sein.');
            }

            // Check if character already exists in database
            $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
            /** @var EveCharacter|null $character */
            $character = $characterRepository->find($characterId);

            if ($character === null) {
                $character = new EveCharacter();
                $character->setId($characterId);
            }

            if ($character->getPerformanceCutoffDate() === null) {
                $character->setPerformanceCutoffDate(new \DateTimeImmutable());
            }

            // Update character credentials and metadata
            $character->setName($characterName);
            $character->setUser($currentUser);
            $character->setAccessToken($accessToken);
            $character->setRefreshToken($refreshToken);
            $character->setTokenExpiresAt((new \DateTimeImmutable())->modify('+' . $expiresIn . ' seconds'));
            $character->setOwnerHash($ownerHash);
            $character->setTokenValid(true);

            // Fetch public character info from ESI to get Corporation ID and Alliance ID
            try {
                $characterData = $this->esiClient->request('GET', 'characters/' . $characterId . '/');
                $character->setCorporationId($characterData['corporation_id'] ?? null);
                $character->setAllianceId($characterData['alliance_id'] ?? null);
            } catch (\Exception $e) {
                // Keep going if public ESI call fails, we can update it later
            }

            // If the character was previously linked to another user's account, clear the assignment
            if ($character->getAccount() !== null && $character->getAccount()->getUser() !== $currentUser) {
                $character->setAccount(null);
            }

            $this->entityManager->persist($character);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Charakter "%s" erfolgreich verknüpft!', $characterName));

        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler bei der SSO-Verknüpfung: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }
}
