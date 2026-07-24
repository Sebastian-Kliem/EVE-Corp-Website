<?php

namespace App\Service;

use App\Service\SdeService;

class PiSimulationService
{
    public function __construct(
        private readonly SdeService $sdeService
    ) {}

    /**
     * Simulates the PI production and consumption for a list of characters' planets.
     */
    public function simulatePlanets(array $planets): array
    {
        $simulatedPlanets = [];
        foreach ($planets as $planet) {
            $simulatedPlanets[] = $this->simulatePlanet($planet);
        }
        return $simulatedPlanets;
    }

    /**
     * Simulates the PI production and consumption for a single planet.
     */
    private function simulatePlanet(array $planet): array
    {
        // If we don't have the raw routes in the database, we cannot simulate the flow.
        if (empty($planet['routes']) || empty($planet['last_update'])) {
            return $planet;
        }

        $lastUpdateTs = strtotime($planet['last_update']);
        $nowTs = time();
        $duration = $nowTs - $lastUpdateTs;

        if ($duration <= 0) {
            return $planet;
        }

        // Cap simulation to 14 days max (after 14 days extractors are dead and factories are out of inputs)
        $duration = min($duration, 14 * 86400);
        $endTs = $lastUpdateTs + $duration;

        // Step size of 15 minutes (900 seconds) matches factory cycles (30 min and 60 min)
        $stepSize = 900;

        // 1. Initialize pin states
        $pins = [];
        foreach ($planet['pins'] as $pin) {
            $pinId = $pin['pin_id'];
            
            // Map inventory of typeID => quantity
            $inventory = [];
            if (!empty($pin['contents'])) {
                foreach ($pin['contents'] as $item) {
                    $inventory[(int)$item['type_id']] = (int)$item['quantity'];
                }
            }

            // Capacities based on EVE Online values
            $capacity = match ($pin['category']) {
                'launchpad' => 10000.0,
                'storage' => 40000.0,
                'command_center' => 10000.0,
                default => 99999999.0, // Extractors and factories have virtually unlimited transit capacity
            };

            $pins[$pinId] = [
                'pin_id' => $pinId,
                'name' => $pin['name'],
                'category' => $pin['category'],
                'inventory' => $inventory,
                'capacity' => $capacity,
                'extractor_info' => $pin['extractor_info'],
                'factory_info' => $pin['factory_info'],
                'expiry_time' => !empty($pin['expiry_time']) ? strtotime($pin['expiry_time']) : null,
                'cycle_ends_at' => null,
                'cycle_in_progress' => false,
                'last_cycle_start' => !empty($pin['last_cycle_start']) ? strtotime($pin['last_cycle_start']) : null,
            ];

            // If it is a factory, initialize its cycle state
            if ($pin['category'] === 'factory' && $pin['factory_info']) {
                $cycleTime = (int)$pin['factory_info']['cycle_time'];
                $lastStart = $pins[$pinId]['last_cycle_start'];
                if ($lastStart && ($lastStart + $cycleTime > $lastUpdateTs)) {
                    $pins[$pinId]['cycle_ends_at'] = $lastStart + $cycleTime;
                    $pins[$pinId]['cycle_in_progress'] = true;
                }
            }
        }

        // 2. Build incoming and outgoing route mappings to prevent route overwriting and support multiple destinations
        $incomingRoutes = [];
        $outgoingRoutes = [];
        foreach ($planet['routes'] as $route) {
            $src = (string)$route['source_pin_id'];
            $dst = (string)$route['destination_pin_id'];
            $typeId = (int)$route['content_type_id'];
            
            $incomingRoutes[$dst][$typeId][] = $src;
            $outgoingRoutes[$src][$typeId][] = $dst;
        }

        // Cache item volumes to prevent querying the database repeatedly
        $typeVolumes = [];
        $getVolume = function(int $typeId) use (&$typeVolumes): float {
            if (isset($typeVolumes[$typeId])) {
                return $typeVolumes[$typeId];
            }
            $volume = $this->sdeService->getItemVolume($typeId);
            $typeVolumes[$typeId] = $volume;
            return $volume;
        };

        $calculateOccupiedVolume = function(array $inventory) use ($getVolume): float {
            $vol = 0.0;
            foreach ($inventory as $typeId => $qty) {
                $vol += $qty * $getVolume($typeId);
            }
            return $vol;
        };

        $addToInventory = function(string $pinId, int $typeId, int $qty) use (&$pins, $calculateOccupiedVolume, $getVolume): int {
            if (!isset($pins[$pinId])) return 0;
            
            $itemVol = $getVolume($typeId);
            if ($itemVol <= 0) $itemVol = 0.01;

            $occupied = $calculateOccupiedVolume($pins[$pinId]['inventory']);
            $availableVol = $pins[$pinId]['capacity'] - $occupied;
            
            if ($availableVol <= 0) {
                return 0; // Buffer is full
            }

            $maxQty = (int)floor($availableVol / $itemVol);
            $qtyToAdd = min($qty, $maxQty);

            if ($qtyToAdd > 0) {
                if (!isset($pins[$pinId]['inventory'][$typeId])) {
                    $pins[$pinId]['inventory'][$typeId] = 0;
                }
                $pins[$pinId]['inventory'][$typeId] += $qtyToAdd;
            }

            return $qtyToAdd;
        };

        $removeFromInventory = function(string $pinId, int $typeId, int $qty) use (&$pins): int {
            if (!isset($pins[$pinId]) || !isset($pins[$pinId]['inventory'][$typeId])) {
                return 0;
            }

            $available = $pins[$pinId]['inventory'][$typeId];
            $qtyToRemove = min($qty, $available);

            $pins[$pinId]['inventory'][$typeId] -= $qtyToRemove;
            if ($pins[$pinId]['inventory'][$typeId] <= 0) {
                unset($pins[$pinId]['inventory'][$typeId]);
            }

            return $qtyToRemove;
        };

        // 3. Simulation Loop
        $t = $lastUpdateTs;
        while ($t < $endTs) {
            $dt = min($stepSize, $endTs - $t);
            $tNext = $t + $dt;

            // A. Extractors
            foreach ($pins as $pinId => &$pin) {
                if ($pin['category'] === 'extractor' && $pin['extractor_info']) {
                    $expiry = $pin['expiry_time'];
                    if ($expiry && $t < $expiry) {
                        $activeDuration = min($dt, $expiry - $t);
                        $ext = $pin['extractor_info'];
                        $prodTypeId = (int)$ext['product_type_id'];
                        $cycleTime = (int)$ext['cycle_time'];
                        $qtyPerCycle = (int)$ext['qty_per_cycle'];

                        if ($cycleTime > 0 && $prodTypeId > 0) {
                            $ratePerSec = $qtyPerCycle / $cycleTime;
                            $produced = (int)round($ratePerSec * $activeDuration);

                            // Trace destination pin ID
                            $destPinId = null;
                            if (isset($outgoingRoutes[(string)$pinId][$prodTypeId])) {
                                $destPinId = $outgoingRoutes[(string)$pinId][$prodTypeId][0];
                            }
                            if ($destPinId) {
                                $addToInventory($destPinId, $prodTypeId, $produced);
                            }
                        }
                    }
                }
            }
            unset($pin);

            // B. Factories
            foreach ($pins as $pinId => &$pin) {
                if ($pin['category'] === 'factory' && $pin['factory_info']) {
                    $fInfo = $pin['factory_info'];
                    $cycleTime = (int)$fInfo['cycle_time'];
                    $inputs = $fInfo['inputs'] ?? [];
                    $outputs = $fInfo['outputs'] ?? [];

                    // If factory is currently running, check if cycle completed
                    if ($pin['cycle_in_progress']) {
                        if ($pin['cycle_ends_at'] <= $tNext) {
                            // Cycle is complete! Deposit outputs
                            foreach ($outputs as $out) {
                                $outTypeId = (int)$out['type_id'];
                                $outQty = (int)$out['quantity'];

                                $destPinId = null;
                                if (isset($outgoingRoutes[(string)$pinId][$outTypeId])) {
                                    $destPinId = $outgoingRoutes[(string)$pinId][$outTypeId][0];
                                }
                                if (!$destPinId && isset($outgoingRoutes[(string)$pinId])) {
                                    $firstType = reset($outgoingRoutes[(string)$pinId]);
                                    $destPinId = $firstType[0] ?? null;
                                }

                                if ($destPinId) {
                                    $addToInventory($destPinId, $outTypeId, $outQty);
                                }
                            }
                            $pin['cycle_in_progress'] = false;
                            $pin['cycle_ends_at'] = null;
                        }
                    }

                    // Try to start a new cycle if idle
                    if (!$pin['cycle_in_progress']) {
                        $canStart = true;
                        $inputSources = []; // type_id => source_pin_id

                        foreach ($inputs as $inp) {
                            $inpTypeId = (int)$inp['type_id'];
                            $inpQty = (int)$inp['quantity'];

                            $sourcePinId = null;
                            if (isset($incomingRoutes[(string)$pinId][$inpTypeId])) {
                                foreach ($incomingRoutes[(string)$pinId][$inpTypeId] as $srcId) {
                                    if (isset($pins[$srcId]['inventory'][$inpTypeId]) && $pins[$srcId]['inventory'][$inpTypeId] >= $inpQty) {
                                        $sourcePinId = $srcId;
                                        break;
                                    }
                                }
                                if (!$sourcePinId) {
                                    $sourcePinId = $incomingRoutes[(string)$pinId][$inpTypeId][0];
                                }
                            }

                            if ($sourcePinId && isset($pins[$sourcePinId]['inventory'][$inpTypeId]) && $pins[$sourcePinId]['inventory'][$inpTypeId] >= $inpQty) {
                                $inputSources[$inpTypeId] = $sourcePinId;
                            } else {
                                $canStart = false;
                                break;
                            }
                        }

                        if ($canStart && !empty($inputs)) {
                            // Deduct inputs
                            foreach ($inputs as $inp) {
                                $inpTypeId = (int)$inp['type_id'];
                                $inpQty = (int)$inp['quantity'];
                                $srcPinId = $inputSources[$inpTypeId];
                                $removeFromInventory($srcPinId, $inpTypeId, $inpQty);
                            }

                            $pin['cycle_in_progress'] = true;
                            $pin['cycle_ends_at'] = $t + $cycleTime;
                        }
                    }
                }
            }
            unset($pin);

            $t = $tNext;
        }

        // 4. Update the contents arrays in the planet structure
        $simulatedPins = [];
        foreach ($planet['pins'] as $pin) {
            $pinId = $pin['pin_id'];
            $simPin = $pin;

            if (isset($pins[$pinId])) {
                $inventory = $pins[$pinId]['inventory'];
                $contents = [];
                foreach ($inventory as $typeId => $qty) {
                    $contents[] = [
                        'type_id' => $typeId,
                        'name' => $this->sdeService->getItemName($typeId),
                        'quantity' => $qty,
                        'volume' => $getVolume($typeId),
                    ];
                }
                $simPin['contents'] = $contents;
            }
            
            $simulatedPins[] = $simPin;
        }

        $planet['pins'] = $simulatedPins;
        return $planet;
    }
}
