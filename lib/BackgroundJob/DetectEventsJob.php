<?php

declare(strict_types=1);

namespace OCA\Reel\BackgroundJob;

use OCA\Reel\AppInfo\Application;
use OCA\Reel\Service\EventDetectionService;
use OCA\Reel\Service\MusicService;
use OCA\Reel\Service\RenderJobService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
        private RenderJobService      $renderJobService,
        private IDBConnection         $db,
        private IConfig               $config,
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

                $this->maybeAutoCreateVideos($uid);
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

    private function maybeAutoCreateVideos(string $userId): void {
        $enabled = $this->config->getUserValue($userId, Application::APP_ID, 'auto_create_videos', '0') === '1';
        if (!$enabled) {
            return;
        }

        $candidates = $this->loadLatestRenderableEvents($userId, 3);
        $queued = 0;

        foreach ($candidates as $event) {
            $eventId = (int)$event['id'];
            if ($this->hasActiveRenderJob($eventId, $userId)) {
                continue;
            }

            $this->renderJobService->enqueue($eventId, $userId, true);
            $queued++;
        }

        if ($queued > 0) {
            $this->logger->info('Reel: auto-queued {count} render job(s) for user {user}', [
                'count' => $queued,
                'user' => $userId,
            ]);
        }
    }

    /** @return array<int, array{id:int}> */
    private function loadLatestRenderableEvents(string $userId, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('reel_events')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('video_file_id'))
            ->andWhere($qb->expr()->isNull('parent_event_id'))
            ->orderBy('date_start', 'DESC')
            ->setMaxResults($limit);

        return array_map(static fn(array $row): array => ['id' => (int)$row['id']], $qb->executeQuery()->fetchAll());
    }

    private function hasActiveRenderJob(int $eventId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('reel_jobs')
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->setMaxResults(1);

        return $qb->executeQuery()->fetchOne() !== false;
    }
}
