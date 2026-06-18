<?php

namespace App\Command;

use App\Entity\TrackingList;
use App\Entity\TrackingListItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:tracking-templates',
    description: 'Seeds standard tracking list templates (e.g. Abyss Loot) in the database.',
)]
class SeedTrackingTemplatesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Tracking List Templates');

        $listRepo = $this->entityManager->getRepository(TrackingList::class);
        $itemRepo = $this->entityManager->getRepository(TrackingListItem::class);

        // Define templates
        $templates = [
            [
                'name' => 'Abyss Loot',
                'description' => 'Standard-Tracking-Liste für typische Abyss-Gegenstände (Red Loot, Triglavian-Materialien, Filaments und BPCs).',
                'items' => [
                    48121, // Triglavian Survey Database (Red Loot)
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
                ]
            ]
        ];

        foreach ($templates as $tmplData) {
            $existingList = $listRepo->findOneBy(['name' => $tmplData['name'], 'user' => null]);
            
            if (!$existingList) {
                $list = new TrackingList();
                $list->setName($tmplData['name']);
                $list->setDescription($tmplData['description']);
                $list->setIsGlobal(true);
                $list->setUser(null); // Explicitly a template
                $this->entityManager->persist($list);
                $io->text(sprintf('Creating template: "%s"', $tmplData['name']));
            } else {
                $list = $existingList;
                $io->text(sprintf('Template "%s" already exists. Updating items...', $tmplData['name']));
            }

            $addedCount = 0;
            foreach ($tmplData['items'] as $typeId) {
                $existingItem = $itemRepo->findOneBy(['trackingList' => $list, 'typeId' => $typeId]);
                if (!$existingItem) {
                    $item = new TrackingListItem();
                    $item->setTrackingList($list);
                    $item->setTypeId($typeId);
                    $this->entityManager->persist($item);
                    $addedCount++;
                }
            }

            $io->text(sprintf('Added %d new items to "%s".', $addedCount, $tmplData['name']));
        }

        $this->entityManager->flush();
        $io->success('All templates seeded successfully!');

        return Command::SUCCESS;
    }
}
