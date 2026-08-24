<?php

namespace App\Twig;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCorporationStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class WarningExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_sso_warnings', [$this, 'getSsoWarnings']),
            new TwigFunction('get_fuel_warnings', [$this, 'getFuelWarnings']),
        ];
    }

    /**
     * Returns an array of warning messages or invalid characters for the currently logged-in user.
     *
     * @return EveCharacter[]
     */
    public function getSsoWarnings(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        // Find all characters associated with the logged-in user that have tokenValid = false
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        return $characterRepository->findBy([
            'user' => $user,
            'tokenValid' => false,
        ]);
    }

    /**
     * Returns an array of structures with fuel under 30 days for corporations
     * where the currently logged-in user has at least one character.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFuelWarnings(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        // 1. Collect all corporation IDs for characters owned by this user
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        $userCharacters = $characterRepository->findBy(['user' => $user]);

        $corpIds = [];
        foreach ($userCharacters as $char) {
            if ($char->getCorporationId()) {
                $corpIds[] = (string)$char->getCorporationId();
            }
        }
        $corpIds = array_values(array_unique($corpIds));

        if (empty($corpIds)) {
            return [];
        }

        // 2. Query structures of these corporations where fuel expires in less than 30 days
        $now = new \DateTimeImmutable();
        $thirtyDaysAhead = $now->modify('+30 days');

        $structureRepo = $this->entityManager->getRepository(EveCorporationStructure::class);
        $structures = $structureRepo->createQueryBuilder('s')
            ->where('s.corporationId IN (:corpIds)')
            ->andWhere('s.fuelExpires IS NOT NULL')
            ->andWhere('s.fuelExpires <= :threshold')
            ->setParameter('corpIds', $corpIds)
            ->setParameter('threshold', $thirtyDaysAhead)
            ->orderBy('s.fuelExpires', 'ASC')
            ->getQuery()
            ->getResult();

        $warnings = [];
        foreach ($structures as $structure) {
            /** @var EveCorporationStructure $structure */
            $fuelExpires = $structure->getFuelExpires();
            if (!$fuelExpires) {
                continue;
            }

            $diff = $now->diff($fuelExpires);
            $isExpired = $fuelExpires <= $now;

            $daysRemaining = 0;
            $hoursRemaining = 0;
            if ($isExpired) {
                $formattedTime = 'Treibstoff abgelaufen!';
            } else {
                $daysRemaining = (int)$diff->format('%a');
                $hoursRemaining = (int)$diff->format('%h');
                if ($daysRemaining > 0) {
                    $formattedTime = sprintf('%d Tag%s %d Std.', $daysRemaining, $daysRemaining === 1 ? '' : 'e', $hoursRemaining);
                } else {
                    $minutesRemaining = (int)$diff->format('%i');
                    $formattedTime = sprintf('%d Std. %d Min.', $hoursRemaining, $minutesRemaining);
                }
            }

            $warnings[] = [
                'id' => $structure->getId(),
                'name' => $structure->getName() ?? $structure->getTypeName() ?? ('Struktur #' . $structure->getId()),
                'typeName' => $structure->getTypeName() ?? 'Struktur',
                'solarSystemName' => $structure->getSolarSystemName() ?? 'Unbekanntes System',
                'corporationId' => $structure->getCorporationId(),
                'state' => $structure->getState(),
                'fuelExpires' => $fuelExpires,
                'daysRemaining' => $daysRemaining,
                'hoursRemaining' => $hoursRemaining,
                'isExpired' => $isExpired,
                'isCritical' => $isExpired || $daysRemaining < 7,
                'formattedTime' => $formattedTime,
                'expiresAtFormatted' => $fuelExpires->format('d.m.Y H:i') . ' UTC',
            ];
        }

        return $warnings;
    }
}
