<?php

declare(strict_types=1);

namespace OCA\Reel\Controller;

use OCA\Reel\AppInfo\Application;
use OCA\Reel\Service\EventDetectionService;
use OCA\Reel\Service\MemoriesRepository;
use OCA\Reel\Service\RenderJobService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Files\IRootFolder;

class ApiController extends OCSController {

    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private IDBConnection         $db,
        private EventDetectionService $detectionService,
        private RenderJobService      $jobService,
        private ?string               $userId,
        private IRootFolder           $rootFolder,
        private IConfig               $config,
        private MemoriesRepository    $memoriesRepository,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------

    /** Returns a 401 response if the user is not authenticated, null otherwise. */
    private function requireUserId(): ?DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }
        return null;
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/events')]
    public function listEvents(): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;

        $qb = $this->db->getQueryBuilder();
        $qb->select('e.*')
            ->selectAlias($qb->createFunction('COUNT(m.id)'), 'media_count')
            ->from('reel_events', 'e')
            ->leftJoin('e', 'reel_event_media', 'm', $qb->expr()->eq('e.id', 'm.event_id'))
            ->where($qb->expr()->eq('e.user_id', $qb->createNamedParameter($this->userId)))
            ->groupBy('e.id')
            ->orderBy('e.date_start', 'DESC');

        $events = $qb->executeQuery()->fetchAll();

        // Batch-load cover file IDs and the most recent job for all events in two
        // portable queries, avoiding N+1 per-event queries.
        $covers = [];
        $jobsByEventId = [];
        if (!empty($events)) {
            $eventIds = array_map('intval', array_column($events, 'id'));

            foreach (array_chunk($eventIds, 1000) as $chunk) {
                $cqb = $this->db->getQueryBuilder();
                $cqb->select('event_id', 'file_id')
                    ->from('reel_event_media')
                    ->where($cqb->expr()->in('event_id', $cqb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->andWhere($cqb->expr()->eq('included', $cqb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                    ->orderBy('event_id', 'ASC')
                    ->addOrderBy('sort_order', 'ASC');
                foreach ($cqb->executeQuery()->fetchAll() as $row) {
                    $eid = (int)$row['event_id'];
                    if (!isset($covers[$eid])) {
                        $covers[$eid] = (int)$row['file_id'];
                    }
                }
            }

            $jobsByEventId = $this->jobService->getLatestForEvents($eventIds, $this->userId);
        }

        foreach ($events as &$event) {
            $event['cover_file_id'] = $covers[(int)$event['id']] ?? null;
            $job = $jobsByEventId[(int)$event['id']] ?? null;
            $event['job'] = $job ? $this->formatJob($job) : null;
            if ($event['cover_file_id']) {
                $event['cover_thumbnail_url'] = '/index.php/core/preview?fileId='
                    . $event['cover_file_id'] . '&x=400&y=300&forceIcon=0';
            } else {
                $event['cover_thumbnail_url'] = null;
            }
        }

        return new DataResponse($events);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/events/{id}')]
    public function getEvent(int $id): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;
        $event = $this->fetchEvent($id);
        if (!$event) {
            return new DataResponse(['error' => 'Not found'], 404);
        }

        $event['media'] = $this->fetchMedia($id);
        $event['job']   = null;

        if (!empty($event['video_file_id'])) {
            $event['video_path'] = $this->getFilePath((int)$event['video_file_id']);
        } else {
            $event['video_path'] = null;
        }

        $job = $this->jobService->getLatestForEvent($id, $this->userId);
        if ($job) {
            $event['job'] = $this->formatJob($job);
        }

        return new DataResponse($event);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/events/{id}')]
    public function updateEvent(int $id, ?string $title = null, ?string $theme = null): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;
        if ($title === null && $theme === null) {
            return new DataResponse($this->fetchEvent($id));
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_events');

        if ($title !== null) {
            $qb->set('title', $qb->createNamedParameter($title));
        }
        if ($theme !== null) {
            $allowedThemes = ['acoustic_folk', 'indie_pop', 'cinematic_orchestral'];
            if (!in_array($theme, $allowedThemes, true)) {
                $theme = 'indie_pop';
            }
            $qb->set('theme', $qb->createNamedParameter($theme));
        }

        $qb->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id',      $qb->createNamedParameter($id,          IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
            ->executeStatement();

        return new DataResponse($this->fetchEvent($id));
    }

    // -------------------------------------------------------------------------
    // Media
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/events/{id}/media/{fileId}')]
    public function updateMedia(
        int $id,
        int $fileId,
        ?bool $included = null,
        ?bool $use_live_video = null,
        ?float $video_start = null,
        ?float $video_length = null,
    ): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_event_media');

        if ($included !== null) {
            $qb->set('included', $qb->createNamedParameter($included ? 1 : 0, IQueryBuilder::PARAM_INT));
        }

        if ($use_live_video !== null || $video_start !== null || $video_length !== null) {
            $settings = $this->loadEditSettings($id, $fileId);

            if ($use_live_video !== null) {
                $settings['use_live_video'] = $use_live_video;
            }

            if ($video_start !== null) {
                $settings['video_start'] = max(0.0, (float)$video_start);
            }
            if ($video_length !== null) {
                $settings['video_length'] = max(0.6, (float)$video_length);
            }

            $qb->set('edit_settings', $qb->createNamedParameter(json_encode($settings)));
        }

        $qb->where($qb->expr()->eq('event_id', $qb->createNamedParameter($id,      IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id',  $qb->createNamedParameter($fileId,  IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id',  $qb->createNamedParameter($this->userId)))
            ->executeStatement();

        return new DataResponse(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/events/{id}/render')]
    public function renderEvent(int $id): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;
        $event = $this->fetchEvent($id);
        if (!$event) {
            return new DataResponse(['error' => 'Not found'], 404);
        }

        $existing = $this->jobService->getLatestForEvent($id, $this->userId);
        if ($existing && in_array($existing['status'], ['pending', 'running'], true)) {
            return new DataResponse([
                'error' => 'A render job is already in progress',
                'job'   => $this->formatJob($existing),
            ], 409);
        }

        // Regenerate path: hide stale previous output while new render is running.
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_events')
            ->set('video_file_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
            ->executeStatement();

        $job = $this->jobService->enqueue($id, $this->userId);

        return new DataResponse(['job' => $this->formatJob($job)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/events/{id}/status')]
    public function getEventStatus(int $id): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;
        $event = $this->fetchEvent($id);
        if (!$event) {
            return new DataResponse(['error' => 'Not found'], 404);
        }

        $job = $this->jobService->getLatestForEvent($id, $this->userId);

        $videoPath = null;
        if (!empty($event['video_file_id'])) {
            $videoPath = $this->getFilePath((int)$event['video_file_id']);
        }

        return new DataResponse([
            'event_id'      => $id,
            'video_file_id' => $event['video_file_id'] ?? null,
            'video_path'    => $videoPath,
            'job'           => $job ? $this->formatJob($job) : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fetchEvent(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('reel_events')
            ->where($qb->expr()->eq('id',      $qb->createNamedParameter($id,       IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));

        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    private function fetchMedia(int $eventId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.*', 'mem.w', 'mem.h', 'mem.isvideo', 'mem.liveid', 'mem.video_duration')
            ->from('reel_event_media', 'm')
            ->leftJoin('m', 'memories', 'mem', $qb->expr()->eq('m.file_id', 'mem.fileid'))
            ->where($qb->expr()->eq('m.event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('m.user_id',  $qb->createNamedParameter($this->userId)))
            ->orderBy('m.sort_order', 'ASC');

        $rows = $qb->executeQuery()->fetchAll();

        // Output orientation determines what "matching" means for the auto-rule
        $outputOrientation = $this->config->getUserValue(
            $this->userId,
            Application::APP_ID,
            'output_orientation',
            'landscape_16_9',
        );
        $outputIsLandscape = ($outputOrientation === 'landscape_16_9');

        return array_map(function (array $row) use ($outputIsLandscape) {
            $row['thumbnail_url'] = '/index.php/core/preview?fileId=' . $row['file_id']
                . '&x=320&y=240&forceIcon=0';
            $row['viewer_path'] = $this->getFilePath((int)$row['file_id']);
            $row['w']       = (int)($row['w'] ?? 0);
            $row['h']       = (int)($row['h'] ?? 0);
            $row['isvideo'] = (bool)($row['isvideo'] ?? false);
            $row['liveid']  = $row['liveid'] ?? null;

            // Only include liveid if a corresponding live video file actually exists
            if ($row['liveid']) {
                $liveVideoFileId = $this->memoriesRepository->findLiveVideoFileId((int)$row['file_id']);
                if ($liveVideoFileId === null) {
                    // No live video file found; clear liveid so UI won't show toggle
                    $row['liveid'] = null;
                }
            }

            // Resolve use_live_video: explicit override > auto orientation-match rule
            $settings = !empty($row['edit_settings'])
                ? (json_decode($row['edit_settings'], true) ?? [])
                : [];

            $row['video_duration'] = isset($row['video_duration']) ? (float)$row['video_duration'] : 0.0;
            $row['video_start'] = isset($settings['video_start']) && is_numeric($settings['video_start'])
                ? max(0.0, (float)$settings['video_start'])
                : 0.0;
            $row['video_length'] = isset($settings['video_length']) && is_numeric($settings['video_length'])
                ? max(0.6, (float)$settings['video_length'])
                : null;

            if (isset($settings['use_live_video'])) {
                $row['use_live_video'] = (bool)$settings['use_live_video'];
            } elseif ($row['liveid']) {
                // Auto rule: use .mov when the still's orientation matches output orientation
                $stillIsLandscape = $row['w'] > 0 && $row['h'] > 0 && $row['w'] >= $row['h'];
                $row['use_live_video'] = ($stillIsLandscape === $outputIsLandscape);
            } else {
                $row['use_live_video'] = false;
            }

            $isClipVideo = $row['isvideo'] || $row['use_live_video'];
            $canEditClipTiming = $row['isvideo'];
            $defaultLength = $row['video_duration'] > 0
                ? min(8.0, $row['video_duration'])
                : 8.0;
            $effectiveLength = $row['video_length'] ?? $defaultLength;
            $maxStart = max(0.0, $row['video_duration'] - $effectiveLength);
            $defaultStart = ($row['video_length'] === null && $row['video_duration'] > ($effectiveLength + 0.05))
                ? max(0.0, ($row['video_duration'] - $effectiveLength) / 2.0)
                : 0.0;
            $effectiveStart = min($row['video_start'] ?: $defaultStart, $maxStart);

            $row['is_clip_video'] = $isClipVideo;
            $row['can_edit_clip_timing'] = $canEditClipTiming;
            $row['effective_video_start'] = $canEditClipTiming ? $effectiveStart : 0.0;
            $row['effective_video_length'] = $canEditClipTiming ? $effectiveLength : 0.0;
            if (!$canEditClipTiming) {
                $row['video_start'] = 0.0;
                $row['video_length'] = null;
            }

            return $row;
        }, $rows);
    }

    private function loadEditSettings(int $eventId, int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('edit_settings')
            ->from('reel_event_media')
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));

        $existing = $qb->executeQuery()->fetchOne();
        return $existing ? (json_decode((string)$existing, true) ?? []) : [];
    }

    private function formatJob(array $job): array {
        return [
            'id'         => (int)$job['id'],
            'event_id'   => (int)$job['event_id'],
            'status'     => $job['status'],
            'progress'   => (int)$job['progress'],
            'error'      => $job['error'] ?? null,
            'created_at' => (int)$job['created_at'],
            'updated_at' => (int)$job['updated_at'],
        ];
    }
    /**
     * Look up the user-relative path for a file using the Node API.
     * Returns e.g. /Reels/My Event.mp4 which OCA.Viewer.open() expects.
     */
    private function getFilePath(int $fileId): ?string {
        try {
            $userFolder = $this->rootFolder->getUserFolder($this->userId);
            $nodes      = $userFolder->getById($fileId);
            if (empty($nodes)) {
                return null;
            }
            return $userFolder->getRelativePath($nodes[0]->getPath());
        } catch (\Throwable $e) {
            return null;
        }
    }
}
