<?php

namespace App\Tests\Service\Fitting;

use App\Entity\Fitting\Fitting;
use App\Service\Fitting\EftFormatter;
use App\Service\Fitting\EftParser;
use App\Service\Fitting\FittingBuildException;
use App\Service\Fitting\FittingContentBuilder;
use App\Service\Fitting\FittingHasher;
use App\Service\Fitting\FittingSlots;
use App\Service\SdeService;
use PHPUnit\Framework\TestCase;

class FittingContentTest extends TestCase
{
    private const EFT = <<<EFT
[Rifter, PvP Tackle]
Damage Control II
[Empty Low slot]

Warp Scrambler II
5MN Microwarpdrive II

200mm AutoCannon II, Republic Fleet EMP S
200mm AutoCannon II, Republic Fleet EMP S /offline

Small Projectile Burst Aerator I


Warrior II x2

Republic Fleet EMP S x400
EFT;

    // name => [typeId, slot]
    private const TYPES = [
        'rifter' => [587, 'hull'],
        'damage control ii' => [2048, 'low'],
        'warp scrambler ii' => [448, 'med'],
        '5mn microwarpdrive ii' => [440, 'med'],
        '200mm autocannon ii' => [2889, 'high'],
        'republic fleet emp s' => [21894, 'charge'],
        'small projectile burst aerator i' => [31668, 'rig'],
        'warrior ii' => [2488, 'drone'],
    ];

    public function testParserReadsHeaderQuantitiesChargesAndSkipsEmptySlots(): void
    {
        $parsed = (new EftParser())->parse(self::EFT);

        $this->assertSame('Rifter', $parsed['shipName']);
        $this->assertSame('PvP Tackle', $parsed['name']);
        $this->assertCount(8, $parsed['lines']);
        $this->assertSame(['name' => '200mm AutoCannon II', 'quantity' => 1, 'hasQuantity' => false, 'charge' => 'Republic Fleet EMP S'], $parsed['lines'][3]);
        $this->assertSame('200mm AutoCannon II', $parsed['lines'][4]['name']);
        $this->assertSame(['name' => 'Warrior II', 'quantity' => 2, 'hasQuantity' => true, 'charge' => null], $parsed['lines'][6]);
    }

    public function testBuilderAssignsSlotFlagsAndMovesChargesToCargo(): void
    {
        $content = $this->_createBuilder()->buildFromEft(self::EFT);

        $this->assertSame(587, $content['shipTypeId']);
        $this->assertSame([], $content['warnings']);
        $this->assertSame([
            ['typeId' => 2048, 'flag' => 'LoSlot0', 'quantity' => 1],
            ['typeId' => 448, 'flag' => 'MedSlot0', 'quantity' => 1],
            ['typeId' => 440, 'flag' => 'MedSlot1', 'quantity' => 1],
            ['typeId' => 2889, 'flag' => 'HiSlot0', 'quantity' => 1],
            ['typeId' => 2889, 'flag' => 'HiSlot1', 'quantity' => 1],
            ['typeId' => 31668, 'flag' => 'RigSlot0', 'quantity' => 1],
            ['typeId' => 2488, 'flag' => 'DroneBay', 'quantity' => 2],
            ['typeId' => 21894, 'flag' => 'Cargo', 'quantity' => 402],
        ], $content['items']);
    }

    public function testBuilderRejectsUnknownShip(): void
    {
        $this->expectException(FittingBuildException::class);
        $this->_createBuilder()->buildFromEft("[Titanic, Iceberg]\nDamage Control II");
    }

    public function testBuilderWarnsAboutUnknownItems(): void
    {
        $content = $this->_createBuilder()->buildFromEft("[Rifter, Test]\nMystery Module");

        $this->assertSame([], $content['items']);
        $this->assertSame(['Unbekanntes Item übersprungen: Mystery Module'], $content['warnings']);
    }

    public function testHashIgnoresSlotPositionAndItemOrder(): void
    {
        $hasher = new FittingHasher();
        $first = $hasher->hash(587, [
            ['typeId' => 2889, 'flag' => 'HiSlot0', 'quantity' => 1],
            ['typeId' => 448, 'flag' => 'MedSlot1', 'quantity' => 1],
        ]);
        $reordered = $hasher->hash(587, [
            ['typeId' => 448, 'flag' => 'MedSlot0', 'quantity' => 1],
            ['typeId' => 2889, 'flag' => 'HiSlot3', 'quantity' => 1],
        ]);
        $otherShip = $hasher->hash(588, [
            ['typeId' => 448, 'flag' => 'MedSlot0', 'quantity' => 1],
            ['typeId' => 2889, 'flag' => 'HiSlot3', 'quantity' => 1],
        ]);

        $this->assertSame($first, $reordered);
        $this->assertNotSame($first, $otherShip);
    }

    public function testSlotsNormalizeNumericInventoryFlags(): void
    {
        $this->assertSame('LoSlot0', FittingSlots::normalize(11));
        $this->assertSame('HiSlot7', FittingSlots::normalize('34'));
        $this->assertSame('RigSlot2', FittingSlots::normalize(94));
        $this->assertSame('DroneBay', FittingSlots::normalize(87));
        $this->assertSame('MedSlot3', FittingSlots::normalize('MedSlot3'));
        $this->assertSame('high', FittingSlots::groupOf('HiSlot2'));
        $this->assertFalse(FittingSlots::isFitted('Cargo'));
    }

    public function testFormatterRoundTripsThroughBuilder(): void
    {
        $builder = $this->_createBuilder();
        $content = $builder->buildFromEft(self::EFT);
        $fitting = (new Fitting())->setName('PvP Tackle');
        $fitting->setContent($content['shipTypeId'], $content['items'], 'hash');

        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getItemName')->willReturnCallback(static function (mixed $typeId): string {
            foreach (self::TYPES as $name => [$knownTypeId]) {
                if ($knownTypeId === $typeId) {
                    return ucwords($name);
                }
            }
            return (string) $typeId;
        });
        $eft = (new EftFormatter($sdeService))->format($fitting);

        $this->assertStringStartsWith("[Rifter, PvP Tackle]\n\nDamage Control Ii\n\nWarp Scrambler Ii\n5mn Microwarpdrive Ii\n", $eft);
        $this->assertSame($content['items'], $builder->buildFromEft($eft)['items']);
    }

    private function _createBuilder(): FittingContentBuilder
    {
        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('resolveItemNames')->willReturnCallback(static function (array $names): array {
            $resolved = [];
            foreach ($names as $name) {
                $key = strtolower($name);
                if (isset(self::TYPES[$key])) {
                    $resolved[$key] = ['id' => self::TYPES[$key][0], 'name' => $name, 'variation' => 'icon'];
                }
            }
            return $resolved;
        });
        $sdeService->method('isShip')->willReturnCallback(static fn (int $typeId): bool => $typeId === 587);
        $sdeService->method('getItemsDetails')->willReturnCallback(static function (array $typeIds): array {
            $details = [];
            foreach (self::TYPES as [$typeId, $slot]) {
                if (in_array($typeId, $typeIds, true)) {
                    $details[$typeId] = ['slot' => $slot];
                }
            }
            return $details;
        });

        return new FittingContentBuilder(new EftParser(), $sdeService);
    }
}
