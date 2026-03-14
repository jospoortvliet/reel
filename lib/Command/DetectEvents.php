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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getOption('user');
        $rebuild = (bool)$input->getOption('rebuild');

        if ($userId !== null) {
            // Single user
            if (!$this->userManager->userExists($userId)) {
                $output->writeln("<error>User '{$userId}' does not exist.</error>");
                return Command::FAILURE;
            }
            return $this->runForUser($userId, $output, $rebuild);
        }

        // All users
        $exitCode = Command::SUCCESS;
        $this->userManager->callForAllUsers(function (\OCP\IUser $user) use ($output, &$exitCode, $rebuild) {
            $result = $this->runForUser($user->getUID(), $output, $rebuild);
            if ($result !== Command::SUCCESS) {
                $exitCode = $result;
            }
        });

        return $exitCode;
    }

    private function runForUser(string $userId, OutputInterface $output, bool $rebuild): int {
        $output->writeln("Processing user: <info>{$userId}</info>");
        if ($rebuild) {
            $output->writeln('  → Mode: <comment>full rebuild</comment> (existing Reel events/media will be replaced)');
        }

        try {
            $count = $this->detectionService->detectForUser($userId, $rebuild);
            $output->writeln("  → Detected <info>{$count}</info> event(s).");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("  <error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
