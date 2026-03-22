<?php

declare(strict_types=1);

namespace OCA\Reel\BackgroundJob;

use OCA\Reel\AppInfo\Application;
use OCA\Reel\Service\VideoRenderingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Notification\IManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * RenderJob
 *
 * A QueuedJob that renders a single event video.
 * Queued via IJobList::add() when the user triggers a render.
 * Runs once and is then removed from the queue automatically.
 *
 * Argument array: ['event_id' => int, 'user_id' => string, 'job_id' => int]
 */
class RenderJob extends QueuedJob {

    public function __construct(
        ITimeFactory                   $time,
        private VideoRenderingService  $renderingService,
        private IDBConnection          $db,
        private IManager               $notificationManager,
        private IURLGenerator          $urlGenerator,
        private LoggerInterface        $logger,
    ) {
        parent::__construct($time);

        // FFmpeg renders can take minutes — not time sensitive
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void {
        $eventId = (int)$argument['event_id'];
        $userId  = (string)$argument['user_id'];
        $jobId   = (int)$argument['job_id'];
        $notifyOnDone = !empty($argument['notify_on_done']);

        $this->logger->info('Reel: RenderJob starting for event {event}, job {job}', [
            'event' => $eventId,
            'job'   => $jobId,
        ]);

        $this->updateJobStatus($jobId, 'running', 0);

        try {
            $fileId = $this->renderingService->renderEvent(
                $eventId,
                $userId,
                function (int $progress) use ($jobId): void {
                    $this->updateJobStatus($jobId, 'running', $progress);
                },
                function (string $message) use ($eventId, $jobId): void {
                    $this->logger->info('Reel debug: event {event}, job {job}: {msg}', [
                        'event' => $eventId,
                        'job' => $jobId,
                        'msg' => $message,
                    ]);
                },
            );
            $this->updateJobStatus($jobId, 'done', 100);
            $this->updateEventVideoFileId($eventId, $userId, $fileId);

            if ($notifyOnDone) {
                $this->sendVideoReadyNotification($eventId, $userId);
            }

            $this->logger->info('Reel: RenderJob complete for event {event}, file {file}', [
                'event' => $eventId,
                'file'  => $fileId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Reel: RenderJob failed for event {event}: {msg}', [
                'event' => $eventId,
                'msg'   => $e->getMessage(),
            ]);
            $this->updateJobStatus($jobId, 'failed', 0, $e->getMessage());
        }
    }

    private function updateEventVideoFileId(int $eventId, string $userId, int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_events')
            ->set('video_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
            ->set('updated_at',    $qb->createNamedParameter(time(),   IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id',      $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }

    private function updateJobStatus(int $jobId, string $status, int $progress, ?string $error = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_jobs')
            ->set('status',     $qb->createNamedParameter($status))
            ->set('progress',   $qb->createNamedParameter($progress,  IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(),      IQueryBuilder::PARAM_INT));

        if ($error !== null) {
            $qb->set('error', $qb->createNamedParameter(mb_substr($error, 0, 4000)));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
           ->executeStatement();
    }

    private function sendVideoReadyNotification(int $eventId, string $userId): void {
        $eventTitle = $this->loadEventTitle($eventId, $userId) ?? ('Event #' . $eventId);

        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('reel_event', (string)$eventId)
            ->setSubject('video_ready', ['event_title' => $eventTitle])
            ->setLink($this->urlGenerator->linkToRouteAbsolute('reel.page.event', ['id' => $eventId]));

        $this->notificationManager->notify($notification);
    }

    private function loadEventTitle(int $eventId, string $userId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('title')
            ->from('reel_events')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $title = $qb->executeQuery()->fetchOne();
        return $title !== false ? (string)$title : null;
    }
}
