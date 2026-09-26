<?php

namespace App\Controller\Admin;

use App\Entity\WandererRouteRule;
use App\Repository\WandererRouteRuleRepository;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/wanderer')]
#[IsGranted('ROLE_CEO')]
class AdminWandererController extends AbstractController
{
    public function __construct(
        private readonly WandererRouteRuleRepository $ruleRepository,
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly SdeService $sdeService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_admin_wanderer_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $corpRules = $this->ruleRepository->findAllCorpRules();
        $settings = $this->discordWebhookService->getAllSettings();

        // Base webhook URL to display in setup instructions
        $webhookEndpoint = $request->getSchemeAndHttpHost() . '/api/webhooks/wanderer';

        return $this->render('admin/admin_wanderer/index.html.twig', [
            'corpRules' => $corpRules,
            'settings' => $settings,
            'webhookEndpoint' => $webhookEndpoint,
        ]);
    }

    #[Route('/settings', name: 'app_admin_wanderer_save_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wanderer_settings_save', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $submittedSettings = [
            'discord_webhook_wanderer' => $request->request->get('discord_webhook_wanderer'),
            'discord_ping_role_wanderer' => $request->request->get('discord_ping_role_wanderer'),
            'wanderer_webhook_secret' => $request->request->get('wanderer_webhook_secret'),
        ];

        $this->discordWebhookService->saveSettings($submittedSettings);

        $this->addFlash('success', 'Wanderer-Integrationseinstellungen wurden erfolgreich gespeichert.');
        return $this->redirectToRoute('app_admin_wanderer_index');
    }

    #[Route('/rule/create', name: 'app_admin_wanderer_create_rule', methods: ['POST'])]
    public function createRule(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wanderer_rule_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $name = trim((string)$request->request->get('name'));
        $targetSystemInput = trim((string)$request->request->get('target_system'));
        $maxJumps = (int)$request->request->get('max_jumps', 10);
        $securityMode = (string)$request->request->get('security_mode', WandererRouteRule::SEC_MODE_HIGHSEC_ONLY);
        $cooldownMinutes = (int)$request->request->get('cooldown_minutes', 180);

        if ($name === '' || $targetSystemInput === '') {
            $this->addFlash('error', 'Bitte Name und Zielsystem angeben.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $systemId = null;
        $systemName = $targetSystemInput;

        if (is_numeric($targetSystemInput)) {
            $systemId = (int)$targetSystemInput;
            $info = $this->sdeService->getSolarSystemInfo($systemId);
            if ($info) {
                $systemName = $info['solarSystemName'];
            }
        } else {
            $systemId = $this->sdeService->getSolarSystemIdByName($targetSystemInput);
        }

        if (!$systemId) {
            $this->addFlash('error', sprintf('Das System "%s" konnte in der EVE-Datenbank nicht gefunden werden.', $targetSystemInput));
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $rule = new WandererRouteRule();
        $rule->setUser(null); // Corporate rule
        $rule->setName($name);
        $rule->setTargetSolarSystemId($systemId);
        $rule->setTargetSolarSystemName($systemName);
        $rule->setMaxJumps(max(1, min(50, $maxJumps)));
        $rule->setSecurityMode($securityMode);
        $rule->setCooldownMinutes(max(0, $cooldownMinutes));
        $rule->setIsActive(true);

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Corp-Routenregel "%s" fuer Ziel %s erfolgreich erstellt.', $name, $systemName));
        return $this->redirectToRoute('app_admin_wanderer_index');
    }

    #[Route('/rule/{id}/toggle', name: 'app_admin_wanderer_toggle_rule', methods: ['POST'])]
    public function toggleRule(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wanderer_rule_toggle_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $rule = $this->ruleRepository->find($id);
        if (!$rule || !$rule->isCorpRule()) {
            $this->addFlash('error', 'Regel nicht gefunden.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $rule->setIsActive(!$rule->isActive());
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Regel "%s" wurde %s.', $rule->getName(), $rule->isActive() ? 'aktiviert' : 'deaktiviert'));
        return $this->redirectToRoute('app_admin_wanderer_index');
    }

    #[Route('/rule/{id}/delete', name: 'app_admin_wanderer_delete_rule', methods: ['POST'])]
    public function deleteRule(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wanderer_rule_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $rule = $this->ruleRepository->find($id);
        if (!$rule || !$rule->isCorpRule()) {
            $this->addFlash('error', 'Regel nicht gefunden.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $ruleName = $rule->getName();
        $this->entityManager->remove($rule);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Regel "%s" wurde geloescht.', $ruleName));
        return $this->redirectToRoute('app_admin_wanderer_index');
    }

    #[Route('/test-discord', name: 'app_admin_wanderer_test_discord', methods: ['POST'])]
    public function testDiscord(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wanderer_test_discord', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        if (!$this->discordWebhookService->isConfigured(DiscordWebhookService::CHANNEL_WANDERER)) {
            $this->addFlash('error', 'Kein Webhook fuer Wanderer konfiguriert.');
            return $this->redirectToRoute('app_admin_wanderer_index');
        }

        $now = new \DateTimeImmutable();
        $embed = (new DiscordEmbed())
            ->setTitle('[TEST] Wanderer K-Space Exit Benachrichtigung')
            ->setColor(DiscordColor::GREEN)
            ->setDescription("Dies ist eine Testnachricht fuer den dedizierten Wanderer-Discord-Kanal.\n\n**System:** Jita (0.95 - Highsec)\n**Distanz:** 0 Spruenge nach Jita\n**Region:** The Forge\n[Dotlan](https://evemaps.dotlan.net/system/Jita) | [zKillboard](https://zkillboard.com/system/30000142/)")
            ->setFooter('Keepers of Duat - Wanderer Test')
            ->setTimestamp($now);

        $message = new DiscordMessage();
        $message->addEmbed($embed);

        $ping = $this->discordWebhookService->getWandererPing();
        if (!empty($ping)) {
            $message->setContent($ping);
        }

        $success = $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_WANDERER);

        if ($success) {
            $this->addFlash('success', 'Testnachricht erfolgreich an den Wanderer-Discord-Kanal gesendet.');
        } else {
            $this->addFlash('error', 'Fehler beim Senden der Testnachricht. Bitte Webhook-URL pruefen.');
        }

        return $this->redirectToRoute('app_admin_wanderer_index');
    }

    #[Route('/search-systems', name: 'app_admin_wanderer_search_systems', methods: ['GET'])]
    public function searchSystems(Request $request): JsonResponse
    {
        $query = (string)$request->query->get('q', '');
        $results = $this->sdeService->searchSolarSystems($query, 10);

        return new JsonResponse($results);
    }
}
