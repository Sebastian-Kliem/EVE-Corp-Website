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

    public function testVerifySignatureWithValidHmac(): void
    {
        $secret = 'test_secret_123';
        $timestamp = (string) time();
        $rawPayload = '{"event":"add_system"}';

        $validSignature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

        $this->discordWebhookService->expects($this->any())
            ->method('getWandererSecret')
            ->willReturn($secret);

        $this->assertTrue($this->service->verifySignature($rawPayload, $validSignature, $timestamp));
        $this->assertFalse($this->service->verifySignature($rawPayload, 'invalid_sig', $timestamp));
    }

    public function testVerifySignatureRejectsExpiredTimestamp(): void
    {
        $secret = 'test_secret_123';
        $timestamp = (string) (time() - WandererRouteService::MAX_TIMESTAMP_AGE_SECONDS - 1);
        $rawPayload = '{"event":"connection_added"}';

        $signature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

        $this->discordWebhookService->expects($this->any())
            ->method('getWandererSecret')
            ->willReturn($secret);

        $this->assertFalse($this->service->verifySignature($rawPayload, $signature, $timestamp));
        $this->assertFalse($this->service->verifySignature($rawPayload, $signature, 'not-a-number'));
    }

    public function testVerifySignatureRejectsWhenNoSecretConfigured(): void
    {
        $this->discordWebhookService->expects($this->any())
            ->method('getWandererSecret')
            ->willReturn(null);

        $this->assertFalse($this->service->verifySignature('{}', 'any', (string) time()));
    }

    public function testProcessWebhookPayloadMatchesCorpRule(): void
    {
        $payload = [
            'event' => 'connection_added',
            'solar_system_source' => 31000001, // J154944 (Wormhole)
            'solar_system_target' => 30000144, // Perimeter (Highsec)
            'from_name' => 'J154944',
            'to_name' => 'Perimeter',
            'map_name' => 'Home Chain',
            'character_name' => 'Pilot Alpha',
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

        $result = $this->service->processWebhookPayload($payload);

        $this->assertSame(2, $result['processed_systems']);
        $this->assertSame(1, $result['matched_rules']);
        $this->assertSame(1, $result['corp_notifications']);
        $this->assertSame(0, $result['user_notifications']);
        $this->assertNotNull($rule->getLastTriggeredAt());
    }

    public function testVerifySignatureWithSha256Prefix(): void
    {
        $secret = 'test_secret_prefix';
        $timestamp = (string) time();
        $rawPayload = '{"event":"connection_added"}';

        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

        $this->discordWebhookService->expects($this->any())
            ->method('getWandererSecret')
            ->willReturn($secret);

        $this->assertTrue($this->service->verifySignature($rawPayload, $signature, $timestamp));
    }

    public function testProcessWebhookPayloadWithNestedWandererFormat(): void
    {
        $payload = [
            'type' => 'connection_added',
            'map_id' => 'map-uuid-123',
            'payload' => [
                'solar_system_source' => 31000001,
                'solar_system_target' => 30000144,
                'from_name' => 'J154944',
                'to_name' => 'Perimeter',
            ],
        ];

        $rule = new WandererRouteRule();
        $rule->setName('Jita Express');
        $rule->setTargetSolarSystemId(30000142);
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
            ->willReturn([30000144, 30000142]);

        $this->discordWebhookService->expects($this->once())
            ->method('send')
            ->willReturn(true);

        $result = $this->service->processWebhookPayload($payload);

        $this->assertSame(2, $result['processed_systems']);
        $this->assertSame(1, $result['matched_rules']);
    }

    public function testProcessWebhookPayloadIgnoresAddSystemEvent(): void
    {
        $payload = [
            'event' => 'add_system',
            'solar_system_id' => 30000144, // Perimeter manually added without connection
            'name' => 'Perimeter',
        ];

        $this->ruleRepository->expects($this->never())
            ->method('findActiveCorpRules');

        $result = $this->service->processWebhookPayload($payload);

        $this->assertSame('add_system', $result['event']);
        $this->assertSame(0, $result['processed_systems']);
        $this->assertSame(0, $result['matched_rules']);
        $this->assertSame(0, $result['corp_notifications']);
    }

    public function testProcessWebhookPayloadIgnoresKSpaceStargateConnection(): void
    {
        $payload = [
            'event' => 'connection_added',
            'solar_system_source' => 30000144, // Perimeter (Highsec)
            'solar_system_target' => 30000142, // Jita (Highsec)
            'type' => 1, // Stargate connection
            'from_name' => 'Perimeter',
            'to_name' => 'Jita',
        ];

        $this->ruleRepository->expects($this->never())
            ->method('findActiveCorpRules');

        $result = $this->service->processWebhookPayload($payload);

        $this->assertSame('connection_added', $result['event']);
        $this->assertSame(0, $result['processed_systems']);
        $this->assertSame(0, $result['matched_rules']);
        $this->assertSame(0, $result['corp_notifications']);
    }
}
