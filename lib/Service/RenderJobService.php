<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCA\Reel\BackgroundJob\RenderJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class RenderJobService {

    public function __construct(
        private IDBConnection $db,
        private IJobList      $jobList,
    ) {}

    /**
     * Create a new render job row, queue the background job, return the job row.
     */
    public function enqueue(int $eventId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $now = time();

        $qb->insert('reel_jobs')
            ->values([
                'event_id'   => $qb->createNamedParameter($eventId,  IQueryBuilder::PARAM_INT),
                'user_id'    => $qb->createNamedParameter($userId),
                'status'     => $qb->createNamedParameter('pending'),
                'progress'   => $qb->createNamedParameter(0,         IQueryBuilder::PARAM_INT),
                'error'      => $qb->createNamedParameter(null,      IQueryBuilder::PARAM_NULL),
                'created_at' => $qb->createNamedParameter($now,      IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($now,      IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        $jobId = $this->db->lastInsertId('oc_reel_jobs');

        // Queue the actual background job
        $this->jobList->add(RenderJob::class, [
            'event_id' => $eventId,
            'user_id'  => $userId,
            'job_id'   => (int)$jobId,
        ]);

        return $this->getJob((int)$jobId, $userId);
    }

    /**
     * Get the most recent job for each of the given event IDs.
     * Returns an array keyed by event_id.
     */
    public function getLatestForEvents(array $eventIds, string $userId): array {
        if (empty($eventIds)) {
            return [];
        }

        $jobs = [];
        foreach (array_chunk($eventIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('reel_jobs')
                ->where($qb->expr()->in('event_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->orderBy('created_at', 'DESC');

            foreach ($qb->executeQuery()->fetchAll() as $row) {
                $eid = (int)$row['event_id'];
                if (!isset($jobs[$eid])) {
                    $jobs[$eid] = $row; // first seen = most recent (DESC order)
                }
            }
        }

        return $jobs;
    }

    /**
     * Get the most recent job for an event.
     */
    public function getLatestForEvent(int $eventId, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('reel_jobs')
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);

        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    private function getJob(int $jobId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('reel_jobs')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $qb->executeQuery()->fetch();
    }
}
