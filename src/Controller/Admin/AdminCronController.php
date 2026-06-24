<?php

namespace App\Controller\Admin;

use App\Entity\CronJob;
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
#[IsGranted('ROLE_CEO')]
class AdminCronController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_admin_cron_index', methods: ['GET'])]
    public function index(KernelInterface $kernel): Response
    {
        $logFile = $kernel->getProjectDir() . '/var/log/cron.log';
        $logContent = '';
        if (file_exists($logFile)) {
            $lines = file($logFile);
            $lastLines = array_slice($lines, -150);
            
            $formattedLines = [];
            foreach ($lastLines as $line) {
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
            $logContent = implode('', $formattedLines);
        } else {
            $logContent = '<span style="color: #6a737d;">Keine Logdatei unter var/log/cron.log gefunden.<br>Sobald der Cronjob zum ersten Mal läuft, wird diese automatisch erstellt.</span>';
        }

        return $this->render('admin/admin_cron/cron_list.html.twig', [
            'logContent' => $logContent,
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
}
