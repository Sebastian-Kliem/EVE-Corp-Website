<?php

namespace App\Service\Discord;

use App\Entity\EveCorporationStructure;
use App\Entity\EveStructure;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class StructureNotificationParser
{
    public function __construct(
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    public function parseNotification(array $notification): ?DiscordMessage
    {
        $type = $notification['type'] ?? '';
        $text = $notification['text'] ?? '';
        $sentDateStr = $notification['sent_date'] ?? null;
        $sentDate = $sentDateStr ? new \DateTimeImmutable($sentDateStr) : new \DateTimeImmutable();

        $data = [];
        if (!empty($text)) {
            try {
                $parsed = Yaml::parse($text);
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Discord] Failed to parse YAML notification: %s', $e->getMessage()));
            }
        }

        return match ($type) {
            'StructureUnderAttack' => $this->handleStructureUnderAttack($data, $sentDate),
            'StructureLostShields' => $this->handleStructureReinforce($data, $sentDate, 'Schild gefallen – Panzerungs-Timer aktiv', DiscordColor::ORANGE),
            'StructureLostArmor' => $this->handleStructureReinforce($data, $sentDate, 'Panzerung gefallen – Finaler Rumpf-Timer aktiv', DiscordColor::DARK_RED, true),
            'StructureWentLowPower' => $this->handleSimpleStructureEvent($data, $sentDate, '⚠️ [LOW POWER] Kein Treibstoff mehr', 'Die Struktur ist in den Low-Power-Modus gewechselt.', DiscordColor::ORANGE),
            'StructureWentHighPower' => $this->handleSimpleStructureEvent($data, $sentDate, '✅ [HIGH POWER] Struktur aktiv', 'Die Struktur ist wieder im High-Power-Modus.', DiscordColor::GREEN),
            'StructureFuelAlert' => $this->handleFuelAlertEvent($data, $sentDate),
            'StructureServicesOffline' => $this->handleSimpleStructureEvent($data, $sentDate, '⚠️ [SERVICES OFFLINE] Dienste ausgefallen', 'Mindestens ein Dienstmodul der Struktur ist offline gegangen.', DiscordColor::ORANGE),
            'StructureUnanchoring' => $this->handleUnanchoringEvent($data, $sentDate),
            'StructureDestroyed' => $this->handleSimpleStructureEvent($data, $sentDate, '💥 [ZERSTÖRT] Struktur zerstört', 'Die Struktur wurde im Kampf zerstört.', DiscordColor::DARK_RED, true),
            'TowerAlertMsg' => $this->handleTowerAlert($data, $sentDate),
            'TowerResourceAlertMsg' => $this->handleTowerResourceAlert($data, $sentDate),
            'OrbitalAttacked' => $this->handleOrbitalAttacked($data, $sentDate),
            'OrbitalReinforced' => $this->handleOrbitalReinforced($data, $sentDate),
            default => null,
        };
    }

    private function handleStructureUnderAttack(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $structureId = (string)($data['structureID'] ?? '');
        $typeId = (int)($data['structureTypeID'] ?? 0);
        $systemId = (int)($data['solarsystemID'] ?? 0);

        $structureName = $this->resolveStructureName($structureId, $typeId);
        $typeName = $this->sdeService->getItemName($typeId) ?: 'Upwell Structure';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);

        $shield = isset($data['shieldPercentage']) ? round(((float)$data['shieldPercentage']) * 100, 1) : null;
        $armor = isset($data['armorPercentage']) ? round(((float)$data['armorPercentage']) * 100, 1) : null;
        $hull = isset($data['hullPercentage']) ? round(((float)$data['hullPercentage']) * 100, 1) : null;

        $attackerCharId = (int)($data['aggressorID'] ?? 0);
        $attackerCorpId = (int)($data['aggressorCorpID'] ?? 0);
        $attackerAllianceId = (int)($data['aggressorAllianceID'] ?? 0);
        $attackerNames = $this->resolveNames(array_filter([$attackerCharId, $attackerCorpId, $attackerAllianceId]));

        $attackerChar = $attackerNames[$attackerCharId] ?? ($attackerCharId ? (string)$attackerCharId : 'Unbekannt');
        $attackerCorp = $attackerNames[$attackerCorpId] ?? null;
        $attackerAlliance = $attackerNames[$attackerAllianceId] ?? null;

        $attackerFormatted = $attackerChar;
        if ($attackerCorp) {
            $attackerFormatted .= sprintf(' [%s]', $attackerCorp);
        }
        if ($attackerAlliance) {
            $attackerFormatted .= sprintf(' <%s>', $attackerAlliance);
        }

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('🚨 [ALARM] Station wird angegriffen: %s', $structureName))
            ->setColor(DiscordColor::RED)
            ->setDescription(sprintf('Die Struktur **%s** im System **%s** wird aktuell attackiert!', $structureName, $systemName))
            ->addField('🏢 Struktur', sprintf('%s (%s)', $structureName, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true)
            ->addField('⚔️ Angreifer', $attackerFormatted, false);

        $statusParts = [];
        if ($shield !== null) $statusParts[] = sprintf('🛡️ Schild: %.1f%%', $shield);
        if ($armor !== null) $statusParts[] = sprintf('🛡️ Panzerung: %.1f%%', $armor);
        if ($hull !== null) $statusParts[] = sprintf('💥 Struktur: %.1f%%', $hull);

        if (!empty($statusParts)) {
            $embed->addField('📊 Status', implode(' | ', $statusParts), false);
        }

        $embed->setFooter('Keepers of Duat • Structure Defense Alert')
            ->setTimestamp($sentDate);

        $ping = $this->discordWebhookService->getStructureDefensePing();

        return DiscordMessage::create($ping)
            ->setUsername('Structure Defense Alert')
            ->addEmbed($embed);
    }

    private function handleStructureReinforce(
        array $data,
        \DateTimeImmutable $sentDate,
        string $titleSuffix,
        int $color,
        bool $isFinal = false
    ): DiscordMessage {
        $structureId = (string)($data['structureID'] ?? '');
        $typeId = (int)($data['structureTypeID'] ?? 0);
        $systemId = (int)($data['solarsystemID'] ?? 0);

        $structureName = $this->resolveStructureName($structureId, $typeId);
        $typeName = $this->sdeService->getItemName($typeId) ?: 'Upwell Structure';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('⚠️ [VERSTÄRKUNG] %s: %s', $titleSuffix, $structureName))
            ->setColor($color)
            ->setDescription(sprintf('Die Struktur **%s** hat eine Verstärkungsphase (Reinforce) begonnen.', $structureName))
            ->addField('🏢 Struktur', sprintf('%s (%s)', $structureName, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true);

        if (isset($data['timeLeft'])) {
            $seconds = $this->convertTimeLeftToSeconds($data['timeLeft']);
            if ($seconds > 0) {
                $exitTime = $sentDate->modify(sprintf('+%d seconds', (int)$seconds));
                $hours = floor($seconds / 3600);
                $mins = floor(($seconds % 3600) / 60);
                $embed->addField('⏳ Timer-Dauer', sprintf('~%d Std. %d Min.', $hours, $mins), true);
                $embed->addField('🎯 Verwundbar ab', $exitTime->format('d.m.Y H:i') . ' EVE Time', true);
            }
        }

        $embed->setFooter('Keepers of Duat • Structure Defense Alert')
            ->setTimestamp($sentDate);

        $ping = $this->discordWebhookService->getStructureDefensePing();

        return DiscordMessage::create($isFinal ? $ping : null)
            ->setUsername('Structure Defense Alert')
            ->addEmbed($embed);
    }

    private function handleSimpleStructureEvent(
        array $data,
        \DateTimeImmutable $sentDate,
        string $title,
        string $description,
        int $color,
        bool $pingDefense = false
    ): DiscordMessage {
        $structureId = (string)($data['structureID'] ?? '');
        $typeId = (int)($data['structureTypeID'] ?? 0);
        $systemId = (int)($data['solarsystemID'] ?? 0);

        $structureName = $this->resolveStructureName($structureId, $typeId);
        $typeName = $this->sdeService->getItemName($typeId) ?: 'Upwell Structure';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('%s: %s', $title, $structureName))
            ->setColor($color)
            ->setDescription($description)
            ->addField('🏢 Struktur', sprintf('%s (%s)', $structureName, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true)
            ->setFooter('Keepers of Duat • Structure Monitor')
            ->setTimestamp($sentDate);

        $content = $pingDefense ? $this->discordWebhookService->getStructureDefensePing() : null;

        return DiscordMessage::create($content)
            ->setUsername('Structure Monitor')
            ->addEmbed($embed);
    }

    private function handleFuelAlertEvent(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $structureId = (string)($data['structureID'] ?? '');
        $typeId = (int)($data['structureTypeID'] ?? 0);
        $systemId = (int)($data['solarsystemID'] ?? 0);

        $structureName = $this->resolveStructureName($structureId, $typeId);
        $typeName = $this->sdeService->getItemName($typeId) ?: 'Upwell Structure';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('⛽ [ESI FUEL ALERT] Treibstoff-Warnung: %s', $structureName))
            ->setColor(DiscordColor::YELLOW)
            ->setDescription('EVE Online meldet einen niedrigen Treibstoffbestand für diese Struktur.')
            ->addField('🏢 Struktur', sprintf('%s (%s)', $structureName, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true)
            ->setFooter('Keepers of Duat • Structure Fuel Monitor')
            ->setTimestamp($sentDate);

        return DiscordMessage::create()
            ->setUsername('Structure Fuel Monitor')
            ->addEmbed($embed);
    }

    private function handleUnanchoringEvent(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $structureId = (string)($data['structureID'] ?? '');
        $typeId = (int)($data['structureTypeID'] ?? 0);
        $systemId = (int)($data['solarsystemID'] ?? 0);

        $structureName = $this->resolveStructureName($structureId, $typeId);
        $typeName = $this->sdeService->getItemName($typeId) ?: 'Upwell Structure';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('📦 [ABBAU] Struktur wird abgebaut: %s', $structureName))
            ->setColor(DiscordColor::PURPLE)
            ->setDescription(sprintf('Der Abbau (Unanchoring) von **%s** wurde gestartet.', $structureName))
            ->addField('🏢 Struktur', sprintf('%s (%s)', $structureName, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true);

        if (isset($data['timeLeft'])) {
            $seconds = $this->convertTimeLeftToSeconds($data['timeLeft']);
            if ($seconds > 0) {
                $unanchorAt = $sentDate->modify(sprintf('+%d seconds', (int)$seconds));
                $embed->addField('📅 Bereit zum Einsammeln am', $unanchorAt->format('d.m.Y H:i') . ' EVE Time', true);
            }
        }

        $embed->setFooter('Keepers of Duat • Structure Monitor')
            ->setTimestamp($sentDate);

        return DiscordMessage::create()
            ->setUsername('Structure Monitor')
            ->addEmbed($embed);
    }

    private function handleTowerAlert(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $systemId = (int)($data['solarSystemID'] ?? 0);
        $typeId = (int)($data['typeID'] ?? 0);
        $moonId = (int)($data['moonID'] ?? 0);

        $typeName = $this->sdeService->getItemName($typeId) ?: 'Control Tower';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);
        $moonName = $this->sdeService->getLocationName($moonId) ?: sprintf('Moon #%d', $moonId);

        $attackerCharId = (int)($data['aggressorID'] ?? 0);
        $attackerCorpId = (int)($data['aggressorCorpID'] ?? 0);
        $attackerAllianceId = (int)($data['aggressorAllianceID'] ?? 0);
        $attackerNames = $this->resolveNames(array_filter([$attackerCharId, $attackerCorpId, $attackerAllianceId]));

        $attackerFormatted = $attackerNames[$attackerCharId] ?? ($attackerCharId ? (string)$attackerCharId : 'Unbekannt');
        if (isset($attackerNames[$attackerCorpId])) {
            $attackerFormatted .= sprintf(' [%s]', $attackerNames[$attackerCorpId]);
        }

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('🚨 [POS ALARM] Starbase unter Beschuss in %s', $systemName))
            ->setColor(DiscordColor::RED)
            ->setDescription(sprintf('Ein Kontrollturm (**%s**) bei **%s** wird angegriffen!', $typeName, $moonName))
            ->addField('🏢 Typ', $typeName, true)
            ->addField('🌌 Ort', sprintf('%s (%s)', $systemName, $moonName), true)
            ->addField('⚔️ Angreifer', $attackerFormatted, false)
            ->setFooter('Keepers of Duat • Starbase Defense')
            ->setTimestamp($sentDate);

        $ping = $this->discordWebhookService->getStructureDefensePing();

        return DiscordMessage::create($ping)
            ->setUsername('Starbase Defense Alert')
            ->addEmbed($embed);
    }

    private function handleTowerResourceAlert(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $systemId = (int)($data['solarSystemID'] ?? 0);
        $typeId = (int)($data['typeID'] ?? 0);
        $moonId = (int)($data['moonID'] ?? 0);

        $typeName = $this->sdeService->getItemName($typeId) ?: 'Control Tower';
        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);
        $moonName = $this->sdeService->getLocationName($moonId) ?: sprintf('Moon #%d', $moonId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('⛽ [POS FUEL] Treibstoff-Mangel in %s', $systemName))
            ->setColor(DiscordColor::ORANGE)
            ->setDescription(sprintf('Der Kontrollturm (**%s**) bei **%s** benötigt dringend Treibstoff!', $typeName, $moonName))
            ->addField('🏢 Typ', $typeName, true)
            ->addField('🌌 Ort', sprintf('%s (%s)', $systemName, $moonName), true)
            ->setFooter('Keepers of Duat • POS Monitor')
            ->setTimestamp($sentDate);

        return DiscordMessage::create()
            ->setUsername('POS Fuel Monitor')
            ->addEmbed($embed);
    }

    private function handleOrbitalAttacked(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $systemId = (int)($data['solarSystemID'] ?? 0);
        $planetId = (int)($data['planetID'] ?? 0);

        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);
        $planetName = $this->sdeService->getLocationName($planetId) ?: sprintf('Planet #%d', $planetId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('🚨 [POCO ALARM] Zollamt unter Beschuss in %s', $systemName))
            ->setColor(DiscordColor::RED)
            ->setDescription(sprintf('Ein Zollamt (POCO) bei **%s** im System **%s** wird attackiert!', $planetName, $systemName))
            ->addField('🏢 Typ', 'Customs Office (POCO)', true)
            ->addField('🌌 Ort', sprintf('%s (%s)', $systemName, $planetName), true)
            ->setFooter('Keepers of Duat • Orbital Defense')
            ->setTimestamp($sentDate);

        $ping = $this->discordWebhookService->getStructureDefensePing();

        return DiscordMessage::create($ping)
            ->setUsername('Orbital Defense Alert')
            ->addEmbed($embed);
    }

    private function handleOrbitalReinforced(array $data, \DateTimeImmutable $sentDate): DiscordMessage
    {
        $systemId = (int)($data['solarSystemID'] ?? 0);
        $planetId = (int)($data['planetID'] ?? 0);

        $systemName = $this->sdeService->getLocationName($systemId) ?: sprintf('System #%d', $systemId);
        $planetName = $this->sdeService->getLocationName($planetId) ?: sprintf('Planet #%d', $planetId);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('⚠️ [POCO VERSTÄRKUNG] Zollamt im Reinforce in %s', $systemName))
            ->setColor(DiscordColor::ORANGE)
            ->setDescription(sprintf('Das Zollamt bei **%s** im System **%s** wurde in die Verstärkung geschossen.', $planetName, $systemName))
            ->addField('🏢 Typ', 'Customs Office (POCO)', true)
            ->addField('🌌 Ort', sprintf('%s (%s)', $systemName, $planetName), true)
            ->setFooter('Keepers of Duat • Orbital Defense')
            ->setTimestamp($sentDate);

        return DiscordMessage::create()
            ->setUsername('Orbital Defense Alert')
            ->addEmbed($embed);
    }

    private function resolveStructureName(string $structureId, int $typeId): string
    {
        if (empty($structureId)) {
            return $this->sdeService->getItemName($typeId) ?: 'Struktur';
        }

        $corpStructRepo = $this->entityManager->getRepository(EveCorporationStructure::class);
        $struct = $corpStructRepo->find($structureId);
        if ($struct && $struct->getName()) {
            return $struct->getName();
        }

        $globalStructRepo = $this->entityManager->getRepository(EveStructure::class);
        $globalStruct = $globalStructRepo->find($structureId);
        if ($globalStruct && $globalStruct->getName()) {
            return $globalStruct->getName();
        }

        $typeName = $this->sdeService->getItemName($typeId);
        return $typeName ? sprintf('%s #%s', $typeName, substr($structureId, -4)) : sprintf('Struktur #%s', $structureId);
    }

    private function resolveNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        try {
            $data = $this->esiClient->request('POST', 'universe/names/', [
                'json' => $ids
            ]);

            $names = [];
            if (is_array($data)) {
                foreach ($data as $entry) {
                    if (isset($entry['id'], $entry['name'])) {
                        $names[(int)$entry['id']] = (string)$entry['name'];
                    }
                }
            }
            return $names;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Discord] Failed to resolve universe names: %s', $e->getMessage()));
            return [];
        }
    }

    private function convertTimeLeftToSeconds(mixed $timeLeft): float
    {
        $val = (float)$timeLeft;
        if ($val > 100000000) {
            // EVE FileTime format (100ns intervals)
            return $val / 10000000.0;
        }
        return $val;
    }
}
