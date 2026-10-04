<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Revokes the API tokens handed to the frontend, so they stop working once the session ends.
 */
#[AsEventListener(event: LogoutEvent::class)]
class InvalidateApiTokensOnLogoutListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->invalidateApiTokens();
        $this->entityManager->flush();
    }
}
