<?php

namespace App\Controller\General;

use App\Entity\User;
use App\Entity\Wormhole\WormholeSite;
use App\Entity\Wormhole\WormholeSiteAlias;
use App\Repository\WormholeSiteAliasRepository;
use App\Repository\WormholeSiteRepository;
use App\Service\JitaPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/general/wormhole-sites')]
#[IsGranted('ROLE_MEMBER')]
final class WormholeSitesController extends AbstractController
{
    private const MAX_ALIAS_LENGTH = 128;

    public function __construct(
        private readonly WormholeSiteRepository $wormholeSiteRepository,
        private readonly WormholeSiteAliasRepository $wormholeSiteAliasRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService
    ) {}

    #[Route('', name: 'app_wormhole_sites', methods: ['GET'])]
    public function index(): Response
    {
        $sites = [];
        $gasTypeIds = [];
        foreach ($this->wormholeSiteRepository->findAllOrdered() as $site) {
            $sites[] = $this->_serializeSite($site);
            if ($site->getCategory() === WormholeSite::CATEGORY_GAS) {
                foreach ($site->getResources() as $resource) {
                    $gasTypeIds[$resource['typeId']] = true;
                }
            }
        }

        $aliases = [];
        foreach ($this->wormholeSiteAliasRepository->findAll() as $alias) {
            $aliases[$alias->getAlias()] = $alias->getSite()->getName();
        }

        return $this->render('general/wormhole_sites.html.twig', [
            'sites' => $sites,
            'aliases' => $aliases,
            'gasPrices' => $this->_getGasPrices(array_keys($gasTypeIds)),
        ]);
    }

    // Maps a site name from a pasted probe scanner list to a known site, an existing mapping is replaced
    #[Route('/api/alias', name: 'app_wormhole_sites_save_alias', methods: ['POST'])]
    public function saveAlias(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $aliasName = trim((string) ($data['alias'] ?? ''));
        $siteName = trim((string) ($data['siteName'] ?? ''));

        if ($aliasName === '' || mb_strlen($aliasName) > self::MAX_ALIAS_LENGTH) {
            return new JsonResponse(['error' => 'Ungültiger Name.'], Response::HTTP_BAD_REQUEST);
        }

        $site = $this->wormholeSiteRepository->findOneBy(['name' => $siteName]);
        if (!$site instanceof WormholeSite) {
            return new JsonResponse(['error' => 'Unbekannte Site.'], Response::HTTP_BAD_REQUEST);
        }

        $alias = $this->wormholeSiteAliasRepository->findOneByAlias($aliasName);
        if ($alias === null) {
            $alias = new WormholeSiteAlias();
            $alias->setAlias($aliasName);
            $this->entityManager->persist($alias);
        }

        $currentUser = $this->getUser();
        $alias->setSite($site)
            ->setCreatedBy($currentUser instanceof User ? $currentUser : null);
        $this->entityManager->flush();

        return new JsonResponse(['alias' => $aliasName, 'siteName' => $site->getName()]);
    }

    /**
     * Jita buy prices per unit, the value when selling the gas right away.
     *
     * @param list<int> $typeIds
     * @return array<int, float>
     */
    private function _getGasPrices(array $typeIds): array
    {
        $prices = [];
        foreach ($typeIds as $typeId) {
            $price = $this->jitaPriceService->getAverageJitaPrice($typeId, true)['price'];
            if ($price !== null) {
                $prices[$typeId] = $price;
            }
        }

        return $prices;
    }

    private function _serializeSite(WormholeSite $site): array
    {
        return [
            'name' => $site->getName(),
            'nameDe' => $site->getNameDe(),
            'category' => $site->getCategory(),
            'classes' => $site->getClasses(),
            'occurrence' => $site->getOccurrence(),
            'blueLootIsk' => $site->getBlueLootIsk(),
            'waves' => $site->getWaves(),
            'resources' => $site->getResources(),
            'notes' => $site->getNotes(),
            'source' => $site->getSource(),
        ];
    }
}
