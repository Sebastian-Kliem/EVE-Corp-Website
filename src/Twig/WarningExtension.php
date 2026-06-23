<?php

namespace App\Twig;

use App\Entity\User;
use App\Entity\EveCharacter;
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
}
