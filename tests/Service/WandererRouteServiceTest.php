<?php

namespace App\Tests\Service;

use App\Entity\WandererRouteRule;
use App\Repository\WandererRouteRuleRepository;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use App\Service\Wanderer\WandererRouteService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// Shared mocks from setUp() are only configured by some tests
#[AllowMockObjectsWithoutExpectations]
class WandererRouteServiceTest extends TestCase
{
    private $ruleRepository;
    private $esiClient;
    private $sdeService;
    private $discordWebhookService;
    private $entityManager;
    private WandererRouteService $service;

    protected function setUp(): void
    {
        $this->ruleRepository = $this->createMock(WandererRouteRuleRepository::class);
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->sdeService = $this->createMock(SdeService::class);
        $this->discordWebhookService = $this->createMock(DiscordWebhookService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new WandererRouteService(
            $this->ruleRepository,
            $this->esiClient,
            $this->sdeService,
            $this->discordWebhookService,
            $this->entityManager,
            new NullLogger()
        );
    }

    public function testNewConnectionMatchesCorpRule(): void
    {
        $connection = [
            'id' => 'connection-1',
            'type' => 0,
            'solar_system_source' => 31000001, // J154944 (Wormhole)
            'solar_system_target' => 30000144, // Perimeter (Highsec)
        ];

        $rule = new WandererRouteRule();
        $rule->setName('Jita Express');
        $rule->setTargetSolarSystemId(30000142); // Jita
        $rule->setTargetSolarSystemName('Jita');
        $rule->setMaxJumps(10);
        $rule->setSecurityMode(WandererRouteRule::SEC_MODE_HIGHSEC_ONLY);
        $rule->setIsActive(true);

        $this->ruleRepository->expects($this->once())
            ->method('findActiveCorpRules')
            ->willReturn([$rule]);

        $this->ruleRepository->expects($this->once())
            ->method('findActiveUserRules')
            ->willReturn([]);

        $this->sdeService->expects($this->any())
            ->method('getSolarSystemInfo')
            ->willReturnCallback(function (int $id) {
                if ($id === 31000001) {
                    return [
                        'solarSystemID' => 31000001,
                        'solarSystemName' => 'J154944',
                        'security' => -0.99,
                        'regionName' => 'A-R00001',
                        'constellationName' => 'A-C00001',
                    ];
                }
                if ($id === 30000144) {
                    return [
                        'solarSystemID' => 30000144,
                        'solarSystemName' => 'Perimeter',
                        'security' => 0.9,
                        'regionName' => 'The Forge',
                        'constellationName' => 'Kimotoro',
                    ];
                }
                if ($id === 30000142) {
                    return [
                        'solarSystemID' => 30000142,
                        'solarSystemName' => 'Jita',
                        'security' => 0.95,
                        'regionName' => 'The Forge',
                        'constellationName' => 'Kimotoro',
                    ];
                }
                return null;
            });

        $this->esiClient->expects($this->once())
            ->method('getRoute')
            ->with(30000144, 30000142, 'secure')
            ->willReturn([30000144, 30000142]); // 1 jump

        $this->discordWebhookService->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(DiscordMessage::class), DiscordWebhookService::CHANNEL_WANDERER)
            ->willReturn(true);

        $result = $this->service->processNewConnection($connection, 'Home Chain');

        $this->assertSame(2, $result['processed_systems']);
        $this->assertSame(1, $result['matched_rules']);
        $this->assertSame(1, $result['corp_notifications']);
        $this->assertSame(0, $result['user_notifications']);
        $this->assertNotNull($rule->getLastTriggeredAt());
    }

    /**
     * @return array<string, array{string, ?float, bool}>
     */
    public static function routeSecurityProvider(): array
    {
        return [
            'highsec rule, 0.5 system (true 0.450343) passes' => [WandererRouteRule::SEC_MODE_HIGHSEC_ONLY, 0.450343, true],
            'highsec rule, 0.4 system (true 0.44779) blocks' => [WandererRouteRule::SEC_MODE_HIGHSEC_ONLY, 0.44779, false],
            'lowsec rule, 0.1 system (true 0.02) passes' => [WandererRouteRule::SEC_MODE_HIGHSEC_LOWSEC, 0.02, true],
            'lowsec rule, 0.0 system (true -0.04) blocks' => [WandererRouteRule::SEC_MODE_HIGHSEC_LOWSEC, -0.04, false],
            'lowsec rule, exact 0.0 blocks' => [WandererRouteRule::SEC_MODE_HIGHSEC_LOWSEC, 0.0, false],
            'highsec rule, unknown system blocks' => [WandererRouteRule::SEC_MODE_HIGHSEC_ONLY, null, false],
            'any rule, nullsec passes' => [WandererRouteRule::SEC_MODE_ANY, -0.5, true],
        ];
    }

