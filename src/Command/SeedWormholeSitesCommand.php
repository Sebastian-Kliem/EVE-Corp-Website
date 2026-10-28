<?php

namespace App\Command;

use App\Entity\Wormhole\WormholeSite;
use App\Repository\WormholeSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:seed:wormhole-sites',
    description: 'Seeds the wormhole sites (combat, data, relic, gas, ore) from config/seed/wormhole_sites.json.',
)]
class SeedWormholeSitesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WormholeSiteRepository $wormholeSiteRepository,
        #[Autowire('%kernel.project_dir%/config/seed/wormhole_sites.json')]
        private readonly string $seedFile
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Wormhole Sites');

        $seedData = json_decode((string) file_get_contents($this->seedFile), true, 512, JSON_THROW_ON_ERROR);

        $existingSites = [];
        foreach ($this->wormholeSiteRepository->findAll() as $site) {
            $existingSites[$site->getName()] = $site;
        }

        $created = 0;
        $updated = 0;
        foreach ($seedData['sites'] as $sortOrder => $siteData) {
            $site = $existingSites[$siteData['name']] ?? null;
            unset($existingSites[$siteData['name']]);

            if ($site === null) {
                $site = new WormholeSite();
                $this->entityManager->persist($site);
                $created++;
            } else {
                $updated++;
            }

            $this->_applySiteData($site, $siteData, $sortOrder);
        }

        // Sites removed from the seed file are removed from the database as well
        foreach ($existingSites as $obsoleteSite) {
            $this->entityManager->remove($obsoleteSite);
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d sites created, %d updated, %d removed.', $created, $updated, count($existingSites)));

        return Command::SUCCESS;
    }

    private function _applySiteData(WormholeSite $site, array $siteData, int $sortOrder): void
    {
        $site->setName($siteData['name'])
            ->setNameDe($siteData['nameDe'] ?? null)
            ->setCategory($siteData['category'])
            ->setClasses($siteData['classes'])
            ->setOccurrence($siteData['occurrence'] ?? null)
            ->setBlueLootIsk($siteData['blueLootIsk'] ?? null)
            ->setWaves($siteData['waves'])
            ->setResources($siteData['resources'])
            ->setNotes($siteData['notes'])
            ->setSource($siteData['source'])
            ->setSortOrder($sortOrder);
    }
}
