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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
        $timestamp = '1727271600';
        $rawPayload = '{"event":"add_system"}';

        $validSignature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

        $this->discordWebhookService->expects($this->any())
            ->method('getWandererSecret')
            ->willReturn($secret);

        $this->assertTrue($this->service->verifySignature($rawPayload, $validSignature, $timestamp));
        $this->assertFalse($this->service->verifySignature($rawPayload, 'invalid_sig', $timestamp));
    }

    public function testProcessWebhookPayloadMatchesCorpRule(): void
    {
        $payload = [
            'event' => 'add_system',
            'solar_system_id' => 30000144, // Perimeter
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

        $this->assertSame(1, $result['processed_systems']);
        $this->assertSame(1, $result['matched_rules']);
        $this->assertSame(1, $result['corp_notifications']);
        $this->assertSame(0, $result['user_notifications']);
        $this->assertNotNull($rule->getLastTriggeredAt());
    }
}
