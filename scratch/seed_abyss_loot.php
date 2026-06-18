<?php

use App\Kernel;
use App\Entity\TrackingList;
use App\Entity\TrackingListItem;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$container = $kernel->getContainer();
$entityManager = $container->get('doctrine')->getManager();

$listRepo = $entityManager->getRepository(TrackingList::class);
$existingList = $listRepo->findOneBy(['name' => 'Abyss Loot']);

if (!$existingList) {
    $list = new TrackingList();
    $list->setName('Abyss Loot');
    $list->setDescription('Standard-Tracking-Liste für typische Abyss-Gegenstände (Red Loot, Triglavian-Materialien, Filaments und BPCs).');
    $list->setIsGlobal(true);
    $entityManager->persist($list);
    echo "Liste 'Abyss Loot' wird erstellt.\n";
} else {
    $list = $existingList;
    echo "Liste 'Abyss Loot' existiert bereits. Aktualisiere Items...\n";
}

$itemIds = [
    48121, // Triglavian Survey Database
    47975, // Crystalline Isogen-10
    48112, // Zero-Point Condensate
    47966, // Damavik Blueprint
    47967, // Vedmak Blueprint
    47968, // Leshak Blueprint
    49714, // Kikimora Blueprint
    49715, // Drekavac Blueprint
    47761, // Calm Exotic Filament
    47762, // Calm Dark Filament
    47763, // Calm Firestorm Filament
    47700, // Unstable Stasis Webifier Mutaplasmid
    47701, // Gravid Stasis Webifier Mutaplasmid
    47730, // Unstable Warp Scrambler Mutaplasmid
];

$itemRepo = $entityManager->getRepository(TrackingListItem::class);
foreach ($itemIds as $id) {
    $existing = $itemRepo->findOneBy(['trackingList' => $list, 'typeId' => $id]);
    if (!$existing) {
        $item = new TrackingListItem();
        $item->setTrackingList($list);
        $item->setTypeId($id);
        $entityManager->persist($item);
        echo "Item {$id} hinzugefügt.\n";
    }
}

$entityManager->flush();
echo "Fertig!\n";