    #[DataProvider('routeSecurityProvider')]
    public function testRouteSecurityUsesInGameRounding(string $securityMode, ?float $middleSystemSecurity, bool $expectNotification): void
    {
        $rule = new WandererRouteRule();
        $rule->setName('Security Check');
        $rule->setTargetSolarSystemId(30000142);
        $rule->setTargetSolarSystemName('Jita');
        $rule->setMaxJumps(10);
        $rule->setSecurityMode($securityMode);
        $rule->setIsActive(true);

        $this->ruleRepository->method('findActiveCorpRules')->willReturn([$rule]);
        $this->ruleRepository->method('findActiveUserRules')->willReturn([]);

        $systems = [
            30000144 => ['solarSystemID' => 30000144, 'solarSystemName' => 'Perimeter', 'security' => 0.9, 'regionName' => 'The Forge', 'constellationName' => 'Kimotoro'],
            30000142 => ['solarSystemID' => 30000142, 'solarSystemName' => 'Jita', 'security' => 0.945913, 'regionName' => 'The Forge', 'constellationName' => 'Kimotoro'],
        ];
        if ($middleSystemSecurity !== null) {
            $systems[30005000] = ['solarSystemID' => 30005000, 'solarSystemName' => 'Middle', 'security' => $middleSystemSecurity, 'regionName' => 'Test', 'constellationName' => 'Test'];
        }
        $this->sdeService->method('getSolarSystemInfo')->willReturnCallback(fn (int $id) => $systems[$id] ?? null);
        $this->esiClient->method('getRoute')->willReturn([30000144, 30005000, 30000142]);

        $this->discordWebhookService->expects($expectNotification ? $this->once() : $this->never())
            ->method('send')
            ->willReturn(true);

        // Highsec exit system next to a known-space system, so only the route decides
        $this->service->processNewConnection([
            'solar_system_source' => 30000144,
            'solar_system_target' => 30000144,
            'type' => 0,
        ], 'Home Chain');
    }

    public function testStargateConnectionIsIgnored(): void
    {
        $connection = [
            'solar_system_source' => 30000144, // Perimeter (Highsec)
            'solar_system_target' => 30000142, // Jita (Highsec)
            'type' => 1, // Stargate connection
        ];

        $this->ruleRepository->expects($this->never())
            ->method('findActiveCorpRules');

        $result = $this->service->processNewConnection($connection, 'Home Chain');

        $this->assertSame(0, $result['processed_systems']);
        $this->assertSame(0, $result['matched_rules']);
    }

    public function testAlreadyConnectedSystemIsNotAnnouncedAgain(): void
    {
        $rule = new WandererRouteRule();
        $rule->setName('Jita Express');
        $rule->setTargetSolarSystemId(30000142);
        $rule->setTargetSolarSystemName('Jita');
        $rule->setMaxJumps(10);
        $rule->setSecurityMode(WandererRouteRule::SEC_MODE_ANY);
        $rule->setIsActive(true);

        $this->ruleRepository->method('findActiveCorpRules')->willReturn([$rule]);
        $this->ruleRepository->method('findActiveUserRules')->willReturn([]);
        $this->sdeService->method('getSolarSystemInfo')->willReturnCallback(fn (int $id) => [
            'solarSystemID' => $id, 'solarSystemName' => 'System ' . $id, 'security' => $id === 31000001 ? -0.99 : 0.9,
            'regionName' => 'Test', 'constellationName' => 'Test',
        ]);
        $this->esiClient->method('getRoute')->willReturn(null);

        // Perimeter is still reachable through another wormhole, only the new J-space side is evaluated
        $result = $this->service->processNewConnection([
            'type' => 0,
            'solar_system_source' => 31000001,
            'solar_system_target' => 30000144,
        ], 'Home Chain', [30000144]);

        $this->assertSame(1, $result['processed_systems']);
        $this->assertSame(0, $result['matched_rules']);
    }
}
