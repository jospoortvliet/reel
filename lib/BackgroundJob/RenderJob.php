<?php

declare(strict_types=1);

namespace OCA\Reel\BackgroundJob;

use OCA\Reel\Service\VideoRenderingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
                }
            );
            $this->updateJobStatus($jobId, 'done', 100);
            $this->updateEventVideoFileId($eventId, $userId, $fileId);

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
}
