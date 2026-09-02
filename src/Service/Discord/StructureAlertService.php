<?php

namespace App\Service\Discord;

use App\Entity\DiscordNotificationLog;
use App\Entity\EveCorporationStarbase;
use App\Entity\EveCorporationStructure;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StructureAlertService
{
    /**
     * Fuel warning thresholds in days (descending order).
     */
    private const FUEL_THRESHOLDS = [30, 14, 7, 3, 1, 0];

    /**
     * Minimum gain in seconds (6 hours) to trigger a Refuel notification.
     */
    private const REFUEL_THRESHOLD_SECONDS = 21600; // 6 hours

    public function __construct(
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Evaluates fuel status and state transitions for an Upwell structure.
     */
    public function checkUpwellStructure(EveCorporationStructure $structure): void
    {
        $now = new \DateTimeImmutable();
        $fuelExpires = $structure->getFuelExpires();
        $previousFuelExpires = $structure->getPreviousFuelExpires();
        $currentState = $structure->getState() ?? 'unknown';
        $previousState = $structure->getPreviousState();
        $lastAlertDays = $structure->getLastFuelAlertDays();

        $structureName = $structure->getName() ?: sprintf('Struktur #%s', $structure->getId());
        $typeName = $structure->getTypeName() ?: 'Upwell Structure';
        $systemName = $structure->getSolarSystemName() ?: sprintf('System #%d', $structure->getSolarSystemId());

        if ($fuelExpires !== null) {
            $daysRemainingFloat = ($fuelExpires->getTimestamp() - $now->getTimestamp()) / 86400.0;
            $daysRemaining = max(0, (int) round($daysRemainingFloat));

            // 1. Detect Refueling (Fuel expiry extended by >= 6 hours)
            if ($previousFuelExpires !== null) {
                $diffSeconds = $fuelExpires->getTimestamp() - $previousFuelExpires->getTimestamp();

                if ($diffSeconds >= self::REFUEL_THRESHOLD_SECONDS) {
                    $gainedDays = round($diffSeconds / 86400.0, 1);
                    $gainedHours = round($diffSeconds / 3600.0, 1);

                    $this->sendRefueledNotification(
                        $structureName,
                        $typeName,
                        $systemName,
                        $daysRemaining,
                        $fuelExpires,
                        $gainedDays,
                        $gainedHours,
                        $structure->getId(),
                        'structure'
                    );

                    // Reset alert threshold based on new fuel level
                    $newThreshold = $this->resolveCurrentThreshold($daysRemainingFloat);
                    $structure->setLastFuelAlertDays($newThreshold);
                    $lastAlertDays = $newThreshold;
                }
            }

            // 2. Check Fuel Level Threshold Alerts (<= 30d, 14d, 7d, 3d, 1d, 0d)
            $currentThreshold = $this->resolveCurrentThreshold($daysRemainingFloat);

            if ($currentThreshold !== null) {
                // Only send alert if we haven't alerted for this threshold (or lower) yet
                if ($lastAlertDays === null || $lastAlertDays > $currentThreshold) {
                    $this->sendFuelAlertNotification(
                        $structureName,
                        $typeName,
                        $systemName,
                        $daysRemainingFloat,
                        $fuelExpires,
                        $currentThreshold,
                        $structure->getId(),
                        'structure'
                    );

                    $structure->setLastFuelAlertDays($currentThreshold);
                }
            } else {
                // Above 30 days -> ensure alert state is cleared
                $structure->setLastFuelAlertDays(null);
            }
        }

        // 3. Detect State Changes (e.g. online -> offline, armor_reinforce, unanchoring)
        if ($previousState !== null && $previousState !== $currentState) {
            $this->sendStateChangeNotification(
                $structureName,
                $typeName,
                $systemName,
                $previousState,
                $currentState,
                $structure->getId(),
                'structure'
            );
        }

        // Update tracking fields for next run
        $structure->setPreviousFuelExpires($fuelExpires);
        $structure->setPreviousState($currentState);
    }

    /**
     * Evaluates fuel and state for a Starbase (POS Control Tower).
     */
    public function checkStarbase(EveCorporationStarbase $starbase): void
    {
        $now = new \DateTimeImmutable();
        $currentState = $starbase->getState() ?? 'offline';
        $previousState = $starbase->getPreviousState();
        $fuels = $starbase->getFuels() ?? [];

        $typeName = $starbase->getTypeName() ?: 'Control Tower';
        $systemName = $starbase->getSolarSystemName() ?: sprintf('System #%d', $starbase->getSolarSystemId());
        $starbaseName = sprintf('%s (%s)', $typeName, $systemName);

        // Approximate POS hourly consumption (Standard large = 40, medium = 20, small = 10)
        $hourlyRate = 40;
        if (stripos($typeName, 'medium') !== false) {
            $hourlyRate = 20;
        } elseif (stripos($typeName, 'small') !== false) {
            $hourlyRate = 10;
        }

        $totalFuelBlocks = 0;
        foreach ($fuels as $fuelItem) {
            if (stripos($fuelItem['typeName'] ?? '', 'block') !== false) {
                $totalFuelBlocks += (int)($fuelItem['quantity'] ?? 0);
            }
        }

        if ($currentState === 'online' && $totalFuelBlocks > 0) {
            $hoursLeft = $totalFuelBlocks / $hourlyRate;
            $daysLeftFloat = $hoursLeft / 24.0;
            $fuelExpiresApprox = $now->modify(sprintf('+%d hours', (int)$hoursLeft));

            $lastAlertDays = $starbase->getLastFuelAlertDays();
            $previousFuelExpires = $starbase->getPreviousFuelExpires();

            // Refuel detection
            if ($previousFuelExpires !== null) {
                $diffSeconds = $fuelExpiresApprox->getTimestamp() - $previousFuelExpires->getTimestamp();

                if ($diffSeconds >= self::REFUEL_THRESHOLD_SECONDS) {
                    $gainedDays = round($diffSeconds / 86400.0, 1);
                    $gainedHours = round($diffSeconds / 3600.0, 1);
                    $daysRemaining = (int) round($daysLeftFloat);

                    $this->sendRefueledNotification(
                        $starbaseName,
                        $typeName,
                        $systemName,
                        $daysRemaining,
                        $fuelExpiresApprox,
                        $gainedDays,
                        $gainedHours,
                        $starbase->getId(),
                        'starbase'
                    );

                    $newThreshold = $this->resolveCurrentThreshold($daysLeftFloat);
                    $starbase->setLastFuelAlertDays($newThreshold);
                    $lastAlertDays = $newThreshold;
                }
            }

            // Check Fuel Thresholds
            $currentThreshold = $this->resolveCurrentThreshold($daysLeftFloat);

            if ($currentThreshold !== null) {
                if ($lastAlertDays === null || $lastAlertDays > $currentThreshold) {
                    $this->sendFuelAlertNotification(
                        $starbaseName,
                        $typeName,
                        $systemName,
                        $daysLeftFloat,
                        $fuelExpiresApprox,
                        $currentThreshold,
                        $starbase->getId(),
                        'starbase'
                    );
                    $starbase->setLastFuelAlertDays($currentThreshold);
                }
            } else {
                $starbase->setLastFuelAlertDays(null);
            }

            $starbase->setPreviousFuelExpires($fuelExpiresApprox);
        }

        // State change detection
        if ($previousState !== null && $previousState !== $currentState) {
            $this->sendStateChangeNotification(
                $starbaseName,
                $typeName,
                $systemName,
                $previousState,
                $currentState,
                $starbase->getId(),
                'starbase'
            );
        }

        $starbase->setPreviousState($currentState);
    }

    /**
     * Resolves the smallest matching threshold or null if above 30 days.
     */
    private function resolveCurrentThreshold(float $daysRemainingFloat): ?int
    {
        $currentThreshold = null;
        foreach (self::FUEL_THRESHOLDS as $threshold) {
            if ($daysRemainingFloat <= $threshold) {
                $currentThreshold = $threshold;
            }
        }
        return $currentThreshold;
    }

    private function sendFuelAlertNotification(
        string $name,
        string $typeName,
        string $systemName,
        float $daysLeftFloat,
        \DateTimeImmutable $fuelExpires,
        int $threshold,
        string $entityId,
        string $entityType
    ): void {
        $color = match (true) {
            $threshold <= 1 => DiscordColor::DARK_RED,
            $threshold <= 3 => DiscordColor::RED,
            $threshold <= 7 => DiscordColor::ORANGE,
            $threshold <= 14 => DiscordColor::YELLOW,
            default => DiscordColor::GOLD,
        };

        $urgencyPrefix = match (true) {
            $threshold <= 1 => '🚨 [KRITISCH]',
            $threshold <= 3 => '⚠️ [DRINGEND]',
            $threshold <= 7 => '⚠️ [WARNUNG]',
            default => '⛽ [TREIBSTOFF]',
        };

        $daysText = $daysLeftFloat < 1.0
            ? sprintf('%.1f Stunden', max(0, $daysLeftFloat * 24))
            : sprintf('%.1f Tage (%d Tage)', $daysLeftFloat, (int)floor($daysLeftFloat));

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('%s Treibstoff-Warnung: %s', $urgencyPrefix, $name))
            ->setColor($color)
            ->setDescription(sprintf('Der Treibstoff für **%s** neigt sich dem Ende zu und hat den Schwellenwert von **≤ %d Tagen** erreicht.', $name, $threshold))
            ->addField('🏢 Struktur', sprintf('%s (%s)', $name, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true)
            ->addField('⏳ Verbleibende Zeit', $daysText, true)
            ->addField('📅 Läuft ab am', $fuelExpires->format('d.m.Y H:i') . ' EVE Time', true)
            ->setFooter('Keepers of Duat • Structure Fuel Monitor')
            ->setTimestamp(new \DateTimeImmutable());

        $content = null;
        $fuelPing = $this->discordWebhookService->getFuelPing();
        if ($threshold <= 7 && $fuelPing) {
            $content = $fuelPing;
        }

        $message = DiscordMessage::create($content)
            ->setUsername('Structure Fuel Monitor')
            ->addEmbed($embed);

        $sent = $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_FUEL);

        if ($sent) {
            $log = new DiscordNotificationLog();
            $log->setChannel(DiscordWebhookService::CHANNEL_FUEL);
            $log->setType('FuelAlert');
            $log->setEntityType($entityType);
            $log->setEntityId($entityId);
            $log->setAlertLevel((string)$threshold);
            $log->setMetadata([
                'name' => $name,
                'system' => $systemName,
                'days_left' => $daysLeftFloat,
                'expires_at' => $fuelExpires->format(\DateTimeInterface::ATOM),
            ]);
            $this->entityManager->persist($log);
        }
    }

    private function sendRefueledNotification(
        string $name,
        string $typeName,
        string $systemName,
        int $daysRemaining,
        \DateTimeImmutable $fuelExpires,
        float $gainedDays,
        float $gainedHours,
        string $entityId,
        string $entityType
    ): void {
        $gainedText = $gainedDays >= 1.0
            ? sprintf('+%.1f Tage (+%d Std.)', $gainedDays, (int)round($gainedHours))
            : sprintf('+%.1f Std.', $gainedHours);

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('✅ [BETANKT] Treibstoff aufgefüllt: %s', $name))
            ->setColor(DiscordColor::GREEN)
            ->setDescription(sprintf('**%s** wurde erfolgreich nachgetankt!', $name))
            ->addField('🏢 Struktur', sprintf('%s (%s)', $name, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true)
            ->addField('📈 Nachgetankt', $gainedText, true)
            ->addField('⏳ Neue Restlaufzeit', sprintf('~%d Tage', $daysRemaining), true)
            ->addField('📅 Läuft ab am', $fuelExpires->format('d.m.Y H:i') . ' EVE Time', true)
            ->setFooter('Keepers of Duat • Structure Fuel Monitor')
            ->setTimestamp(new \DateTimeImmutable());

        $message = DiscordMessage::create()
            ->setUsername('Structure Fuel Monitor')
            ->addEmbed($embed);

        $sent = $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_FUEL);

        if ($sent) {
            $log = new DiscordNotificationLog();
            $log->setChannel(DiscordWebhookService::CHANNEL_FUEL);
            $log->setType('FuelRefueled');
            $log->setEntityType($entityType);
            $log->setEntityId($entityId);
            $log->setAlertLevel('refueled');
            $log->setMetadata([
                'name' => $name,
                'system' => $systemName,
                'days_remaining' => $daysRemaining,
                'gained_days' => $gainedDays,
                'expires_at' => $fuelExpires->format(\DateTimeInterface::ATOM),
            ]);
            $this->entityManager->persist($log);
        }
    }

    private function sendStateChangeNotification(
        string $name,
        string $typeName,
        string $systemName,
        string $oldState,
        string $newState,
        string $entityId,
        string $entityType
    ): void {
        $isDanger = in_array($newState, ['offline', 'armor_reinforce', 'hull_reinforce', 'unanchoring'], true);
        $color = $isDanger ? DiscordColor::RED : DiscordColor::BLUE;

        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('🔄 [STATUS] Statusänderung: %s', $name))
            ->setColor($color)
            ->setDescription(sprintf('Der Status von **%s** hat sich geändert.', $name))
            ->addField('🏢 Struktur', sprintf('%s (%s)', $name, $typeName), true)
            ->addField('🌌 Sonnensystem', $systemName, true)
            ->addField('⬅️ Vorheriger Status', strtoupper($oldState), true)
            ->addField('➡️ Neuer Status', strtoupper($newState), true)
            ->setFooter('Keepers of Duat • Structure Monitor')
            ->setTimestamp(new \DateTimeImmutable());

        $message = DiscordMessage::create()
            ->setUsername('Structure Monitor')
            ->addEmbed($embed);

        $sent = $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_STRUCTURES);

        if ($sent) {
            $log = new DiscordNotificationLog();
            $log->setChannel(DiscordWebhookService::CHANNEL_STRUCTURES);
            $log->setType('StateChange');
            $log->setEntityType($entityType);
            $log->setEntityId($entityId);
            $log->setAlertLevel($newState);
            $log->setMetadata([
                'name' => $name,
                'system' => $systemName,
                'old_state' => $oldState,
                'new_state' => $newState,
            ]);
            $this->entityManager->persist($log);
        }
    }
}
