<?php

declare(strict_types=1);

namespace OCA\Reel\Command;

use OCA\Reel\Service\EventDetectionService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Usage:
 *   php occ reel:detect-events                  # runs for ALL users
 *   php occ reel:detect-events --user=alice      # runs for a single user
 *   php occ reel:detect-events --rebuild         # full rebuild (drop + recreate)
 */
class DetectEvents extends Command {

    public function __construct(
        private EventDetectionService $detectionService,
        private IUserManager          $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('reel:detect-events')
            ->setDescription('Detect photo/video events and cluster them into Reels')
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'Run for a specific user only (omit to run for all users)',
            )
            ->addOption(
                'rebuild',
                'r',
                InputOption::VALUE_NONE,
                'Force full rebuild: delete existing Reel events/media and recreate from current detection logic',
            )
            ->addOption(
                'debug',
                'd',
                InputOption::VALUE_NONE,
                'Print per-media detection/settings details while processing',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getOption('user');
        $rebuild = (bool)$input->getOption('rebuild');
        $debug = (bool)$input->getOption('debug');

        if ($userId !== null) {
            // Single user
            if (!$this->userManager->userExists($userId)) {
                $output->writeln("<error>User '{$userId}' does not exist.</error>");
                return Command::FAILURE;
            }
            return $this->runForUser($userId, $output, $rebuild, $debug);
        }

        // All users
        $exitCode = Command::SUCCESS;
        $this->userManager->callForAllUsers(function (\OCP\IUser $user) use ($output, &$exitCode, $rebuild, $debug) {
            $result = $this->runForUser($user->getUID(), $output, $rebuild, $debug);
            if ($result !== Command::SUCCESS) {
                $exitCode = $result;
            }
        });

        return $exitCode;
    }

    private function runForUser(string $userId, OutputInterface $output, bool $rebuild, bool $debug): int {
        $output->writeln("Processing user: <info>{$userId}</info>");
        if ($rebuild) {
            $output->writeln('  → Mode: <comment>full rebuild</comment> (existing Reel events/media will be replaced)');
        }
        if ($debug) {
            $output->writeln('  → Mode: <comment>debug</comment> (printing per-media settings)');
        }

        try {
            $count = $this->detectionService->detectForUser(
                $userId,
                $rebuild,
                $debug
                    ? static function (string $line) use ($output): void {
                        $output->writeln('    ' . $line);
                    }
                    : null,
            );
            $output->writeln("  → Detected <info>{$count}</info> event(s).");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("  <error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
