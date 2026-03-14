<?php

declare(strict_types=1);

namespace OCA\Reel\Command;

use OCA\Reel\Service\VideoRenderingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RenderEvent extends Command {

    public function __construct(
        private VideoRenderingService $renderingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('reel:render-event')
            ->setDescription('Render a highlight video for a specific Reel event')
            ->addArgument('event-id', InputArgument::REQUIRED, 'The event ID to render')
            ->addArgument('user-id',  InputArgument::REQUIRED, 'The user ID who owns the event')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Print each FFmpeg command before running it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $eventId      = (int)$input->getArgument('event-id');
        $userId       = $input->getArgument('user-id');
        $verboseFfmpeg = (bool)$input->getOption('debug');

        $output->writeln("Rendering event <info>{$eventId}</info> for user <info>{$userId}</info>...");

        try {
            $fileId = $this->renderingService->renderEvent(
                $eventId,
                $userId,
                // Progress callback — print each step to the console
                function (int $progress) use ($output): void {
                    $output->writeln("  progress: <info>{$progress}%</info>");
                },
                // Debug callback — print FFmpeg commands and HEIC conversions
                $verboseFfmpeg
                    ? function (string $msg) use ($output): void {
                        $output->writeln("  <comment>{$msg}</comment>");
                    }
                    : null,
            );
            $output->writeln("<info>Done!</info> Video saved with file ID: <info>{$fileId}</info>");
            $output->writeln("Check your <info>Reels/</info> folder in Nextcloud Files.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Render failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
