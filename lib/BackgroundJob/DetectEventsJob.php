<?php

declare(strict_types=1);

namespace OCA\Reel\BackgroundJob;

use OCA\Reel\Service\EventDetectionService;
use OCA\Reel\Service\MusicService;
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
        private MusicService          $musicService,
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
            $uid = $user->getUID();
            try {
                $count = $this->detectionService->detectForUser($uid);
                $this->logger->info('Reel: detected {count} events for user {user}', [
                    'count' => $count,
                    'user'  => $uid,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Reel: event detection failed for user {user}: {msg}', [
                    'user' => $uid,
                    'msg'  => $e->getMessage(),
                ]);
            }

            // Refresh the custom music file cache so the UI reflects any
            // additions/removals in the user's chosen music folder.
            try {
                $this->musicService->refreshCache($uid);
            } catch (\Throwable $e) {
                $this->logger->warning('Reel: music cache refresh failed for user {user}: {msg}', [
                    'user' => $uid,
                    'msg'  => $e->getMessage(),
                ]);
            }
        });

        $this->logger->info('Reel: DetectEventsJob complete');
    }
}
