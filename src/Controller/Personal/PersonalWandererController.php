<?php

namespace App\Controller\Personal;

use App\Entity\User;
use App\Entity\WandererRouteRule;
use App\Repository\WandererRouteRuleRepository;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/personal/wanderer')]
#[IsGranted('ROLE_USER')]
class PersonalWandererController extends AbstractController
{
    public function __construct(
        private readonly WandererRouteRuleRepository $ruleRepository,
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly SdeService $sdeService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_personal_wanderer_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
    }

    #[Route('/webhook', name: 'app_personal_wanderer_save_webhook', methods: ['POST'])]
    public function saveWebhook(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('personal_wanderer_webhook_save', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        /** @var User $user */
        $user = $this->getUser();
        $webhookUrl = trim((string)$request->request->get('discord_webhook_wanderer'));

        $settings = $user->getSettings();
        $settings['discord_webhook_wanderer'] = $webhookUrl;
        $user->setSettings($settings);

        $this->entityManager->flush();

        $this->addFlash('success', 'Persoenliche Discord-Webhook-URL wurde erfolgreich gespeichert.');
        return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
    }

    #[Route('/test-webhook', name: 'app_personal_wanderer_test_webhook', methods: ['POST'])]
    public function testWebhook(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('personal_wanderer_test_webhook', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        /** @var User $user */
        $user = $this->getUser();
        $userSettings = $user->getSettings();
        $webhookUrl = $userSettings['discord_webhook_wanderer'] ?? null;

        if (empty($webhookUrl)) {
            $this->addFlash('error', 'Bitte trage zuerst deine persoenliche Discord-Webhook-URL ein.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        $now = new \DateTimeImmutable();
        $embed = (new DiscordEmbed())
            ->setTitle('[TEST] Persoenlicher Wanderer Exit Alert')
            ->setColor(DiscordColor::PURPLE)
            ->setDescription("Dies ist eine Testnachricht an deinen privaten Discord-Kanal.\n\nDeine persoenlichen Routenregeln sind aktiv.")
            ->setFooter('WH-Toolbox Persoenliche Integration')
            ->setTimestamp($now);

        $message = new DiscordMessage();
        $message->addEmbed($embed);

        $success = $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_DEFAULT, $webhookUrl);

        if ($success) {
            $this->addFlash('success', 'Testnachricht erfolgreich an deinen privaten Discord-Webhook gesendet!');
        } else {
            $this->addFlash('error', 'Fehler beim Senden der Testnachricht. Bitte pruefe deine Webhook-URL.');
        }

        return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
    }

    #[Route('/rule/create', name: 'app_personal_wanderer_create_rule', methods: ['POST'])]
    public function createRule(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('personal_wanderer_rule_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        /** @var User $user */
        $user = $this->getUser();

        $name = trim((string)$request->request->get('name'));
        $targetSystemInput = trim((string)$request->request->get('target_system'));
        $maxJumps = (int)$request->request->get('max_jumps', 10);
        $securityMode = (string)$request->request->get('security_mode', WandererRouteRule::SEC_MODE_HIGHSEC_ONLY);
        $cooldownMinutes = (int)$request->request->get('cooldown_minutes', 180);

        if ($name === '' || $targetSystemInput === '') {
            $this->addFlash('error', 'Bitte Name und Zielsystem angeben.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
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
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        $rule = new WandererRouteRule();
        $rule->setUser($user);
        $rule->setName($name);
        $rule->setTargetSolarSystemId($systemId);
        $rule->setTargetSolarSystemName($systemName);
        $rule->setMaxJumps(max(1, min(50, $maxJumps)));
        $rule->setSecurityMode($securityMode);
        $rule->setCooldownMinutes(max(0, $cooldownMinutes));
        $rule->setIsActive(true);

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Persoenliche Routenregel "%s" fuer Ziel %s erfolgreich erstellt.', $name, $systemName));
        return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
    }

    #[Route('/rule/{id}/toggle', name: 'app_personal_wanderer_toggle_rule', methods: ['POST'])]
    public function toggleRule(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('personal_wanderer_rule_toggle_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        /** @var User $user */
        $user = $this->getUser();
        $rule = $this->ruleRepository->find($id);

        if (!$rule || $rule->getUser() !== $user) {
            $this->addFlash('error', 'Regel nicht gefunden oder keine Berechtigung.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        $rule->setIsActive(!$rule->isActive());
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Regel "%s" wurde %s.', $rule->getName(), $rule->isActive() ? 'aktiviert' : 'deaktiviert'));
        return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
    }

    #[Route('/rule/{id}/delete', name: 'app_personal_wanderer_delete_rule', methods: ['POST'])]
    public function deleteRule(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('personal_wanderer_rule_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungueltiges CSRF-Token.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        /** @var User $user */
        $user = $this->getUser();
        $rule = $this->ruleRepository->find($id);

        if (!$rule || $rule->getUser() !== $user) {
            $this->addFlash('error', 'Regel nicht gefunden oder keine Berechtigung.');
            return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
        }

        $ruleName = $rule->getName();
        $this->entityManager->remove($rule);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Regel "%s" wurde geloescht.', $ruleName));
        return $this->redirect($this->generateUrl('app_profile') . '#profile-wanderer-alerts');
    }
}
