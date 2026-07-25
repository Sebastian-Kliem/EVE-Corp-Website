<?php

use App\Kernel;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Entity\EveCompetingMarketOrder;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
/** @var \Doctrine\ORM\EntityManagerInterface $em */
$em = $container->get('doctrine.orm.default_entity_manager');

$character = $em->getRepository(EveCharacter::class)->findOneBy(['id' => 92673867]);
if (!$character) {
    // Fallback to any character
    $character = $em->getRepository(EveCharacter::class)->findOneBy([]);
}

if (!$character) {
    die("No character found in DB to attach mock orders to.\n");
}

echo "Seeding market mock data for character: " . $character->getName() . " (" . $character->getId() . ")\n";

// Clear existing
$em->createQuery('DELETE FROM App\Entity\EveCharacterMarketOrder')->execute();
$em->createQuery('DELETE FROM App\Entity\EveCompetingMarketOrder')->execute();

$jitaStationId = '60003760';
$now = new \DateTimeImmutable();

// Helper to create own order
$createOwnOrder = function($orderId, $typeId, $price, $isBuy, $volRemain, $volTotal) use ($character, $jitaStationId, $now) {
    $order = new EveCharacterMarketOrder();
    $order->setCharacter($character);
    $order->setOrderId($orderId);
    $order->setTypeId($typeId);
    $order->setLocationId($jitaStationId);
    $order->setVolumeTotal($volTotal);
    $order->setVolumeRemain($volRemain);
    $order->setPrice($price);
    $order->setIsBuy($isBuy);
    $order->setIssued($now->modify('-2 days'));
    $order->setDuration(90);
    $order->setRange('region');
    $order->setMinVolume(1);
    return $order;
};

// Helper to create competing order
$createCompetingOrder = function($orderId, $typeId, $price, $isBuy, $volRemain, $volTotal) use ($jitaStationId, $now) {
    $order = new EveCompetingMarketOrder();
    $order->setOrderId($orderId);
    $order->setTypeId($typeId);
    $order->setLocationId($jitaStationId);
    $order->setVolumeTotal($volTotal);
    $order->setVolumeRemain($volRemain);
    $order->setPrice($price);
    $order->setIsBuy($isBuy);
    $order->setIssued($now->modify('-1 days'));
    $order->setDuration(90);
    $order->setRange('region');
    $order->setMinVolume(1);
    $order->setLastUpdated($now);
    return $order;
};

// 1. TRITANIUM (typeId 34)
// Own Sell: 5.20 ISK
$em->persist($createOwnOrder('100001', 34, '5.20', false, 500000, 1000000));
// Own Buy: 4.80 ISK
$em->persist($createOwnOrder('100002', 34, '4.80', true, 250000, 500000));

// Competitor Sells (lowest is 5.15 - undercutting us!)
$em->persist($createCompetingOrder('200001', 34, '5.15', false, 1500000, 2000000));
$em->persist($createCompetingOrder('200002', 34, '5.19', false, 400000, 500000));
$em->persist($createCompetingOrder('100001', 34, '5.20', false, 500000, 1000000)); // Our own in the public book
$em->persist($createCompetingOrder('200003', 34, '5.22', false, 800000, 1000000));

// Competitor Buys (highest is 4.85 - outbidding us!)
$em->persist($createCompetingOrder('200004', 34, '4.85', true, 1000000, 1000000));
$em->persist($createCompetingOrder('100002', 34, '4.80', true, 250000, 500000)); // Our own in public book
$em->persist($createCompetingOrder('200005', 34, '4.78', true, 600000, 1000000));

// 2. RAVEN (typeId 638)
// Own Sell: 345,000,000.00 ISK
$em->persist($createOwnOrder('100003', 638, '345000000.00', false, 2, 5));
// Competitor Sells
$em->persist($createCompetingOrder('200006', 638, '340000000.00', false, 3, 3)); // Undercutting us!
$em->persist($createCompetingOrder('100003', 638, '345000000.00', false, 2, 5)); // Ours
$em->persist($createCompetingOrder('200007', 638, '350000000.00', false, 1, 2));

// 3. PLEX (typeId 44992)
// Own Buy: 5,100,000.00 ISK
$em->persist($createOwnOrder('100004', 44992, '5100000.00', true, 100, 100));
// Competitor Buys
$em->persist($createCompetingOrder('100004', 44992, '5100000.00', true, 100, 100)); // Ours (highest!)
$em->persist($createCompetingOrder('200008', 44992, '5099000.00', true, 500, 500));
$em->persist($createCompetingOrder('200009', 44992, '5050000.00', true, 200, 1000));

$em->flush();

echo "Successfully seeded mock market data!\n";
