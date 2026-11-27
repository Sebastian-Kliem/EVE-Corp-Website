<?php

namespace App\Controller\General;

use App\Entity\EveCharacter;
use App\Entity\Fitting\Fitting;
use App\Entity\Fitting\FittingIngameCopy;
use App\Entity\User;
use App\Repository\FittingRepository;
use App\Service\Fitting\DefenseDoctrineFleet;
use App\Service\Fitting\FittingBuildException;
use App\Service\Fitting\FittingIngameSync;
use App\Service\Fitting\FittingLibrary;
use App\Service\Fitting\FittingViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/general/fittings')]
#[IsGranted('ROLE_MEMBER')]
class FittingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FittingRepository $fittingRepository,
        private readonly FittingLibrary $library,
        private readonly FittingIngameSync $ingameSync,
        private readonly FittingViewBuilder $viewBuilder,
        private readonly DefenseDoctrineFleet $doctrineFleet,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'app_general_fittings', methods: ['GET'])]
    public function index(): Response
    {
        $this->doctrineFleet->migrateLegacyFits();

        return $this->render('general/fittings.html.twig', [
            'library' => $this->viewBuilder->buildLibrary($this->_currentUser()),
            'canManageCorpFlag' => $this->isGranted('ROLE_OFFICER'),
        ]);
    }

    #[Route('/api/{id}', name: 'app_general_fittings_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $fitting = $this->_findFitting($id);

        return new JsonResponse($this->viewBuilder->buildDetail($fitting, $this->_currentUser()));
    }

    #[Route('/api/preview', name: 'app_general_fittings_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $data = $this->_readJson($request);

        try {
            return new JsonResponse($this->viewBuilder->buildPreview((string) ($data['eft'] ?? '')));
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }
    }

    #[Route('/api', name: 'app_general_fittings_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->_readJson($request);
        $user = $this->_currentUser();

        try {
            $result = $this->library->createFromEft((string) ($data['eft'] ?? ''), $user, $this->_optionalString($data, 'name'), $this->_optionalString($data, 'description'));
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }

        $fitting = $result['fitting'];
        if ($result['created']) {
            $fitting->setTags($this->library->normalizeTags($data['tags'] ?? []));
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'created' => $result['created'],
            'warnings' => $result['warnings'],
            'fitting' => $this->_summarize($fitting),
        ]);
    }

    #[Route('/api/{id}', name: 'app_general_fittings_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $fitting = $this->_findFitting($id);
        $this->_denyUnlessCanEdit($fitting);
        $data = $this->_readJson($request);

        $name = $this->_optionalString($data, 'name');
        if ($name === null) {
            return $this->_error('Der Name darf nicht leer sein.');
        }

        $warnings = [];
        try {
            $eft = $this->_optionalString($data, 'eft');
            if ($eft !== null) {
                $warnings = $this->library->updateContentFromEft($fitting, $eft);
            }
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }

        $fitting->setName($name);
        $fitting->setDescription($this->_optionalString($data, 'description'));
        $this->entityManager->flush();

        return new JsonResponse(['warnings' => $warnings, 'fitting' => $this->_summarize($fitting)]);
    }

    #[Route('/api/{id}/tags', name: 'app_general_fittings_tags', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateTags(int $id, Request $request): JsonResponse
    {
        $fitting = $this->_findFitting($id);
        $data = $this->_readJson($request);

        $fitting->setTags($this->library->normalizeTags(is_array($data['tags'] ?? null) ? $data['tags'] : []));
        $this->entityManager->flush();

        return new JsonResponse(['fitting' => $this->_summarize($fitting)]);
    }

    #[Route('/api/{id}/corp', name: 'app_general_fittings_corp_flag', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function updateCorpFlag(int $id, Request $request): JsonResponse
    {
        $fitting = $this->_findFitting($id);
        $data = $this->_readJson($request);

        $fitting->setCorpSaved((bool) ($data['corpSaved'] ?? false));
        $this->entityManager->flush();

        return new JsonResponse(['fitting' => $this->_summarize($fitting)]);
    }

    #[Route('/api/{id}', name: 'app_general_fittings_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $fitting = $this->_findFitting($id);
        $this->_denyUnlessCanEdit($fitting);

        try {
            $this->library->delete($fitting);
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/import', name: 'app_general_fittings_import', methods: ['POST'])]
    public function importOwnCharacters(): JsonResponse
    {
        $user = $this->_currentUser();
        $results = [];

        foreach ($this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $user]) as $character) {
            $results[] = $this->_importCharacter($character);
        }

        return new JsonResponse([
            'results' => $results,
            'library' => $this->viewBuilder->buildLibrary($user),
        ]);
    }

    #[Route('/api/{id}/ingame', name: 'app_general_fittings_save_ingame', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveToGame(int $id, Request $request): JsonResponse
    {
        $fitting = $this->_findFitting($id);
        $data = $this->_readJson($request);
        $character = $this->_findOwnCharacter((int) ($data['characterId'] ?? 0));

        try {
            $this->ingameSync->saveToGame($fitting, $character);
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }

        return new JsonResponse(['fitting' => $this->_summarize($fitting)]);
    }

    #[Route('/api/copies/{copyId}/refresh', name: 'app_general_fittings_refresh_copy', requirements: ['copyId' => '\d+'], methods: ['POST'])]
    public function refreshCopy(int $copyId): JsonResponse
    {
        $copy = $this->_findOwnCopy($copyId);

        try {
            $this->ingameSync->updateInGame($copy);
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }

        return new JsonResponse(['fitting' => $this->_summarize($copy->getFitting())]);
    }

    #[Route('/api/copies/{copyId}', name: 'app_general_fittings_delete_copy', requirements: ['copyId' => '\d+'], methods: ['DELETE'])]
    public function deleteCopy(int $copyId): JsonResponse
    {
        $copy = $this->_findOwnCopy($copyId);
        $fitting = $copy->getFitting();

        try {
            $this->ingameSync->deleteFromGame($copy);
        } catch (FittingBuildException $e) {
            return $this->_error($e->getMessage());
        }

        return new JsonResponse(['fitting' => $this->_summarize($fitting)]);
    }

    /**
     * @return array{characterName: string, success: bool, message: string}
     */
    private function _importCharacter(EveCharacter $character): array
    {
        if (!$this->ingameSync->canRead($character)) {
            return ['characterName' => (string) $character->getName(), 'success' => false, 'message' => 'Berechtigung fehlt (Re-Auth nötig).'];
        }

        try {
            $result = $this->ingameSync->importForCharacter($character);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('Fitting import for %s failed: %s', $character->getName(), $e->getMessage()));
            return ['characterName' => (string) $character->getName(), 'success' => false, 'message' => 'ESI-Abruf fehlgeschlagen.'];
        }

        return [
            'characterName' => (string) $character->getName(),
            'success' => true,
            'message' => sprintf('%d Fits ingame, %d neu in der Bibliothek, %d entfernt.', $result['total'], $result['created'], $result['removed']),
        ];
    }

    private function _summarize(Fitting $fitting): array
    {
        return $this->viewBuilder->summarizeFittings([$fitting], null, $this->_currentUser())[0];
    }

    private function _findFitting(int $id): Fitting
    {
        $fitting = $this->fittingRepository->find($id);
        if ($fitting === null) {
            throw $this->createNotFoundException('Fit nicht gefunden.');
        }

        return $fitting;
    }

    private function _findOwnCharacter(int $characterId): EveCharacter
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($characterId);
        if ($character === null || $character->getUser()?->getId() !== $this->_currentUser()->getId()) {
            throw $this->createAccessDeniedException('Nur eigene Charaktere sind erlaubt.');
        }

        return $character;
    }

    private function _findOwnCopy(int $copyId): FittingIngameCopy
    {
        $copy = $this->entityManager->getRepository(FittingIngameCopy::class)->find($copyId);
        if ($copy === null) {
            throw $this->createNotFoundException('Ingame-Fit nicht gefunden.');
        }
        $this->_findOwnCharacter((int) $copy->getCharacter()->getId());

        return $copy;
    }

    private function _denyUnlessCanEdit(Fitting $fitting): void
    {
        if (!$this->viewBuilder->canEdit($fitting->getCreatedBy(), $this->_currentUser())) {
            throw $this->createAccessDeniedException('Nur Ersteller oder Officer dürfen dieses Fit ändern.');
        }
    }

    private function _currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function _readJson(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function _optionalString(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function _error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
    }
}
