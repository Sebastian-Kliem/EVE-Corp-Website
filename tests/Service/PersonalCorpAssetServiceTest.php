<?php

namespace App\Tests\Service;

use App\Entity\EveCorporationAsset;
use App\Service\PersonalCorpAssetService;
use PHPUnit\Framework\TestCase;

class PersonalCorpAssetServiceTest extends TestCase
{
    private PersonalCorpAssetService $service;

    protected function setUp(): void
    {
        $this->service = new PersonalCorpAssetService();
    }

    private function createAsset(int $itemId, int $locationId, string $flag, int $typeId = 1, int $corpId = 100): EveCorporationAsset
    {
        $asset = new EveCorporationAsset();
        $asset->setItemId($itemId);
        $asset->setLocationId($locationId);
        $asset->setLocationFlag($flag);
        $asset->setTypeId($typeId);
        $asset->setCorporationId($corpId);
        $asset->setQuantity(1);
        $asset->setIsSingleton(false);
        return $asset;
    }

    public function testHangarAndContainedContainerDoesNotDuplicateContainer(): void
    {
        $corpId = 100;
        $officeId = 1000;
        $flag = 'CorpSAG1';

        // Item 1: loose item in Hangar 1
        $item1 = $this->createAsset(101, $officeId, $flag, 34, $corpId);
        // Container 1: container in Hangar 1
        $container1 = $this->createAsset(201, $officeId, $flag, 17366, $corpId);
        // Item inside Container 1
        $nestedItem = $this->createAsset(301, 201, 'None', 35, $corpId);

        $corpAssets = [$item1, $container1, $nestedItem];

        // User selected both the hangar and the container inside it
        $personalHangars = [
            ['corporationId' => $corpId, 'locationId' => $officeId, 'locationFlag' => $flag]
        ];
        $personalContainers = [
            ['corporationId' => $corpId, 'itemId' => 201]
        ];

        $result = $this->service->resolvePersonalCorpAssets($corpId, $corpAssets, $personalHangars, $personalContainers);

        $roots = $result['roots'];

        // Roots should contain item1 and container1 exactly once, and NOT duplicate container1
        $this->assertCount(2, $roots);
        $this->assertArrayHasKey(101, $roots);
        $this->assertArrayHasKey(201, $roots);

        // Nested assets should properly link nestedItem to container1
        $nested = $result['nested'];
        $this->assertArrayHasKey(201, $nested);
        $this->assertCount(1, $nested[201]);
        $this->assertSame(301, $nested[201][0]->getItemId());
    }

    public function testSubContainerInsideContainerInPersonalHangarIsNotDuplicated(): void
    {
        $corpId = 100;
        $officeId = 1000;
        $flag = 'CorpSAG1';

        // Container 1 in Hangar 1
        $container1 = $this->createAsset(201, $officeId, $flag, 17366, $corpId);
        // Container 2 inside Container 1
        $container2 = $this->createAsset(202, 201, 'None', 17367, $corpId);
        // Item inside Container 2
        $item = $this->createAsset(301, 202, 'None', 34, $corpId);

        $corpAssets = [$container1, $container2, $item];

        // User selected Hangar 1 AND Container 2
        $personalHangars = [
            ['corporationId' => $corpId, 'locationId' => $officeId, 'locationFlag' => $flag]
        ];
        $personalContainers = [
            ['corporationId' => $corpId, 'itemId' => 202]
        ];

        $result = $this->service->resolvePersonalCorpAssets($corpId, $corpAssets, $personalHangars, $personalContainers);

        $roots = $result['roots'];

        // Only container1 should be root; container2 should NOT be added as separate root
        $this->assertCount(1, $roots);
        $this->assertArrayHasKey(201, $roots);
        $this->assertArrayNotHasKey(202, $roots);
    }

    public function testNestedPersonalContainersWithoutHangarSelected(): void
    {
        $corpId = 100;
        $officeId = 1000;
        $flag = 'CorpSAG1';

        // Container 1 in Hangar 1 (Hangar 1 is NOT selected)
        $container1 = $this->createAsset(201, $officeId, $flag, 17366, $corpId);
        // Container 2 inside Container 1
        $container2 = $this->createAsset(202, 201, 'None', 17367, $corpId);

        $corpAssets = [$container1, $container2];

        // User selected Container 1 AND Container 2, but NO hangars
        $personalHangars = [];
        $personalContainers = [
            ['corporationId' => $corpId, 'itemId' => 201],
            ['corporationId' => $corpId, 'itemId' => 202],
        ];

        $result = $this->service->resolvePersonalCorpAssets($corpId, $corpAssets, $personalHangars, $personalContainers);

        $roots = $result['roots'];

        // Only Container 1 should be a root; Container 2 is nested inside Container 1 and must not be a duplicate root
        $this->assertCount(1, $roots);
        $this->assertArrayHasKey(201, $roots);
        $this->assertArrayNotHasKey(202, $roots);
    }

    public function testStandaloneContainerInNonPersonalHangarIsSelected(): void
    {
        $corpId = 100;
        $officeId = 1000;

        // Container in Hangar 2
        $container = $this->createAsset(201, $officeId, 'CorpSAG2', 17366, $corpId);
        $item = $this->createAsset(301, 201, 'None', 34, $corpId);

        $corpAssets = [$container, $item];

        // Hangar 1 is selected, but NOT Hangar 2. Container 201 is selected.
        $personalHangars = [
            ['corporationId' => $corpId, 'locationId' => $officeId, 'locationFlag' => 'CorpSAG1']
        ];
        $personalContainers = [
            ['corporationId' => $corpId, 'itemId' => 201]
        ];

        $result = $this->service->resolvePersonalCorpAssets($corpId, $corpAssets, $personalHangars, $personalContainers);

        $roots = $result['roots'];

        // Container 201 is NOT inside Hangar 1, so it MUST be included as a root
        $this->assertCount(1, $roots);
        $this->assertArrayHasKey(201, $roots);
    }
}
