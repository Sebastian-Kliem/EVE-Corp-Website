<?php

namespace App\Controller;

use App\Entity\CronJob;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cron')]
#[IsGranted('ROLE_OFFICER')]
class AdminCronController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_admin_cron_index', methods: ['GET'])]
    public function index(): Response
    {
        $cronJobs = $this->entityManager->getRepository(CronJob::class)->findAll();

        return $this->render('admin/cron_list.html.twig', [
            'cronJobs' => $cronJobs,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_cron_edit', methods: ['POST'])]
    public function edit(int $id, Request $request): Response
    {
        $cronJob = $this->entityManager->getRepository(CronJob::class)->find($id);
        if (!$cronJob) {
            throw $this->createNotFoundException('Cron-Job nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('cron_edit_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        $name = trim((string) $request->request->get('name'));
        $expression = trim((string) $request->request->get('cronExpression'));
        $isActive = (bool) $request->request->get('isActive');

        if (empty($name) || empty($expression)) {
            $this->addFlash('error', 'Name und Cron-Ausdruck dürfen nicht leer sein.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        // Validate cron expression using dragonmantank/cron-expression library
        if (!CronExpression::isValidExpression($expression)) {
            $this->addFlash('error', sprintf('Ungültiger Cron-Ausdruck: "%s"', $expression));
            return $this->redirectToRoute('app_admin_cron_index');
        }

        $cronJob->setName($name);
        $cronJob->setCronExpression($expression);
        $cronJob->setIsActive($isActive);

        // Recalculate next run date
        try {
            $cron = new CronExpression($expression);
            $nextRun = \DateTimeImmutable::createFromInterface($cron->getNextRunDate());
            $cronJob->setNextRunAt($nextRun);
        } catch (\Exception $e) {
            // Fallback: keep existing nextRunAt
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Cron-Job "%s" wurde erfolgreich aktualisiert.', $name));

        return $this->redirectToRoute('app_admin_cron_index');
    }

    #[Route('/{id}/run', name: 'app_admin_cron_run', methods: ['POST'])]
    public function run(int $id, Request $request, KernelInterface $kernel): Response
    {
        $cronJob = $this->entityManager->getRepository(CronJob::class)->find($id);
        if (!$cronJob) {
            throw $this->createNotFoundException('Cron-Job nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('cron_run_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        // Reset nextRunAt to now to force trigger it
        $cronJob->setNextRunAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Run command programmatically in the background / request context
        $application = new Application($kernel);
        $application->setAutoExit(false);
        
        $input = new ArrayInput(['command' => 'app:cron:run']);
        $output = new BufferedOutput();
        
        try {
            $application->run($input, $output);
            $outputContent = $output->fetch();
            
            $this->addFlash('success', 'Cron-Runner wurde erfolgreich gestartet.');
            if (!empty($outputContent)) {
                $this->addFlash('info', $this->formatCommandOutput($outputContent));
            } else {
                $this->addFlash('info', 'Command wurde ausgeführt (keine Ausgabe).');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Ausführen des Cron-Runners: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_cron_index');
    }

    /**
     * Formats the raw CLI output of the cron runner into a clean, human-readable
     * HTML layout suitable for administrators and CEOs.
     */
    private function formatCommandOutput(string $output): string
    {
        $lines = explode("\n", $output);
        $charStatuses = [];

        foreach ($lines as $line) {
            // Match failed sync attempts
            if (preg_match('/Failed to sync (wallet|assets) for character ([a-zA-Z0-9_\- ]+) \((\d+)\): (.+)/i', $line, $matches)) {
                $type = $matches[1] === 'wallet' ? 'Wallet-Kontostand' : 'Inventar (Assets)';
                $charName = trim($matches[2]);
                $errorDetails = trim($matches[4]);

                // Simplify API 401 Unauthorized errors for the user/CEO
                if (str_contains($errorDetails, '401')) {
                    $errorDetails = 'Fehlende API-Berechtigung (der Charakter muss auf dem Profil neu per EVE-SSO eingeloggt werden).';
                }

                $charStatuses[$charName]['errors'][] = sprintf('<strong>%s:</strong> %s', $type, $errorDetails);
            }

            // Match successful wallet updates
            if (preg_match('/Successfully updated wallet for character ([a-zA-Z0-9_\- ]+) to (.+?) ISK/i', $line, $matches)) {
                $charName = trim($matches[1]);
                $balance = trim($matches[2]);
                $formattedBalance = number_format((float)$balance, 2, ',', '.');
                $charStatuses[$charName]['successes'][] = sprintf('Wallet-Guthaben erfolgreich aktualisiert (<strong>%s ISK</strong>).', $formattedBalance);
            }

            // Match successful asset updates
            if (preg_match('/Successfully updated (\d+) assets for character ([a-zA-Z0-9_\- ]+)/i', $line, $matches)) {
                $count = (int) $matches[1];
                $charName = trim($matches[2]);
                $charStatuses[$charName]['successes'][] = sprintf('Inventar erfolgreich importiert (<strong>%d Gegenstände</strong>).', $count);
            }

            // Match skipped characters
            if (preg_match('/Skipping character ([a-zA-Z0-9_\- ]+) \((\d+)\): No refresh token/i', $line, $matches)) {
                $charName = trim($matches[1]);
                $charStatuses[$charName]['info'][] = 'Charakter übersprungen: Kein API-Token in der Datenbank vorhanden.';
            }
        }

        if (empty($charStatuses)) {
            // Fallback: If no structured lines match, output a simple pre-wrapped terminal window
            return '<pre style="background:#111; color:#eee; padding:12px; border-radius:4px; font-size:0.8rem; font-family:monospace; border:1px solid #222;">' . htmlspecialchars($output) . '</pre>';
        }

        $html = '<div style="margin-top: 10px; margin-bottom: 10px;">';
        foreach ($charStatuses as $charName => $status) {
            $html .= '<div class="box p-3 mb-2" style="background: rgba(0, 0, 0, 0.2); border: 1px solid #333; border-radius: 4px;">';
            $html .= sprintf('<h4 class="title is-6 mb-2" style="color: #fff; display: flex; align-items: center; gap: 8px;">👤 <strong>%s</strong></h4>', htmlspecialchars($charName));

            if (!empty($status['successes'])) {
                $html .= '<ul style="list-style-type: none; margin: 0 0 6px 0; padding-left: 20px;">';
                foreach ($status['successes'] as $success) {
                    $html .= sprintf('<li style="color: #00ffaa; font-size: 0.9rem; margin-bottom: 2px;">✔️ %s</li>', $success);
                }
                $html .= '</ul>';
            }

            if (!empty($status['info'])) {
                $html .= '<ul style="list-style-type: none; margin: 0 0 6px 0; padding-left: 20px;">';
                foreach ($status['info'] as $info) {
                    $html .= sprintf('<li style="color: #3273dc; font-size: 0.9rem; margin-bottom: 2px;">ℹ️ %s</li>', $info);
                }
                $html .= '</ul>';
            }

            if (!empty($status['errors'])) {
                $html .= '<ul style="list-style-type: none; margin: 0; padding-left: 20px;">';
                foreach ($status['errors'] as $error) {
                    $html .= sprintf('<li style="color: #f14668; font-size: 0.9rem; margin-bottom: 2px;">❌ %s</li>', $error);
                }
                $html .= '</ul>';
            }

            $html .= '</div>';
        }
        $html .= '</div>';

        // Add the technical logs in a collapsible details element for developers
        $html .= '<details style="margin-top: 15px;">';
        $html .= '<summary style="cursor: pointer; font-size: 0.75rem; color: #888; list-style: none; display: flex; align-items: center; gap: 5px;">';
        $html .= '<span>🛠️</span> Technisches Ausführungsprotokoll (Entwickler-Protokoll) anzeigen';
        $html .= '</summary>';
        $html .= '<pre class="mt-2 p-2 is-size-7" style="background:#0a0a0a; color:#ccc; border:1px solid #222; border-radius:4px; font-family:monospace; overflow-x:auto;">' . htmlspecialchars($output) . '</pre>';
        $html .= '</details>';

        return $html;
    }
}
