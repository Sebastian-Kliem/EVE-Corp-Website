<?php

namespace App\Command;

use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:discord:test',
    description: 'Tests sending a notification message to configured Discord webhook(s).',
)]
class TestDiscordNotificationCommand extends Command
{
    public function __construct(
        private readonly DiscordWebhookService $discordWebhookService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('channel', InputArgument::OPTIONAL, 'Target channel (default, fuel, combat, structures, etc.)', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $channel = (string)$input->getArgument('channel');

        $io->title(sprintf('Testing Discord Notification for channel: "%s"', $channel));

        if (!$this->discordWebhookService->isConfigured($channel)) {
            $io->warning(sprintf('No webhook URL configured for channel "%s" (or default). Set DISCORD_WEBHOOK_DEFAULT or DISCORD_WEBHOOK_%s in .env / .env.local', $channel, strtoupper($channel)));
            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $embed = (new DiscordEmbed())
            ->setTitle('🧪 [TEST] Keepers of Duat Notification Test')
            ->setColor(DiscordColor::BLUE)
            ->setDescription(sprintf('Dies ist eine Testnachricht für den Discord-Kanal: **%s**.', $channel))
            ->addField('📡 Status', 'Webhook-Verbindung erfolgreich eingerichtet ✅', false)
            ->addField('🕒 Sendezeitpunkt', $now->format('d.m.Y H:i:s') . ' (Server)', true)
            ->addField('🤖 System', 'Keepers of Duat Notification Engine', true)
            ->setFooter('Keepers of Duat • Discord Integration')
            ->setTimestamp($now);

        $message = DiscordMessage::create('🔔 Keepers of Duat Testbenachrichtigung')
            ->setUsername('Keepers of Duat Test')
            ->addEmbed($embed);

        $success = $this->discordWebhookService->send($message, $channel);

        if ($success) {
            $io->success(sprintf('Test message successfully sent to Discord channel "%s"!', $channel));
            return Command::SUCCESS;
        }

        $io->error(sprintf('Failed to send test message to Discord channel "%s". Check logs for details.', $channel));
        return Command::FAILURE;
    }
}
