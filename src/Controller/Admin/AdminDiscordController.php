<?php

namespace App\Controller\Admin;

use App\Entity\DiscordNotificationLog;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/discord')]
#[IsGranted('ROLE_CEO')]
class AdminDiscordController extends AbstractController
{
    public function __construct(
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_admin_discord_index', methods: ['GET'])]
    public function index(): Response
    {
        $settings = $this->discordWebhookService->getAllSettings();
        
        $logRepo = $this->entityManager->getRepository(DiscordNotificationLog::class);
        $recentLogs = $logRepo->findBy([], ['createdAt' => 'DESC'], 20);

        return $this->render('admin/admin_discord/discord_settings.html.twig', [
            'settings' => $settings,
            'recentLogs' => $recentLogs,
        ]);
    }

    #[Route('/save', name: 'app_admin_discord_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('discord_settings_save', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_discord_index');
        }

        $submittedSettings = [
            'discord_webhook_default' => $request->request->get('discord_webhook_default'),
            'discord_webhook_fuel' => $request->request->get('discord_webhook_fuel'),
            'discord_webhook_combat' => $request->request->get('discord_webhook_combat'),
            'discord_webhook_structures' => $request->request->get('discord_webhook_structures'),
            'discord_webhook_user_alerts' => $request->request->get('discord_webhook_user_alerts'),
            'discord_webhook_industry' => $request->request->get('discord_webhook_industry'),
            'discord_webhook_market' => $request->request->get('discord_webhook_market'),
            'discord_ping_role_structure_defense' => $request->request->get('discord_ping_role_structure_defense'),
            'discord_ping_role_fuel' => $request->request->get('discord_ping_role_fuel'),
        ];

        $this->discordWebhookService->saveSettings($submittedSettings);

        $this->addFlash('success', 'Discord-Webhook-Einstellungen wurden erfolgreich gespeichert.');
        return $this->redirectToRoute('app_admin_discord_index');
    }

    #[Route('/test/{channel}', name: 'app_admin_discord_test', methods: ['POST'])]
    public function testChannel(string $channel, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('discord_test_' . $channel, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_discord_index');
        }

        if (!$this->discordWebhookService->isConfigured($channel)) {
            $this->addFlash('error', sprintf('Kein Webhook für den Kanal "%s" konfiguriert.', $channel));
            return $this->redirectToRoute('app_admin_discord_index');
        }

        $now = new \DateTimeImmutable();
        $embed = (new DiscordEmbed())
            ->setTitle(sprintf('🧪 [TEST] Keepers of Duat – Test für Kanal: %s', strtoupper($channel)))
            ->setColor(DiscordColor::BLUE)
            ->setDescription(sprintf('Dies ist eine erfolgreiche Testnachricht für den Discord-Kanal: **%s**.', $channel))
            ->addField('📡 Webhook Status', 'Verbindung aktiv & funktionsbereit ✅', false)
            ->addField('🕒 Sendezeitpunkt', $now->format('d.m.Y H:i:s') . ' EVE Time', true)
            ->addField('👤 Gesendet von', $this->getUser()?->getUserIdentifier() ?: 'Admin', true)
            ->setFooter('Keepers of Duat • Discord Integration')
            ->setTimestamp($now);

        $message = DiscordMessage::create(sprintf('🔔 Testbenachrichtigung für **#%s**', $channel))
            ->setUsername('Keepers of Duat')
            ->addEmbed($embed);

        $success = $this->discordWebhookService->send($message, $channel);

        if ($success) {
            $this->addFlash('success', sprintf('Testnachricht erfolgreich an den Kanal "%s" gesendet! Bitte prüfe deinen Discord-Server.', $channel));
        } else {
            $this->addFlash('error', sprintf('Fehler beim Senden der Testnachricht an Kanal "%s". Bitte prüfe die Webhook-URL.', $channel));
        }

        return $this->redirectToRoute('app_admin_discord_index');
    }
}
