<?php

namespace App\Controller\Admin;

use App\Entity\CronJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cron')]
#[IsGranted('ROLE_CEO')]
class AdminCronController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_admin_cron_index', methods: ['GET'])]
    public function index(KernelInterface $kernel): Response
    {
        $cronJobs = $this->entityManager->getRepository(CronJob::class)->findBy([], ['name' => 'ASC']);

        $logFile = $kernel->getProjectDir() . '/var/log/cron.log';
        $logContent = '';
        if (file_exists($logFile)) {
            // Memory-efficient reading of the last 35 lines to prevent OutOfMemoryError on large logfiles
            $rawLogs = $this->getLastLines($logFile, 35);
            $lines = explode("\n", $rawLogs);
            
            $formattedLines = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                // Escape HTML characters to prevent XSS
                $escapedLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                
                // Determine style based on log level/keywords
                $color = '#abb2bf'; // Default muted white/grey
                
                if (str_contains($line, 'ERROR') || str_contains($line, '[ERROR]')) {
                    $color = '#ff5b7f'; // Soft vibrant red
                } elseif (str_contains($line, 'WARNING') || str_contains($line, '[WARNING]')) {
                    $color = '#ffc857'; // Warm yellow
                } elseif (str_contains($line, 'DEBUG') || str_contains($line, '[DEBUG]')) {
                    $color = '#6a737d'; // Darker gray for debug info
                } elseif (str_contains($line, 'SUCCESS') || str_contains($line, '[OK]') || str_contains($line, 'erfolgreich beendet')) {
                    $color = '#3cd070'; // Emerald green for success
                } elseif (str_contains($line, 'INFO') || str_contains($line, '[INFO]')) {
                    $color = '#3ab0ff'; // Soft blue/cyan for standard info
                }
                
                $formattedLines[] = sprintf('<span style="color: %s;">%s</span>', $color, $escapedLine);
            }
            $logContent = implode('<br>', $formattedLines);
        } else {
            $logContent = '<span style="color: #6a737d;">Keine Logdatei unter var/log/cron.log gefunden.<br>Sobald der Cronjob zum ersten Mal läuft, wird diese automatisch erstellt.</span>';
        }

        return $this->render('admin/admin_cron/cron_list.html.twig', [
            'cronJobs' => $cronJobs,
            'logContent' => $logContent,
            'logFileExists' => file_exists($logFile),
        ]);
    }

    #[Route('/run', name: 'app_admin_cron_run_all', methods: ['POST'])]
    public function runAll(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('cron_run_all', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        // Set all active jobs nextRunAt to now to force execute them
        $cronJobRepository = $this->entityManager->getRepository(CronJob::class);
        $activeJobs = $cronJobRepository->findBy(['isActive' => true]);
        foreach ($activeJobs as $job) {
            $job->setNextRunAt(new \DateTimeImmutable());
        }
        $this->entityManager->flush();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(['command' => 'app:cron:run']);
        $output = new BufferedOutput();

        try {
            $application->run($input, $output);
            $this->addFlash('success', 'Der Cron-Runner wurde erfolgreich ausgeführt und die Daten synchronisiert.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Ausführen des Cron-Runners: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_cron_index');
    }

    #[Route('/{id}/run', name: 'app_admin_cron_run_single', methods: ['POST'])]
    public function runSingle(CronJob $job, Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('cron_run_' . $job->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        // Make sure the job is active and due right now
        $job->setNextRunAt(new \DateTimeImmutable());
        if (!$job->isActive()) {
            $job->setIsActive(true);
        }
        $this->entityManager->flush();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(['command' => 'app:cron:run']);
        $output = new BufferedOutput();

        try {
            $application->run($input, $output);
            
            // Refresh to get updated execution time, status, and error details
            $this->entityManager->refresh($job);
            
            if ($job->getLastStatus() === 'success') {
                $this->addFlash('success', sprintf('Der Cronjob "%s" wurde erfolgreich ausgeführt (Dauer: %.2f Sek.).', $job->getName(), $job->getLastExecutionTime()));
            } else {
                $this->addFlash('error', sprintf('Fehler beim Ausführen des Cronjobs "%s": %s', $job->getName(), $job->getLastError()));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Ausführen des Cronjobs: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_cron_index');
    }

    #[Route('/{id}/toggle', name: 'app_admin_cron_toggle', methods: ['POST'])]
    public function toggle(CronJob $job, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('cron_toggle_' . $job->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        $job->setIsActive(!$job->isActive());
        $this->entityManager->flush();

        $statusText = $job->isActive() ? 'aktiviert' : 'deaktiviert';
        $this->addFlash('success', sprintf('Der Cronjob "%s" wurde erfolgreich %s.', $job->getName(), $statusText));

        return $this->redirectToRoute('app_admin_cron_index');
    }

    #[Route('/log/download', name: 'app_admin_cron_download_log', methods: ['GET'])]
    public function downloadLog(KernelInterface $kernel): Response
    {
        $logFile = $kernel->getProjectDir() . '/var/log/cron.log';
        if (!file_exists($logFile)) {
            $this->addFlash('error', 'Die Logdatei existiert noch nicht.');
            return $this->redirectToRoute('app_admin_cron_index');
        }

        $response = new BinaryFileResponse($logFile);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'cron.log'
        );

        return $response;
    }

    /**
     * Speicherschonendes Lesen der letzten N Zeilen einer Datei.
     */
    private function getLastLines(string $filename, int $numLines = 50): string
    {
        if (!file_exists($filename) || !is_readable($filename)) {
            return '';
        }

        $handle = fopen($filename, 'r');
        if (!$handle) {
            return '';
        }

        $lineCount = 0;
        $pos = -1;
        $buffer = '';
        $chunkSize = 4096;

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);

        while ($fileSize > 0 && $lineCount < $numLines + 1) {
            $readSize = min($chunkSize, $fileSize);
            $fileSize -= $readSize;
            
            fseek($handle, $fileSize);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk . $buffer;

            $lineCount = substr_count($buffer, "\n");
        }

        // Split by lines and take the last $numLines
        $lines = explode("\n", $buffer);
        if (count($lines) > $numLines) {
            $lines = array_slice($lines, -$numLines - 1);
        }

        fclose($handle);

        return implode("\n", $lines);
    }
}
