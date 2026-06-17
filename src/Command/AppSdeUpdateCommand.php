<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:sde:update',
    description: 'Checks for EVE Online SDE updates and downloads/extracts the latest SQLite database.',
)]
class AppSdeUpdateCommand extends Command
{
    private const DEFAULT_URL = 'https://www.fuzzwork.co.uk/dump/latest-sqlite.db.gz';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force update and ignore cached checksums.')
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Custom URL for the EVE SDE sqlite.gz file.', self::DEFAULT_URL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $url = $input->getOption('url');

        $projectDir = $this->kernel->getProjectDir();
        $varDir = $projectDir . '/var';
        $sdeFile = $varDir . '/sde.sqlite';
        $statusFile = $varDir . '/sde_status.json';

        $io->title('EVE Online SDE SQLite Updater');

        // Ensure the var directory exists
        if (!is_dir($varDir)) {
            if (!mkdir($varDir, 0777, true) && !is_dir($varDir)) {
                $io->error(sprintf('Directory "%s" was not created.', $varDir));
                return Command::FAILURE;
            }
        }

        // Fetch remote headers using HEAD request to verify if an update is required
        $io->text('Checking remote EVE SDE file status...');
        try {
            $response = $this->httpClient->request('HEAD', $url, [
                'timeout' => 10,
            ]);
            $headers = $response->getHeaders(false);
        } catch (\Exception $e) {
            $io->error('Failed to connect to Fuzzwork to check for updates: ' . $e->getMessage());
            
            // If the local file does not exist, we cannot proceed
            if (!file_exists($sdeFile)) {
                return Command::FAILURE;
            }
            $io->warning('Using existing SDE file as the remote check failed.');
            return Command::SUCCESS;
        }

        $remoteLastModified = $headers['last-modified'][0] ?? null;
        $remoteETag = $headers['etag'][0] ?? null;
        $remoteContentLength = $headers['content-length'][0] ?? null;

        $localStatus = [];
        if (file_exists($statusFile)) {
            $localStatus = json_decode(file_get_contents($statusFile), true) ?: [];
        }

        $upToDate = false;
        if (
            file_exists($sdeFile) &&
            !$force &&
            isset($localStatus['last_modified']) &&
            $localStatus['last_modified'] === $remoteLastModified &&
            isset($localStatus['etag']) &&
            $localStatus['etag'] === $remoteETag
        ) {
            $upToDate = true;
        }

        if ($upToDate) {
            $io->success('Your EVE Online SDE SQLite database is already up to date!');
            return Command::SUCCESS;
        }

        $io->section('Downloading and updating EVE Online SDE');

        // Create temporary paths
        $tempCompressed = $varDir . '/sde_download.sqlite.gz';
        $tempUncompressed = $varDir . '/sde_download.sqlite';

        // Clean up any remaining temp files
        if (file_exists($tempCompressed)) {
            unlink($tempCompressed);
        }
        if (file_exists($tempUncompressed)) {
            unlink($tempUncompressed);
        }

        $io->text('Downloading compressed SDE SQLite dump...');
        $progressBar = $io->createProgressBar();
        $progressBar->start();

        try {
            // We use symfony http-client streaming to avoid loading the whole file in memory
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 300, // 5 minutes timeout for download
                'on_progress' => function (int $dlNow, int $dlSize, array $info) use ($progressBar): void {
                    if ($dlSize > 0) {
                        $progressBar->setMaxSteps($dlSize);
                        $progressBar->setProgress($dlNow);
                    }
                }
            ]);

            // Save chunk by chunk
            $fileHandler = fopen($tempCompressed, 'w');
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($fileHandler, $chunk->getContent());
            }
            fclose($fileHandler);
            $progressBar->finish();
            $io->newLine(2);
        } catch (\Exception $e) {
            $progressBar->finish();
            $io->newLine(2);
            $io->error('Download failed: ' . $e->getMessage());
            
            // Cleanup temp file
            if (file_exists($tempCompressed)) {
                unlink($tempCompressed);
            }
            return Command::FAILURE;
        }

        $io->text('Decompressing downloaded gzip archive...');
        try {
            if (extension_loaded('zlib')) {
                $gz = gzopen($tempCompressed, 'rb');
                if ($gz === false) {
                    throw new \RuntimeException('Failed to open gzip source file.');
                }
                $out = fopen($tempUncompressed, 'wb');
                if ($out === false) {
                    gzclose($gz);
                    throw new \RuntimeException('Failed to open destination uncompressed file.');
                }
                while (!gzeof($gz)) {
                    $buffer = gzread($gz, 1024 * 64); // Read in 64kb chunks
                    if ($buffer === false) {
                        throw new \RuntimeException('Error occurred during gzip decompression.');
                    }
                    fwrite($out, $buffer);
                }
                gzclose($gz);
                fclose($out);
            } else {
                // Fallback using gunzip command
                $io->comment('PHP zlib extension not loaded. Falling back to system "gunzip"...');
                $process = new Process(['gunzip', '-k', '-c', $tempCompressed]);
                $process->setTimeout(300);
                
                // Write uncompressed output directly to target file
                $out = fopen($tempUncompressed, 'wb');
                $process->run(function ($type, $buffer) use ($out) {
                    fwrite($out, $buffer);
                });
                fclose($out);

                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Failed to decompress using gunzip: ' . $process->getErrorOutput());
                }
            }
        } catch (\Exception $e) {
            $io->error('Decompression failed: ' . $e->getMessage());
            
            // Cleanup
            if (file_exists($tempCompressed)) {
                unlink($tempCompressed);
            }
            if (file_exists($tempUncompressed)) {
                unlink($tempUncompressed);
            }
            return Command::FAILURE;
        }

        // Remove the compressed temp file
        if (file_exists($tempCompressed)) {
            unlink($tempCompressed);
        }

        // Backup existing SDE file if it exists
        if (file_exists($sdeFile)) {
            $io->text('Backing up existing SDE database...');
            
            // Clean up any older backups in var directory first to save space
            $oldBackups = glob($varDir . '/sde.sqlite.backup-*');
            if ($oldBackups) {
                foreach ($oldBackups as $oldBackup) {
                    unlink($oldBackup);
                }
            }

            // Rename it with a timestamp
            $timestamp = date('YmdHis');
            $backupFile = $varDir . '/sde.sqlite.backup-' . $timestamp;
            rename($sdeFile, $backupFile);
            $io->comment(sprintf('Previous SDE database backed up to "%s"', basename($backupFile)));
        }

        // Move new uncompressed file to target location
        $io->text('Activating new SDE database...');
        if (!rename($tempUncompressed, $sdeFile)) {
            $io->error('Failed to move uncompressed database to its final location: ' . $sdeFile);
            return Command::FAILURE;
        }

        // Save status to status file
        $newStatus = [
            'last_modified' => $remoteLastModified,
            'etag' => $remoteETag,
            'content_length' => $remoteContentLength,
            'downloaded_at' => date(\DateTimeInterface::ATOM),
        ];
        file_put_contents($statusFile, json_encode($newStatus, JSON_PRETTY_PRINT));

        $io->success('EVE Online SDE SQLite database updated successfully!');

        return Command::SUCCESS;
    }
}
