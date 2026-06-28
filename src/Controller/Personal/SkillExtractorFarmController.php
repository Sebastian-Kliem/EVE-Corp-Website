<?php

namespace App\Controller\Personal;

use App\Entity\EveCharacter;
use App\Entity\User;
use App\Service\JitaPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/personal/profile/skill-farm')]
#[IsGranted('ROLE_MEMBER')]
class SkillExtractorFarmController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService,
        private readonly ManagerRegistry $doctrine
    ) {}

    #[Route('', name: 'app_profile_skill_farm', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Fetch all characters for this user
        $allCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);

        // Filter characters containing 'Skill-Extractor-Farm' tag
        /** @var EveCharacter[] $farmCharacters */
        $farmCharacters = array_filter($allCharacters, function (EveCharacter $char) {
            return in_array('Skill-Extractor-Farm', $char->getTags(), true);
        });

        // Live prices from Jita (Sell prices for standard setup)
        $plexPriceInfo = $this->jitaPriceService->getAverageJitaPrice(44992, false);
        $plexPrice = $plexPriceInfo['price'] ?? 5100000.0;

        $extractorPriceInfo = $this->jitaPriceService->getAverageJitaPrice(40519, false);
        $extractorPrice = $extractorPriceInfo['price'] ?? 480000000.0;

        $injectorPriceInfo = $this->jitaPriceService->getAverageJitaPrice(40520, false);
        $injectorPrice = $injectorPriceInfo['price'] ?? 920000000.0;

        // Jita Buy prices (for Ideal Conditions setup)
        $plexBuyPriceInfo = $this->jitaPriceService->getAverageJitaPrice(44992, true);
        $plexBuyPrice = $plexBuyPriceInfo['price'] ?? ($plexPrice * 0.995); // fallback slight discount

        $extractorBuyPriceInfo = $this->jitaPriceService->getAverageJitaPrice(40519, true);
        $extractorBuyPrice = $extractorBuyPriceInfo['price'] ?? ($extractorPrice * 0.975); // fallback typical buy order spread

        $mctPlex = 800 / 3;
        $mctCost = $mctPlex * $plexPrice;

        // Ideal conditions: 2-for-1 sale = 133.33 PLEX, bought at Jita Buy price
        $mctIdealCost = (800 / 6) * $plexBuyPrice;

        $charactersData = [];
        $sdeConnection = $this->doctrine->getConnection('sde');

        foreach ($farmCharacters as $char) {
            // Get effective attributes from ESI (which already includes implant bonuses)
            $attrs = $char->getAttributes();
            $effectiveAttrs = [
                'intelligence' => (int)($attrs['intelligence'] ?? 20),
                'memory' => (int)($attrs['memory'] ?? 20),
                'perception' => (int)($attrs['perception'] ?? 20),
                'willpower' => (int)($attrs['willpower'] ?? 20),
                'charisma' => (int)($attrs['charisma'] ?? 20),
            ];

            // Resolve implant bonuses
            $implantIds = $char->getImplants();
            $implantBonuses = [
                'intelligence' => 0,
                'memory' => 0,
                'perception' => 0,
                'willpower' => 0,
                'charisma' => 0,
            ];
            $implantsList = [];

            if (!empty($implantIds)) {
                $placeholders = implode(',', array_fill(0, count($implantIds), '?'));
                try {
                    $implantRows = $sdeConnection->fetchAllAssociative(
                        "SELECT t.typeID, t.typeName, ta.attributeID, ta.valueFloat
                         FROM invTypes t
                         JOIN dgmTypeAttributes ta ON t.typeID = ta.typeID
                         WHERE t.typeID IN ($placeholders) AND ta.attributeID IN (175, 176, 177, 178, 179)",
                        $implantIds
                    );

                    foreach ($implantRows as $row) {
                        $attrId = (int)$row['attributeID'];
                        $val = (float)$row['valueFloat'];
                        if ($val > 0) {
                            $attrKey = $this->getAttributeKey($attrId);
                            $implantBonuses[$attrKey] += $val;
                            $implantsList[] = [
                                'name' => $row['typeName'],
                                'bonus' => $val,
                                'attribute' => $this->translateAttribute($attrKey),
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore SDE errors
                }
            }

            // Calculate base attributes (subtracting implant bonuses)
            $baseAttrs = [];
            foreach ($effectiveAttrs as $key => $val) {
                $baseAttrs[$key] = (int)($val - $implantBonuses[$key]);
            }

            // Check if active training is in progress and get its attributes
            $queue = $char->getSkillQueue();
            $activeSkillName = 'Kein aktives Training (Standard Intelligence/Memory)';
            $primaryAttrId = 165; // Intelligence default
            $secondaryAttrId = 166; // Memory default

            if (!empty($queue)) {
                $activeSkill = $queue[0];
                $skillTypeId = (int)$activeSkill['skill_id'];
                try {
                    $skillName = $sdeConnection->fetchOne('SELECT typeName FROM invTypes WHERE typeID = :id', ['id' => $skillTypeId]);
                    if ($skillName) {
                        $activeSkillName = $skillName;
                    }

                    $attribs = $sdeConnection->fetchAllAssociative(
                        'SELECT attributeID, valueFloat FROM dgmTypeAttributes WHERE typeID = :typeId AND attributeID IN (180, 181)',
                        ['typeId' => $skillTypeId]
                    );

                    foreach ($attribs as $row) {
                        if ((int)$row['attributeID'] === 180) {
                            $primaryAttrId = (int)$row['valueFloat'];
                        } elseif ((int)$row['attributeID'] === 181) {
                            $secondaryAttrId = (int)$row['valueFloat'];
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore SDE errors
                }
            }

            $primaryAttrKey = $this->getAttributeKeyFromSde($primaryAttrId);
            $secondaryAttrKey = $this->getAttributeKeyFromSde($secondaryAttrId);

            // Calculation of current SP per minute
            $currentSpMin = $effectiveAttrs[$primaryAttrKey] + 0.5 * $effectiveAttrs[$secondaryAttrKey];

            // Warning checks
            $hasImplants = !empty($implantIds);
            // Standart Setup optimal: base Intelligence (27) and base Memory (21)
            $isOptimalAttributes = ($baseAttrs['intelligence'] === 27 && $baseAttrs['memory'] === 21);

            // Cost and revenue on monthly basis (30 days)
            // Current Setup
            $currentSp30 = $currentSpMin * 43200;
            $currentExtractorsNeeded = $currentSp30 / 500000;
            $currentExtractorCost = $currentExtractorsNeeded * $extractorPrice;
            $currentRevenue = $currentExtractorsNeeded * $injectorPrice;
            $currentTotalCost = $currentExtractorCost + $mctCost;
            $currentProfit = $currentRevenue - $currentTotalCost;

            // Optimal Setup (Memory 32, Intelligence 26 -> 45 SP/Min)
            $optimalSpMin = 45;
            $optimalSp30 = $optimalSpMin * 43200;
            $optimalExtractorsNeeded = $optimalSp30 / 500000;
            $optimalExtractorCost = $optimalExtractorsNeeded * $extractorPrice;
            $optimalRevenue = $optimalExtractorsNeeded * $injectorPrice;
            $optimalTotalCost = $optimalExtractorCost + $mctCost;
            $optimalProfit = $optimalRevenue - $optimalTotalCost;

            // Ideal Setup (45 SP/Min + Buy Orders for Extractors + 2-for-1 NES MCT Sale)
            $idealSpMin = 45;
            $idealSp30 = 1944000;
            $idealExtractorsNeeded = 3.888;
            $idealExtractorCost = $idealExtractorsNeeded * $extractorBuyPrice;
            $idealRevenue = $idealExtractorsNeeded * $injectorPrice;
            $idealTotalCost = $idealExtractorCost + $mctIdealCost;
            $idealProfit = $idealRevenue - $idealTotalCost;

            $charactersData[] = [
                'character' => $char,
                'baseAttrs' => $baseAttrs,
                'implantBonuses' => $implantBonuses,
                'effectiveAttrs' => $effectiveAttrs,
                'implantsList' => $implantsList,
                'activeSkillName' => $activeSkillName,
                'primaryAttr' => $this->translateAttribute($primaryAttrKey),
                'secondaryAttr' => $this->translateAttribute($secondaryAttrKey),
                'currentSpMin' => $currentSpMin,
                'hasImplants' => $hasImplants,
                'isOptimalAttributes' => $isOptimalAttributes,
                // Current stats
                'currentSp30' => $currentSp30,
                'currentExtractorsNeeded' => $currentExtractorsNeeded,
                'currentExtractorCost' => $currentExtractorCost,
                'currentRevenue' => $currentRevenue,
                'currentTotalCost' => $currentTotalCost,
                'currentProfit' => $currentProfit,
                // Optimal stats
                'optimalSpMin' => $optimalSpMin,
                'optimalSp30' => $optimalSp30,
                'optimalExtractorsNeeded' => $optimalExtractorsNeeded,
                'optimalExtractorCost' => $optimalExtractorCost,
                'optimalRevenue' => $optimalRevenue,
                'optimalTotalCost' => $optimalTotalCost,
                'optimalProfit' => $optimalProfit,
                // Ideal stats
                'idealSpMin' => $idealSpMin,
                'idealSp30' => $idealSp30,
                'idealExtractorsNeeded' => $idealExtractorsNeeded,
                'idealExtractorCost' => $idealExtractorCost,
                'idealRevenue' => $idealRevenue,
                'idealTotalCost' => $idealTotalCost,
                'idealProfit' => $idealProfit,
            ];
        }

        return $this->render('profile/profile_skill_farm/index.html.twig', [
            'characters' => $charactersData,
            'plexPrice' => $plexPrice,
            'extractorPrice' => $extractorPrice,
            'injectorPrice' => $injectorPrice,
            'mctCost' => $mctCost,
            'mctPlex' => $mctPlex,
            'plexBuyPrice' => $plexBuyPrice,
            'extractorBuyPrice' => $extractorBuyPrice,
            'mctIdealCost' => $mctIdealCost,
        ]);
    }

    private function getAttributeKey(int $implantAttrId): string
    {
        return match ($implantAttrId) {
            175 => 'charisma',
            176 => 'intelligence',
            177 => 'memory',
            178 => 'perception',
            179 => 'willpower',
            default => 'memory',
        };
    }

    private function getAttributeKeyFromSde(int $sdeAttrId): string
    {
        return match ($sdeAttrId) {
            164 => 'charisma',
            165 => 'intelligence',
            166 => 'memory',
            167 => 'perception',
            168 => 'willpower',
            default => 'memory',
        };
    }

    private function translateAttribute(string $key): string
    {
        return match ($key) {
            'charisma' => 'Charisma',
            'intelligence' => 'Intelligenz',
            'memory' => 'Gedächtnis',
            'perception' => 'Wahrnehmung',
            'willpower' => 'Willenskraft',
            default => $key,
        };
    }
}
