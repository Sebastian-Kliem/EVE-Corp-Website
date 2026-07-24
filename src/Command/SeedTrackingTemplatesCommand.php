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

                    47297, // Unstable 50MN Microwarpdrive Mutaplasmid
                    47299, // Decayed 500MN Microwarpdrive Mutaplasmid
                    47699, // Decayed Stasis Webifier Mutaplasmid
                    47700, // Unstable Stasis Webifier Mutaplasmid
                    47701, // Gravid Stasis Webifier Mutaplasmid
                    47729, // Decayed Warp Scrambler Mutaplasmid
                    47730, // Unstable Warp Scrambler Mutaplasmid
                    47731, // Gravid Warp Scrambler Mutaplasmid
                    47733, // Decayed Warp Disruptor Mutaplasmid
                    47734, // Unstable Warp Disruptor Mutaplasmid
                    47735, // Gravid Warp Disruptor Mutaplasmid
                    47737, // Decayed 5MN Microwarpdrive Mutaplasmid
                    47738, // Unstable 5MN Microwarpdrive Mutaplasmid
                    47739, // Gravid 5MN Microwarpdrive Mutaplasmid
                    47741, // Gravid 50MN Microwarpdrive Mutaplasmid
                    47742, // Decayed 50MN Microwarpdrive Mutaplasmid
                    47743, // Unstable 500MN Microwarpdrive Mutaplasmid
                    47744, // Gravid 500MN Microwarpdrive Mutaplasmid
                    47746, // Decayed 1MN Afterburner Mutaplasmid
                    47747, // Unstable 1MN Afterburner Mutaplasmid
                    47748, // Gravid 1MN Afterburner Mutaplasmid
                    47750, // Decayed 10MN Afterburner Mutaplasmid
                    47751, // Unstable 10MN Afterburner Mutaplasmid
                    47752, // Gravid 10MN Afterburner Mutaplasmid
                    47754, // Decayed 100MN Afterburner Mutaplasmid
                    47755, // Unstable 100MN Afterburner Mutaplasmid
                    47756, // Gravid 100MN Afterburner Mutaplasmid
                    47761, // Calm Exotic Filament
                    47762, // Calm Dark Filament
                    47763, // Calm Firestorm Filament
                    47764, // Calm Gamma Filament
                    47765, // Calm Electrical Filament
                    47766, // Decayed Small Armor Repairer Mutaplasmid
                    47767, // Unstable Small Armor Repairer Mutaplasmid
                    47768, // Gravid Small Armor Repairer Mutaplasmid
                    47770, // Decayed Medium Armor Repairer Mutaplasmid
                    47771, // Unstable Medium Armor Repairer Mutaplasmid
                    47772, // Gravid Medium Armor Repairer Mutaplasmid
                    47774, // Decayed Large Armor Repairer Mutaplasmid
                    47775, // Unstable Large Armor Repairer Mutaplasmid
                    47776, // Gravid Large Armor Repairer Mutaplasmid
                    47778, // Decayed Small Shield Booster Mutaplasmid
                    47779, // Unstable Small Shield Booster Mutaplasmid
                    47780, // Gravid Small Shield Booster Mutaplasmid
                    47782, // Decayed Medium Shield Booster Mutaplasmid
                    47783, // Unstable Medium Shield Booster Mutaplasmid
                    47784, // Gravid Medium Shield Booster Mutaplasmid
                    47786, // Decayed Large Shield Booster Mutaplasmid
                    47787, // Unstable Large Shield Booster Mutaplasmid
                    47788, // Gravid Large Shield Booster Mutaplasmid
                    47790, // Decayed X-Large Shield Booster Mutaplasmid
                    47791, // Unstable X-Large Shield Booster Mutaplasmid
                    47792, // Gravid X-Large Shield Booster Mutaplasmid
                    47797, // Decayed Small Shield Extender Mutaplasmid
                    47798, // Unstable Small Shield Extender Mutaplasmid
                    47799, // Gravid Small Shield Extender Mutaplasmid
                    47801, // Decayed Medium Shield Extender Mutaplasmid
                    47802, // Unstable Medium Shield Extender Mutaplasmid
                    47803, // Gravid Medium Shield Extender Mutaplasmid
                    47805, // Decayed Large Shield Extender Mutaplasmid
                    47806, // Unstable Large Shield Extender Mutaplasmid
                    47807, // Gravid Large Shield Extender Mutaplasmid
                    47809, // Decayed Small Armor Plate Mutaplasmid
                    47810, // Unstable Small Armor Plate Mutaplasmid
                    47811, // Gravid Small Armor Plate Mutaplasmid
                    47813, // Decayed Medium Armor Plate Mutaplasmid
                    47814, // Unstable Medium Armor Plate Mutaplasmid
                    47815, // Gravid Medium Armor Plate Mutaplasmid
                    47816, // Decayed Large Armor Plate Mutaplasmid
                    47818, // Unstable Large Armor Plate Mutaplasmid
                    47819, // Gravid Large Armor Plate Mutaplasmid
                    47821, // Decayed Small Energy Neutralizer Mutaplasmid
                    47822, // Unstable Small Energy Neutralizer Mutaplasmid
                    47823, // Gravid Small Energy Neutralizer Mutaplasmid
                    47825, // Decayed Medium Energy Neutralizer Mutaplasmid
                    47826, // Unstable Medium Energy Neutralizer Mutaplasmid
                    47827, // Gravid Medium Energy Neutralizer Mutaplasmid
                    47829, // Decayed Heavy Energy Neutralizer Mutaplasmid
                    47830, // Unstable Heavy Energy Neutralizer Mutaplasmid
                    47831, // Gravid Heavy Energy Neutralizer Mutaplasmid
                    47835, // Unstable Medium Ancillary Shield Booster Mutaplasmid
                    47837, // Unstable Large Ancillary Shield Booster Mutaplasmid
                    47839, // Unstable X-Large Ancillary Shield Booster Mutaplasmid
                    47841, // Unstable Small Ancillary Armor Repairer Mutaplasmid
                    47843, // Unstable Medium Ancillary Armor Repairer Mutaplasmid
                    47845, // Unstable Large Ancillary Armor Repairer Mutaplasmid
                    47867, // Precursor Frigate
                    47868, // Precursor Cruiser
                    47869, // Precursor Battleship
                    47888, // Agitated Exotic Filament
                    47889, // Fierce Exotic Filament
                    47890, // Raging Exotic Filament
                    47891, // Chaotic Exotic Filament
                    47892, // Agitated Dark Filament
                    47893, // Fierce Dark Filament
                    47894, // Raging Dark Filament
                    47895, // Chaotic Dark Filament
                    47896, // Agitated Firestorm Filament
                    47897, // Fierce Firestorm Filament
                    47898, // Raging Firestorm Filament
                    47899, // Chaotic Firestorm Filament
                    47900, // Agitated Gamma Filament
                    47901, // Fierce Gamma Filament
                    47902, // Raging Gamma Filament
                    47903, // Chaotic Gamma Filament
                    47904, // Agitated Electrical Filament
                    47905, // Fierce Electrical Filament
                    47906, // Raging Electrical Filament
                    47907, // Chaotic Electrical Filament
                    47966, // Damavik Blueprint
                    47967, // Vedmak Blueprint
                    47968, // Leshak Blueprint
                    47975, // Crystalline Isogen-10
                    48112, // Zero-Point Condensate
                    48121, // Triglavian Survey Database
                    48416, // Decayed Small Energy Nosferatu Mutaplasmid
                    48417, // Gravid Small Energy Nosferatu Mutaplasmid
                    48418, // Unstable Small Energy Nosferatu Mutaplasmid
                    48420, // Decayed Medium Energy Nosferatu Mutaplasmid
                    48421, // Gravid Medium Energy Nosferatu Mutaplasmid
                    48422, // Unstable Medium Energy Nosferatu Mutaplasmid
                    48424, // Decayed Heavy Energy Nosferatu Mutaplasmid
                    48425, // Gravid Heavy Energy Nosferatu Mutaplasmid
                    48426, // Unstable Heavy Energy Nosferatu Mutaplasmid
                    48428, // Decayed Small Cap Battery Mutaplasmid
                    48429, // Gravid Small Cap Battery Mutaplasmid
                    48430, // Unstable Small Cap Battery Mutaplasmid
                    48432, // Decayed Medium Cap Battery Mutaplasmid
                    48433, // Gravid Medium Cap Battery Mutaplasmid
                    48434, // Unstable Medium Cap Battery Mutaplasmid
                    48436, // Decayed Large Cap Battery Mutaplasmid
                    48437, // Gravid Large Cap Battery Mutaplasmid
                    48438, // Unstable Large Cap Battery Mutaplasmid
                    48638, // Hydra Blueprint
                    49714, // Kikimora Blueprint
                    49715, // Drekavac Blueprint
                    49723, // Decayed Magnetic Field Stabilizer Mutaplasmid
                    49724, // Gravid Magnetic Field Stabilizer Mutaplasmid
                    49725, // Unstable Magnetic Field Stabilizer Mutaplasmid
                    49727, // Decayed Heat Sink Mutaplasmid
                    49728, // Gravid Heat Sink Mutaplasmid
                    49729, // Unstable Heat Sink Mutaplasmid
                    49731, // Decayed Gyrostabilizer Mutaplasmid
                    49732, // Gravid Gyrostabilizer Mutaplasmid
                    49733, // Unstable Gyrostabilizer Mutaplasmid
                    49735, // Decayed Entropic Radiation Sink Mutaplasmid
                    49736, // Gravid Entropic Radiation Sink Mutaplasmid
                    49737, // Unstable Entropic Radiation Sink Mutaplasmid
                    49739, // Decayed Ballistic Control System Mutaplasmid
                    49740, // Gravid Ballistic Control System Mutaplasmid
                    49741, // Unstable Ballistic Control System Mutaplasmid
                    49742, // Precursor Destroyer
                    49743, // Precursor Battlecruiser
                    52224, // Decayed Damage Control Mutaplasmid
                    52225, // Gravid Damage Control Mutaplasmid
                    52226, // Unstable Damage Control Mutaplasmid
                    52228, // Decayed Assault Damage Control Mutaplasmid
                    52229, // Gravid Assault Damage Control Mutaplasmid
                    52231, // Unstable Assault Damage Control Mutaplasmid
                    52237, // Zorya's Light Entropic Disintegrator Blueprint
                    52239, // Zorya's Heavy Entropic Disintegrator Blueprint
                    52241, // Zorya's Supratidal Entropic Disintegrator Blueprint
                    52243, // Zorya's Entropic Radiation Sink Blueprint
                    52245, // Veles Entropic Radiation Sink Blueprint
                    52251, // Nergal Blueprint
                    52253, // Ikitursa Blueprint
                    52311, // Singularity Radiation Convertor
                    52348, // Veles Light Entropic Disintegrator Blueprint
                    52349, // Veles Heavy Entropic Disintegrator Blueprint
                    52350, // Veles Supratidal Entropic Disintegrator Blueprint
                    52997, // Precursor Dreadnought
                    53029, // Zirnitra Blueprint
                    54826, // Large Vorton Projector
                    54842, // Skybreaker Blueprint
                    54843, // Stormbringer Blueprint
                    54844, // Thunderchild Blueprint
                    55034, // Small Vorton Projector
                    55035, // Medium Vorton Projector
                    56131, // Tranquil Electrical Filament
                    56132, // Tranquil Dark Filament
                    56133, // Tranquil Exotic Filament
                    56134, // Tranquil Firestorm Filament
                    56136, // Tranquil Gamma Filament
                    56139, // Cataclysmic Electrical Filament
                    56140, // Cataclysmic Dark Filament
                    56141, // Cataclysmic Exotic Filament
                    56142, // Cataclysmic Firestorm Filament
                    56143, // Cataclysmic Gamma Filament
                    56269, // Decayed Heavy Warp Scrambler Mutaplasmid
                    56270, // Unstable Heavy Warp Scrambler Mutaplasmid
                    56271, // Gravid Heavy Warp Scrambler Mutaplasmid
                    56272, // Unstable Heavy Warp Disruptor Mutaplasmid
                    56273, // Gravid Heavy Warp Disruptor Mutaplasmid
                    56274, // Decayed Heavy Warp Disruptor Mutaplasmid
                    56275, // Decayed 10000MN Afterburner Mutaplasmid
                    56276, // Unstable 10000MN Afterburner Mutaplasmid
                    56277, // Gravid 10000MN Afterburner Mutaplasmid
                    56278, // Decayed 50000MN Microwarpdrive Mutaplasmid
                    56279, // Unstable 50000MN Microwarpdrive Mutaplasmid
                    56280, // Gravid 50000MN Microwarpdrive Mutaplasmid
                    56281, // Decayed Capital Armor Repairer Mutaplasmid
                    56282, // Unstable Capital Armor Repairer Mutaplasmid
                    56283, // Gravid Capital Armor Repairer Mutaplasmid
                    56284, // Unstable Capital Ancillary Armor Repairer Mutaplasmid
                    56285, // Decayed Capital Shield Booster Mutaplasmid
                    56286, // Unstable Capital Shield Booster Mutaplasmid
                    56287, // Gravid Capital Shield Booster Mutaplasmid
                    56288, // Unstable Capital Ancillary Shield Booster Mutaplasmid
                    56289, // Decayed Capital Energy Nosferatu Mutaplasmid
                    56290, // Unstable Capital Energy Nosferatu Mutaplasmid
                    56291, // Gravid Capital Energy Nosferatu Mutaplasmid
                    56292, // Decayed Capital Energy Neutralizer Mutaplasmid
                    56293, // Unstable Capital Energy Neutralizer Mutaplasmid
                    56294, // Gravid Capital Energy Neutralizer Mutaplasmid
                    56299, // Decayed Siege Module Mutaplasmid
                    56300, // Unstable Siege Module Mutaplasmid
                    56301, // Gravid Siege Module Mutaplasmid
                    60460, // Exigent Light Drone Navigation Mutaplasmid
                    60461, // Exigent Light Drone Firepower Mutaplasmid
                    60462, // Exigent Light Drone Durability Mutaplasmid
                    60463, // Exigent Heavy Drone Navigation Mutaplasmid
                    60464, // Exigent Heavy Drone Firepower Mutaplasmid
                    60465, // Exigent Heavy Drone Durability Mutaplasmid
                    60466, // Exigent Heavy Drone Projection Mutaplasmid
                    60467, // Exigent Sentry Drone Precision Mutaplasmid
                    60468, // Exigent Sentry Drone Firepower Mutaplasmid
                    60469, // Exigent Sentry Drone Durability Mutaplasmid
                    60470, // Exigent Sentry Drone Projection Mutaplasmid
                    60471, // Exigent Light Drone Projection Mutaplasmid
                    60472, // Exigent Medium Drone Navigation Mutaplasmid
                    60473, // Exigent Medium Drone Firepower Mutaplasmid
                    60474, // Exigent Medium Drone Durability Mutaplasmid
                    60475, // Exigent Medium Drone Projection Mutaplasmid
                    60476, // Radical Drone Damage Amplifier Mutaplasmid
                    60477, // Radical Fighter Support Unit Mutaplasmid
                    78622, // Gravid Vorton Tuning System Mutaplasmid
                    78623, // Decayed Vorton Tuning System Mutaplasmid
                    78624, // Unstable Vorton Tuning System Mutaplasmid
                    84398, // Decayed Large EMP Smartbomb Mutaplasmid
                    84399, // Decayed Large Plasma Smartbomb Mutaplasmid
                    84400, // Decayed Medium EMP Smartbomb Mutaplasmid
                    84401, // Decayed Medium Plasma Smartbomb Mutaplasmid
                    84402, // Decayed Medium Graviton Smartbomb Mutaplasmid
                    84403, // Decayed Medium Proton Smartbomb Mutaplasmid
                    84404, // Decayed Small EMP Smartbomb Mutaplasmid
                    84405, // Decayed Small Plasma Smartbomb Mutaplasmid
                    84406, // Decayed Small Graviton Smartbomb Mutaplasmid
                    84407, // Decayed Small Proton Smartbomb Mutaplasmid
                    84408, // Decayed Large Graviton Smartbomb Mutaplasmid
                    84409, // Decayed Large Proton Smartbomb Mutaplasmid
                    84410, // Gravid Small EMP Smartbomb Mutaplasmid
                    84411, // Gravid Small Plasma Smartbomb Mutaplasmid
                    84412, // Gravid Small Graviton Smartbomb Mutaplasmid
                    84413, // Gravid Small Proton Smartbomb Mutaplasmid
                    84414, // Gravid Large Graviton Smartbomb Mutaplasmid
                    84415, // Gravid Large Proton Smartbomb Mutaplasmid
                    84416, // Gravid Large EMP Smartbomb Mutaplasmid
                    84417, // Gravid Large Plasma Smartbomb Mutaplasmid
                    84418, // Gravid Medium EMP Smartbomb Mutaplasmid
                    84419, // Gravid Medium Plasma Smartbomb Mutaplasmid
                    84420, // Gravid Medium Graviton Smartbomb Mutaplasmid
                    84421, // Gravid Medium Proton Smartbomb Mutaplasmid
                    84422, // Unstable Large EMP Smartbomb Mutaplasmid
                    84423, // Unstable Large Plasma Smartbomb Mutaplasmid
                    84424, // Unstable Medium EMP Smartbomb Mutaplasmid
                    84425, // Unstable Medium Plasma Smartbomb Mutaplasmid
                    84426, // Unstable Medium Graviton Smartbomb Mutaplasmid
                    84427, // Unstable Medium Proton Smartbomb Mutaplasmid
                    84428, // Unstable Small EMP Smartbomb Mutaplasmid
                    84429, // Unstable Small Plasma Smartbomb Mutaplasmid
                    84430, // Unstable Small Graviton Smartbomb Mutaplasmid
                    84431, // Unstable Small Proton Smartbomb Mutaplasmid
                    84432, // Unstable Large Graviton Smartbomb Mutaplasmid
                    84433, // Unstable Large Proton Smartbomb Mutaplasmid
                    85438, // Glorified Decayed Small Armor Plate Mutaplasmid
                    85439, // Glorified Unstable Small Armor Plate Mutaplasmid
                    85440, // Glorified Gravid Small Armor Plate Mutaplasmid
                    85441, // Glorified Decayed Small Armor Repairer Mutaplasmid
                    85442, // Glorified Unstable Small Armor Repairer Mutaplasmid
                    85443, // Glorified Gravid Small Armor Repairer Mutaplasmid
                    85445, // Glorified Unstable Small Ancillary Armor Repairer Mutaplasmid
                    85446, // Glorified Unstable Medium Armor Plate Mutaplasmid
                    85447, // Glorified Gravid Medium Armor Plate Mutaplasmid
                    85448, // Glorified Decayed Medium Armor Plate Mutaplasmid
                    85449, // Glorified Decayed Medium Armor Repairer Mutaplasmid
                    85450, // Glorified Gravid Medium Armor Repairer Mutaplasmid
                    85451, // Glorified Unstable Medium Ancillary Armor Repairer Mutaplasmid
                    85452, // Glorified Unstable Medium Armor Repairer Mutaplasmid
                    85453, // Glorified Decayed Large Armor Plate Mutaplasmid
                    85454, // Glorified Unstable Large Armor Plate Mutaplasmid
                    85455, // Glorified Decayed Large Armor Repairer Mutaplasmid
                    85456, // Glorified Gravid Large Armor Plate Mutaplasmid
                    85457, // Glorified Unstable Large Armor Repairer Mutaplasmid
                    85458, // Glorified Gravid Large Armor Repairer Mutaplasmid
                    85459, // Glorified Unstable Large Ancillary Armor Repairer Mutaplasmid
                    85460, // Glorified Decayed Capital Armor Repairer Mutaplasmid
                    85461, // Glorified Unstable Capital Armor Repairer Mutaplasmid
                    85462, // Glorified Gravid Capital Armor Repairer Mutaplasmid
                    85463, // Glorified Unstable Capital Ancillary Armor Repairer Mutaplasmid
                    85464, // Glorified Decayed 1MN Afterburner Mutaplasmid
                    85465, // Glorified Unstable 1MN Afterburner Mutaplasmid
                    85466, // Glorified Gravid 1MN Afterburner Mutaplasmid
                    85467, // Glorified Gravid 5MN Microwarpdrive Mutaplasmid
                    85468, // Glorified Decayed 5MN Microwarpdrive Mutaplasmid
                    85469, // Glorified Unstable 5MN Microwarpdrive Mutaplasmid
                    85470, // Glorified Decayed 10MN Afterburner Mutaplasmid
                    85471, // Glorified Unstable 10MN Afterburner Mutaplasmid
                    85472, // Glorified Gravid 10MN Afterburner Mutaplasmid
                    85473, // Glorified Unstable 50MN Microwarpdrive Mutaplasmid
                    85474, // Glorified Decayed 50MN Microwarpdrive Mutaplasmid
                    85475, // Glorified Gravid 50MN Microwarpdrive Mutaplasmid
                    85476, // Glorified Gravid 100MN Afterburner Mutaplasmid
                    85477, // Glorified Decayed 100MN Afterburner Mutaplasmid
                    85478, // Glorified Unstable 100MN Afterburner Mutaplasmid
                    85479, // Glorified Decayed 500MN Microwarpdrive Mutaplasmid
                    85480, // Glorified Gravid 500MN Microwarpdrive Mutaplasmid
                    85481, // Glorified Unstable 500MN Microwarpdrive Mutaplasmid
                    85482, // Glorified Decayed 10000MN Afterburner Mutaplasmid
                    85483, // Glorified Unstable 10000MN Afterburner Mutaplasmid
                    85484, // Glorified Gravid 10000MN Afterburner Mutaplasmid
                    85485, // Glorified Gravid 50000MN Microwarpdrive Mutaplasmid
                    85486, // Glorified Decayed 50000MN Microwarpdrive Mutaplasmid
                    85487, // Glorified Unstable 50000MN Microwarpdrive Mutaplasmid
                    85488, // Glorified Decayed Damage Control Mutaplasmid
                    85489, // Glorified Unstable Damage Control Mutaplasmid
                    85490, // Glorified Decayed Assault Damage Control Mutaplasmid
                    85491, // Glorified Gravid Damage Control Mutaplasmid
                    85492, // Glorified Gravid Assault Damage Control Mutaplasmid
                    85493, // Glorified Unstable Assault Damage Control Mutaplasmid
                    85494, // Glorified Decayed Small Cap Battery Mutaplasmid
                    85495, // Glorified Unstable Small Cap Battery Mutaplasmid
                    85496, // Glorified Gravid Small Cap Battery Mutaplasmid
                    85497, // Glorified Decayed Small Energy Neutralizer Mutaplasmid
                    85498, // Glorified Gravid Small Energy Neutralizer Mutaplasmid
                    85499, // Glorified Unstable Small Energy Neutralizer Mutaplasmid
                    85500, // Glorified Decayed Small Energy Nosferatu Mutaplasmid
                    85501, // Glorified Gravid Small Energy Nosferatu Mutaplasmid
                    85502, // Glorified Unstable Small Energy Nosferatu Mutaplasmid
                    85504, // Glorified Decayed Medium Cap Battery Mutaplasmid
                    85506, // Glorified Gravid Medium Cap Battery Mutaplasmid
                    85507, // Glorified Unstable Medium Cap Battery Mutaplasmid
                    85508, // Glorified Decayed Medium Energy Neutralizer Mutaplasmid
                    85509, // Glorified Gravid Medium Energy Neutralizer Mutaplasmid
                    85510, // Glorified Unstable Medium Energy Neutralizer Mutaplasmid
                    85511, // Glorified Decayed Medium Energy Nosferatu Mutaplasmid
                    85512, // Glorified Unstable Medium Energy Nosferatu Mutaplasmid
                    85513, // Glorified Gravid Medium Energy Nosferatu Mutaplasmid
                    85514, // Glorified Gravid Large Cap Battery Mutaplasmid
                    85515, // Glorified Decayed Large Cap Battery Mutaplasmid
                    85516, // Glorified Unstable Large Cap Battery Mutaplasmid
                    85517, // Glorified Decayed Heavy Energy Neutralizer Mutaplasmid
                    85518, // Glorified Gravid Heavy Energy Neutralizer Mutaplasmid
                    85519, // Glorified Gravid Heavy Energy Nosferatu Mutaplasmid
                    85520, // Glorified Decayed Heavy Energy Nosferatu Mutaplasmid
                    85521, // Glorified Unstable Heavy Energy Neutralizer Mutaplasmid
                    85522, // Glorified Unstable Heavy Energy Nosferatu Mutaplasmid
                    85523, // Glorified Unstable Capital Energy Neutralizer Mutaplasmid
                    85524, // Glorified Gravid Capital Energy Neutralizer Mutaplasmid
                    85525, // Glorified Decayed Capital Energy Neutralizer Mutaplasmid
                    85526, // Glorified Decayed Capital Energy Nosferatu Mutaplasmid
                    85527, // Glorified Gravid Capital Energy Nosferatu Mutaplasmid
                    85528, // Glorified Unstable Capital Energy Nosferatu Mutaplasmid
                    85529, // Glorified Decayed Small Shield Booster Mutaplasmid
                    85530, // Glorified Decayed Small Shield Extender Mutaplasmid
                    85531, // Glorified Unstable Small Shield Booster Mutaplasmid
                    85532, // Glorified Gravid Small Shield Extender Mutaplasmid
                    85533, // Glorified Gravid Small Shield Booster Mutaplasmid
                    85534, // Glorified Unstable Small Shield Extender Mutaplasmid
                    85535, // Glorified Decayed Medium Shield Booster Mutaplasmid
                    85536, // Glorified Gravid Medium Shield Booster Mutaplasmid
                    85537, // Glorified Unstable Medium Shield Booster Mutaplasmid
                    85538, // Glorified Gravid Medium Shield Extender Mutaplasmid
                    85539, // Glorified Unstable Medium Shield Extender Mutaplasmid
                    85540, // Glorified Decayed Medium Shield Extender Mutaplasmid
                    85541, // Glorified Unstable Medium Ancillary Shield Booster Mutaplasmid
                    85542, // Glorified Decayed Large Shield Booster Mutaplasmid
                    85543, // Glorified Gravid Large Shield Booster Mutaplasmid
                    85544, // Glorified Gravid Large Shield Extender Mutaplasmid
                    85545, // Glorified Unstable Large Shield Booster Mutaplasmid
                    85546, // Glorified Decayed Large Shield Extender Mutaplasmid
                    85547, // Glorified Unstable Large Shield Extender Mutaplasmid
                    85548, // Glorified Unstable Large Ancillary Shield Booster Mutaplasmid
                    85549, // Glorified Unstable X-Large Shield Booster Mutaplasmid
                    85550, // Glorified Decayed X-Large Shield Booster Mutaplasmid
                    85551, // Glorified Gravid X-Large Shield Booster Mutaplasmid
                    85552, // Glorified Unstable X-Large Ancillary Shield Booster Mutaplasmid
                    85553, // Glorified Decayed Capital Shield Booster Mutaplasmid
                    85554, // Glorified Unstable Capital Ancillary Shield Booster Mutaplasmid
                    85555, // Glorified Gravid Capital Shield Booster Mutaplasmid
                    85556, // Glorified Unstable Capital Shield Booster Mutaplasmid
                    85557, // Glorified Decayed Stasis Webifier Mutaplasmid
                    85558, // Glorified Unstable Stasis Webifier Mutaplasmid
                    85559, // Glorified Gravid Stasis Webifier Mutaplasmid
                    85640, // Glorified Decayed Warp Disruptor Mutaplasmid
                    85641, // Glorified Unstable Warp Disruptor Mutaplasmid
                    85642, // Glorified Gravid Warp Disruptor Mutaplasmid
                    85643, // Glorified Decayed Warp Scrambler Mutaplasmid
                    85644, // Glorified Gravid Warp Scrambler Mutaplasmid
                    85645, // Glorified Unstable Warp Scrambler Mutaplasmid
                    85646, // Glorified Decayed Heavy Warp Scrambler Mutaplasmid
                    85647, // Glorified Gravid Heavy Warp Scrambler Mutaplasmid
                    85648, // Glorified Unstable Heavy Warp Scrambler Mutaplasmid
                    85649, // Glorified Decayed Heavy Warp Disruptor Mutaplasmid
                    85650, // Glorified Gravid Heavy Warp Disruptor Mutaplasmid
                    85651, // Glorified Unstable Heavy Warp Disruptor Mutaplasmid
                    85652, // Glorified Decayed Ballistic Control System Mutaplasmid
                    85653, // Glorified Gravid Ballistic Control System Mutaplasmid
                    85654, // Glorified Unstable Ballistic Control System Mutaplasmid
                    85655, // Glorified Decayed Entropic Radiation Sink Mutaplasmid
                    85656, // Glorified Unstable Entropic Radiation Sink Mutaplasmid
                    85657, // Glorified Gravid Entropic Radiation Sink Mutaplasmid
                    85660, // Glorified Decayed Gyrostabilizer Mutaplasmid
                    85661, // Glorified Gravid Gyrostabilizer Mutaplasmid
                    85662, // Glorified Unstable Gyrostabilizer Mutaplasmid
                    85663, // Glorified Decayed Heat Sink Mutaplasmid
                    85664, // Glorified Unstable Heat Sink Mutaplasmid
                    85665, // Glorified Gravid Heat Sink Mutaplasmid
                    85666, // Glorified Decayed Magnetic Field Stabilizer Mutaplasmid
                    85667, // Glorified Gravid Magnetic Field Stabilizer Mutaplasmid
                    85668, // Glorified Unstable Magnetic Field Stabilizer Mutaplasmid
                    85669, // Glorified Decayed Siege Module Mutaplasmid
                    85670, // Glorified Gravid Siege Module Mutaplasmid
                    85671, // Glorified Unstable Siege Module Mutaplasmid
                    85672, // Glorified Decayed Vorton Tuning System Mutaplasmid
                    85673, // Glorified Unstable Vorton Tuning System Mutaplasmid
                    85674, // Glorified Gravid Vorton Tuning System Mutaplasmid
                    85675, // Glorified Exigent Light Drone Durability Mutaplasmid
                    85676, // Glorified Exigent Light Drone Navigation Mutaplasmid
                    85677, // Glorified Exigent Light Drone Projection Mutaplasmid
                    85678, // Glorified Exigent Light Drone Firepower Mutaplasmid
                    85679, // Glorified Exigent Medium Drone Durability Mutaplasmid
                    85680, // Glorified Exigent Medium Drone Navigation Mutaplasmid
                    85681, // Glorified Exigent Medium Drone Firepower Mutaplasmid
                    85682, // Glorified Exigent Medium Drone Projection Mutaplasmid
                    85685, // Glorified Exigent Heavy Drone Durability Mutaplasmid
                    85686, // Glorified Exigent Heavy Drone Navigation Mutaplasmid
                    85687, // Glorified Exigent Heavy Drone Projection Mutaplasmid
                    85688, // Glorified Exigent Heavy Drone Firepower Mutaplasmid
                    85690, // Glorified Exigent Sentry Drone Durability Mutaplasmid
                    85691, // Glorified Exigent Sentry Drone Firepower Mutaplasmid
                    85692, // Glorified Exigent Sentry Drone Precision Mutaplasmid
                    85693, // Glorified Exigent Sentry Drone Projection Mutaplasmid
                    85696, // Glorified Radical Drone Damage Amplifier Mutaplasmid
                    85698, // Glorified Radical Fighter Support Unit Mutaplasmid
                    90457, // Decayed Mining Laser Mutaplasmid
                    90458, // Gravid Mining Laser Mutaplasmid
                    90459, // Unstable Mining Laser Mutaplasmid
                    90466, // Decayed Modulated Strip Miner Mutaplasmid
                    90468, // Gravid Modulated Strip Miner Mutaplasmid
                    90469, // Unstable Modulated Strip Miner Mutaplasmid
                    90470, // Decayed Modulated Deep Core Miner Mutaplasmid
                    90471, // Unstable Modulated Deep Core Miner Mutaplasmid
                    90472, // Gravid Modulated Deep Core Miner Mutaplasmid
                    90480, // Decayed Deep Core Mining Laser Mutaplasmid
                    90481, // Gravid Deep Core Mining Laser Mutaplasmid
                    90482, // Unstable Deep Core Mining Laser Mutaplasmid
                    90484, // Decayed Modulated Deep Core Strip Miner Mutaplasmid
                    90485, // Gravid Modulated Deep Core Strip Miner Mutaplasmid
                    90486, // Unstable Modulated Deep Core Strip Miner Mutaplasmid
                    90489, // Decayed Strip Miner Mutaplasmid
                    90490, // Gravid Strip Miner Mutaplasmid
                    90491, // Unstable Strip Miner Mutaplasmid
                    90495, // Decayed Deep Core Strip Miner Mutaplasmid
                    90496, // Gravid Deep Core Strip Miner Mutaplasmid
                    90497, // Unstable Deep Core Strip Miner Mutaplasmid
                    90499, // Decayed Ice Mining Laser Mutaplasmid
                    90500, // Gravid Ice Mining Laser Mutaplasmid
                    90501, // Unstable Ice Mining Laser Mutaplasmid
                    90521, // Decayed Ice Harvester Mutaplasmid
                    90522, // Gravid Ice Harvester Mutaplasmid
                    90523, // Unstable Ice Harvester Mutaplasmid
                    90526, // Decayed Gas Cloud Scoop Mutaplasmid
                    90527, // Gravid Gas Cloud Scoop Mutaplasmid
                    90528, // Unstable Gas Cloud Scoop Mutaplasmid
                    90590, // Decayed Gas Cloud Harvester Mutaplasmid
                    90591, // Gravid Gas Cloud Harvester Mutaplasmid
                    90592, // Unstable Gas Cloud Harvester Mutaplasmid
                    90609, // Exigent Mining Drone Mutaplasmid
                    90611, // Exigent Ice Harvesting Drone Mutaplasmid
                    90619, // Exigent 'Excavator' Mining Drone Mutaplasmid
                    90620, // Exigent 'Excavator' Ice Harvesting Drone Mutaplasmid
                    // Faction Rogue Drones (Rampancy)
                    92033, // Orbweaver SW-300-I
                    92034, // Huntsman SW-600-I
                    92035, // Arabellata SW-900-I
                    92036, // Tick EV-300-I
                    92037, // Mosquito EV-600-I
                    92038, // Tabanida EV-900-I
                    92039, // Inshore EC-300-I
                    92040, // Nertic EC-600-I
                    92041, // Humboldt EC-900-I
                    92461, // Skimmer TP-300-I
                    92462, // Darter TP-600-I
                    92463, // Meganeura TP-900-I
                    92464, // Stigmella SD-300-I
                    92465, // Luna SD-600-I
                    92466, // Atlas SD-900-I
                    92467, // Stellate TD-300-I
                    92468, // Immaculate TD-600-I
                    92469, // Torafugu TD-900-I
                    // Other Requested Items
                    91773, // Rampancy Data Dump
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
