<?php

declare(strict_types=1);

namespace OCA\Reel\BackgroundJob;

use OCA\Reel\Service\EventDetectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * DetectEventsJob
 *
 * Runs nightly to re-detect events for all users.
 * Uses TIME_INSENSITIVE so Nextcloud can schedule it during
 * low-load hours rather than interrupting active users.
 */
class DetectEventsJob extends TimedJob {

    public function __construct(
        ITimeFactory                  $time,
        private EventDetectionService $detectionService,
        private IUserManager          $userManager,
        private LoggerInterface       $logger,
    ) {
        parent::__construct($time);

        // Run once per day
        $this->setInterval(24 * 3600);

        // Delay until low-load nightly window
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);

        // Don't run multiple instances in parallel
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void {
        $this->logger->info('Reel: DetectEventsJob starting');

        $this->userManager->callForAllUsers(function (\OCP\IUser $user) {
            try {
                $count = $this->detectionService->detectForUser($user->getUID());
                $this->logger->info('Reel: detected {count} events for user {user}', [
                    'count' => $count,
                    'user'  => $user->getUID(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Reel: event detection failed for user {user}: {msg}', [
                    'user' => $user->getUID(),
                    'msg'  => $e->getMessage(),
                ]);
            }
        });

        $this->logger->info('Reel: DetectEventsJob complete');
    }
}
