<?php

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCorporationAsset;
use App\Service\SdeService;
use App\Service\LocationService;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        SdeService $sdeService,
        LocationService $locationService,
        EsiClient $esiClient
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            // CSRF Protection
            if (!$this->isCsrfTokenValid('change_password', $request->request->get('_token'))) {
                $errors[] = 'Ungültiges CSRF-Token. Bitte versuche es erneut.';
            }

            $currentPassword = $request->request->get('current_password', '');
            $newPassword = $request->request->get('new_password', '');
            $newPasswordConfirm = $request->request->get('new_password_confirm', '');

            if (empty($errors)) {
                // 1. Verify current password
                if (!$this->passwordHasher->isPasswordValid($currentUser, $currentPassword)) {
                    $errors[] = 'Dein aktuelles Passwort ist nicht korrekt.';
                }

                // 2. Validate new password length
                if (strlen($newPassword) < 6) {
                    $errors[] = 'Das neue Passwort muss mindestens 6 Zeichen lang sein.';
                }

                // 3. Confirm password match
                if ($newPassword !== $newPasswordConfirm) {
                    $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                }

                // If all checks pass, save the new password
                if (empty($errors)) {
                    $hashedPassword = $this->passwordHasher->hashPassword($currentUser, $newPassword);
                    $currentUser->setPassword($hashedPassword);
                    
                    $this->entityManager->flush();
                    $success = true;
                    
                    $this->addFlash('success', 'Dein Passwort wurde erfolgreich geändert!');
                }
            }
        }

        // Fetch personal corp asset choices
        $userCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        $corpIds = [];
        $charByCorp = [];
        foreach ($userCharacters as $char) {
            if ($char->getCorporationId()) {
                $corpIds[] = $char->getCorporationId();
                if (!isset($charByCorp[$char->getCorporationId()])) {
                    $charByCorp[$char->getCorporationId()] = $char;
                }
            }
        }
        $corpIds = array_unique($corpIds);

        $availableHangars = [];
        $availableContainers = [];

        if (!empty($corpIds)) {
            // Fetch distinct hangar locations
            $hangarRows = $this->entityManager->getRepository(EveCorporationAsset::class)->createQueryBuilder('a')
                ->select('DISTINCT a.locationId, a.locationFlag, a.corporationId')
                ->where('a.corporationId IN (:corpIds)')
                ->andWhere('a.locationFlag IN (:flags)')
                ->setParameter('corpIds', $corpIds)
                ->setParameter('flags', ['CorpSAG1', 'CorpSAG2', 'CorpSAG3', 'CorpSAG4', 'CorpSAG5', 'CorpSAG6', 'CorpSAG7', 'CorpDeliveries', 'Hangar'])
                ->getQuery()
                ->getResult();

            // Fetch division names for the corporations
            $divisionNamesMap = [];
            foreach ($corpIds as $corpId) {
                $syncChar = $charByCorp[$corpId] ?? null;
                if ($syncChar) {
                    try {
                        $divData = $esiClient->request('GET', sprintf('corporations/%d/divisions/', $corpId), [], $syncChar);
                        if (isset($divData['hangar']) && is_array($divData['hangar'])) {
                            foreach ($divData['hangar'] as $div) {
                                $divisionNamesMap[$corpId][(int) $div['division']] = $div['name'];
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }

            $getDivisionName = function (int $corpId, string $flag) use ($divisionNamesMap) {
                if (preg_match('/^CorpSAG(\d)$/', $flag, $matches)) {
                    $divIndex = (int) $matches[1];
                    return $divisionNamesMap[$corpId][$divIndex] ?? 'Hangar ' . $divIndex;
                }
                if ($flag === 'CorpDeliveries') {
                    return 'Lieferungen (Deliveries)';
                }
                if ($flag === 'Hangar' || $flag === 'HangarAll') {
                    return 'Hangar';
                }
                return $flag;
            };

            $resolvedLocations = [];
            foreach ($hangarRows as $row) {
                $locId = (int)$row['locationId'];
                $corpId = (int)$row['corporationId'];
                $flag = $row['locationFlag'];
                $char = $charByCorp[$corpId] ?? null;

                if (!isset($resolvedLocations[$locId])) {
                    $resolved = $locationService->resolveLocation($locId, $char);
                    $resolvedLocations[$locId] = $resolved['name'];
                }

                $locName = $resolvedLocations[$locId];
                $divName = $getDivisionName($corpId, $flag);

                $availableHangars[] = [
                    'id' => sprintf('%d_%d_%s', $corpId, $locId, $flag),
                    'corpId' => $corpId,
                    'locationId' => $locId,
                    'locationFlag' => $flag,
                    'locationName' => $locName,
                    'divisionName' => $divName,
                ];
            }

            // Fetch container type IDs
            $uniqueTypes = $this->entityManager->getRepository(EveCorporationAsset::class)->createQueryBuilder('a')
                ->select('DISTINCT a.typeId')
                ->where('a.corporationId IN (:corpIds)')
                ->setParameter('corpIds', $corpIds)
                ->getQuery()
                ->getResult();

            $containerTypeIds = [];
            foreach ($uniqueTypes as $row) {
                $tId = (int)$row['typeId'];
                if ($sdeService->isContainer($tId)) {
                    $containerTypeIds[] = $tId;
                }
            }

            if (!empty($containerTypeIds)) {
                $containerAssets = $this->entityManager->getRepository(EveCorporationAsset::class)->createQueryBuilder('a')
                    ->where('a.corporationId IN (:corpIds)')
                    ->andWhere('a.typeId IN (:containerTypeIds)')
                    ->setParameter('corpIds', $corpIds)
                    ->setParameter('containerTypeIds', $containerTypeIds)
                    ->getQuery()
                    ->getResult();

                foreach ($containerAssets as $asset) {
                    $locId = $asset->getLocationId();
                    $corpId = $asset->getCorporationId();
                    $char = $charByCorp[$corpId] ?? null;

                    if (!isset($resolvedLocations[$locId])) {
                        $resolved = $locationService->resolveLocation($locId, $char);
                        $resolvedLocations[$locId] = $resolved['name'];
                    }

                    $locName = $resolvedLocations[$locId];
                    $typeName = $sdeService->getItemName($asset->getTypeId());
                    $containerName = $asset->getCustomName() ?? ($typeName . ' (#' . $asset->getItemId() . ')');

                    $availableContainers[] = [
                        'id' => sprintf('%d_%d', $corpId, $asset->getItemId()),
                        'corpId' => $corpId,
                        'itemId' => $asset->getItemId(),
                        'name' => $containerName,
                        'locationName' => $locName,
                        'locationFlag' => $getDivisionName($corpId, $asset->getLocationFlag()),
                    ];
                }
            }
        }

        // Sort choices alphabetically
        usort($availableHangars, function($a, $b) {
            $cmp = strcasecmp($a['locationName'], $b['locationName']);
            if ($cmp !== 0) return $cmp;
            return strcasecmp($a['divisionName'], $b['divisionName']);
        });

        usort($availableContainers, function($a, $b) {
            $cmp = strcasecmp($a['locationName'], $b['locationName']);
            if ($cmp !== 0) return $cmp;
            return strcasecmp($a['name'], $b['name']);
        });

        $response = new Response();
        if (!empty($errors) && $request->isMethod('POST')) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('profile/profile/profile.html.twig', [
            'user' => $currentUser,
            'errors' => $errors,
            'success' => $success,
            'availableHangars' => $availableHangars,
            'availableContainers' => $availableContainers,
        ], $response);
    }

    #[Route('/characters', name: 'app_profile_characters', methods: ['GET'])]
    public function characters(): Response
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

        return $this->render('profile/eve_account/characters.html.twig', [
            'user' => $currentUser,
            'unassignedCharacters' => $unassignedCharacters,
            'accounts' => $accounts,
        ]);
    }


    #[Route('/personal-assets', name: 'app_profile_personal_assets', methods: ['POST'])]
    public function updatePersonalAssets(Request $request): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isCsrfTokenValid('update_personal_assets', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token. Bitte versuche es erneut.');
            return $this->redirectToRoute('app_profile');
        }

        $selectedHangars = $request->request->all('hangars'); // Array of strings like "corpId_locationId_flag"
        $selectedContainers = $request->request->all('containers'); // Array of strings like "corpId_itemId"

        $hangarsConfig = [];
        foreach ($selectedHangars as $hangarStr) {
            $parts = explode('_', $hangarStr, 3);
            if (count($parts) === 3) {
                $hangarsConfig[] = [
                    'corporationId' => (int)$parts[0],
                    'locationId' => (int)$parts[1],
                    'locationFlag' => $parts[2],
                ];
            }
        }

        $containersConfig = [];
        foreach ($selectedContainers as $containerStr) {
            $parts = explode('_', $containerStr, 2);
            if (count($parts) === 2) {
                $containersConfig[] = [
                    'corporationId' => (int)$parts[0],
                    'itemId' => (int)$parts[1],
                ];
            }
        }

        $currentUser->setPersonalCorpHangars($hangarsConfig);
        $currentUser->setPersonalCorpContainers($containersConfig);

        $this->entityManager->flush();

        $this->addFlash('success', 'Deine persönlichen Corp-Asset-Einstellungen wurden erfolgreich gespeichert!');
        return $this->redirectToRoute('app_profile');
    }
}
