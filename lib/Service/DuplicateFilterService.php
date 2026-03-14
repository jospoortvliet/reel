<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCA\Reel\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * DuplicateFilterService
 *
 * Detects near-duplicate photos within an event using two conditions:
 *   1. Time proximity  — photos taken within burst_gap_seconds of each other
 *   2. Visual similarity — blurhash Hamming distance below similarity_threshold
 *
 * Winner selection within a duplicate group:
 *   1. Highest face composition score (Recognize face size x centrality)
 *   2. Sharpest image via Imagick Laplacian variance
 *   3. Middle of group as last resort
 *
 * Videos are never deduplicated. Both thresholds are configurable per user
 * via IConfig and exposed in the Settings UI.
 *
 * Public API:
 *   analyseEvent() -- dry run, returns structured report, no DB writes
 *   filterEvent()  -- calls analyseEvent() then writes exclusions to DB
 */
class DuplicateFilterService {

    private const DEFAULT_BURST_GAP_SECONDS    = 5;
    private const DEFAULT_SIMILARITY_THRESHOLD = 16;
    private const MIN_BURST_SIZE               = 2;
    private const MAX_BURST_SIZE               = 5;

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
        private IConfig         $config,
        private IRootFolder     $rootFolder,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Dry-run analysis -- returns a report without writing anything to the DB.
     *
     * Return shape:
     * [
     *   'bursts' => [
     *     [
     *       'photos'   => [['file_id' => int, 'name' => string, 'datetaken' => int], ...],
     *       'winner'   => int,    // file_id of the photo that would be kept
     *       'excluded' => int[],  // file_ids that would be excluded
     *       'method'   => string, // 'face_score' | 'sharpness' | 'middle'
     *     ],
     *     ...
     *   ],
     *   'thresholds' => ['burst_gap' => int, 'similarity' => int],
     * ]
     */
    public function analyseEvent(int $eventId, string $userId): array {
        $photos    = $this->loadPhotos($eventId, $userId);
        $burstGap  = $this->burstGapSeconds($userId);
        $simThresh = $this->similarityThreshold($userId);

        if (count($photos) < self::MIN_BURST_SIZE) {
            return ['bursts' => [], 'thresholds' => ['burst_gap' => $burstGap, 'similarity' => $simThresh]];
        }

        $fileIds    = array_column($photos, 'file_id');
        $blurhashes = $this->loadBlurhashes($fileIds);
        $bursts     = $this->detectBursts($photos, $blurhashes, $userId);

        $report = [];
        foreach ($bursts as $burst) {
            $burstFileIds        = array_column($burst, 'file_id');
            $scores              = $this->scorePhotos($burstFileIds, $userId);
            [$winnerId, $method] = $this->pickWinner($burst, $scores, $userId);

            $excluded = [];
            foreach ($burst as $photo) {
                if ((int)$photo['file_id'] !== $winnerId) {
                    $excluded[] = (int)$photo['file_id'];
                }
            }

            $report[] = [
                'photos'   => $burst,
                'winner'   => $winnerId,
                'excluded' => $excluded,
                'method'   => $method,
            ];
        }

        return [
            'bursts'     => $report,
            'thresholds' => ['burst_gap' => $burstGap, 'similarity' => $simThresh],
        ];
    }

    /**
     * Filter duplicates for all included photos in an event.
     * Calls analyseEvent() for the detection logic, then writes exclusions to DB.
     * Returns the number of items marked as excluded.
     */
    public function filterEvent(int $eventId, string $userId): int {
        $analysis = $this->analyseEvent($eventId, $userId);

        $excluded = [];
        foreach ($analysis['bursts'] as $burst) {
            $excluded = array_merge($excluded, $burst['excluded']);
        }

        if (empty($excluded)) {
            return 0;
        }

        $this->markExcluded($eventId, $userId, $excluded);

        $this->logger->info('Reel: excluded {n} duplicate photos from event {id}', [
            'n'  => count($excluded),
            'id' => $eventId,
        ]);

        return count($excluded);
    }

    // -------------------------------------------------------------------------
    // Burst detection
    // -------------------------------------------------------------------------

    private function detectBursts(array $photos, array $blurhashes, string $userId): array {
        usort($photos, fn($a, $b) => (int)$a['datetaken'] <=> (int)$b['datetaken']);

        $duplicateGroups = [];
        $current         = [$photos[0]];

        for ($i = 1; $i < count($photos); $i++) {
            $timeDiff     = (int)$photos[$i]['datetaken'] - (int)$photos[$i - 1]['datetaken'];
            $prevHash     = $blurhashes[(int)$photos[$i - 1]['file_id']] ?? null;
            $currHash     = $blurhashes[(int)$photos[$i]['file_id']] ?? null;
            $visuallySame = $prevHash && $currHash
                && $this->hammingDistance($prevHash, $currHash) < $this->similarityThreshold($userId);

            if ($timeDiff <= $this->burstGapSeconds($userId) && $visuallySame) {
                $current[] = $photos[$i];
            } else {
                if (count($current) >= self::MIN_BURST_SIZE
                    && count($current) <= self::MAX_BURST_SIZE) {
                    $duplicateGroups[] = $current;
                }
                $current = [$photos[$i]];
            }
        }

        if (count($current) >= self::MIN_BURST_SIZE
            && count($current) <= self::MAX_BURST_SIZE) {
            $duplicateGroups[] = $current;
        }

        return $duplicateGroups;
    }

    // -------------------------------------------------------------------------
    // Scoring
    // -------------------------------------------------------------------------

    /**
     * Score each photo by face composition quality.
     * Returns [file_id => score]. Files with no face data score 0.0.
     */
    private function scorePhotos(array $fileIds, string $userId): array {
        $scores = array_fill_keys($fileIds, 0.0);

        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'x', 'y', 'width', 'height')
            ->from('recognize_face_detections')
            ->where($qb->expr()->in(
                'file_id',
                $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->andWhere($qb->expr()->eq(
                'user_id',
                $qb->createNamedParameter($userId)
            ));

        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $fileId     = (int)$row['file_id'];
            $cx         = (float)$row['x'] + (float)$row['width']  / 2;
            $cy         = (float)$row['y'] + (float)$row['height'] / 2;
            $size       = (float)$row['width'] * (float)$row['height'];
            $centrality = 1.0 - 2.0 * sqrt(($cx - 0.5) ** 2 + ($cy - 0.5) ** 2);
            $scores[$fileId] = ($scores[$fileId] ?? 0.0) + $size * max(0.0, $centrality);
        }

        return $scores;
    }

    /**
     * Pick the best photo from a burst group.
     * Returns [winnerId, method] where method is 'face_score' | 'sharpness' | 'middle'.
     */
    private function pickWinner(array $group, array $scores, string $userId): array {
        // 1. Face composition score
        $hasAnyFaces = false;
        foreach ($group as $photo) {
            if (($scores[(int)$photo['file_id']] ?? 0.0) > 0.0) {
                $hasAnyFaces = true;
                break;
            }
        }

        if ($hasAnyFaces) {
            $best = null; $bestScore = -1.0;
            foreach ($group as $photo) {
                $score = $scores[(int)$photo['file_id']] ?? 0.0;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best      = (int)$photo['file_id'];
                }
            }
            return [$best, 'face_score'];
        }

        // 2. Sharpness via Imagick Laplacian variance
        if (class_exists(\Imagick::class)) {
            $best = null; $bestSharpness = -1.0;
            foreach ($group as $photo) {
                $sharpness = $this->measureSharpness((int)$photo['file_id'], $userId);
                if ($sharpness > $bestSharpness) {
                    $bestSharpness = $sharpness;
                    $best          = (int)$photo['file_id'];
                }
            }
            if ($best !== null) {
                return [$best, 'sharpness'];
            }
        }

        // 3. Middle of group
        return [(int)$group[(int)floor((count($group) - 1) / 2)]['file_id'], 'middle'];
    }

    /**
     * Measure sharpness using Imagick Laplacian variance.
     * High variance = sharp image. Returns 0.0 on any error.
     */
    private function measureSharpness(int $fileId, string $userId): float {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes      = $userFolder->getById($fileId);
            if (empty($nodes)) {
                return 0.0;
            }
            $localPath = $nodes[0]->getStorage()->getLocalFile($nodes[0]->getInternalPath());
            if (!$localPath || !file_exists($localPath)) {
                return 0.0;
            }

            $imagick = new \Imagick($localPath);
            $imagick->resizeImage(256, 256, \Imagick::FILTER_LANCZOS, 1, true);
            $imagick->setColorspace(\Imagick::COLORSPACE_GRAY);
            $kernel = \ImagickKernel::fromBuiltIn(\Imagick::KERNEL_LAPLACIAN, '0');
            $imagick->filter($kernel);
            $stats = $imagick->getImageChannelStatistics();
            $imagick->destroy();

            return $stats[\Imagick::CHANNEL_GRAY]['standardDeviation'] ** 2;
        } catch (\Throwable $e) {
            $this->logger->debug('Reel: sharpness measurement failed for file {id}: {msg}', [
                'id'  => $fileId,
                'msg' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    private function hammingDistance(string $a, string $b): int {
        $len      = min(strlen($a), strlen($b));
        $distance = abs(strlen($a) - strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) $distance++;
        }
        return $distance;
    }

    // -------------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------------

    private function burstGapSeconds(string $userId): int {
        return (int)$this->config->getUserValue(
            $userId, Application::APP_ID, 'burst_gap_seconds',
            (string)self::DEFAULT_BURST_GAP_SECONDS
        );
    }

    private function similarityThreshold(string $userId): int {
        return (int)$this->config->getUserValue(
            $userId, Application::APP_ID, 'similarity_threshold',
            (string)self::DEFAULT_SIMILARITY_THRESHOLD
        );
    }

    // -------------------------------------------------------------------------
    // Data access
    // -------------------------------------------------------------------------

    private function loadPhotos(int $eventId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        // Use mem.epoch (integer Unix timestamp) instead of UNIX_TIMESTAMP(mem.datetaken)
        // which is MySQL-specific. Memories stores the epoch as a plain integer column.
        $qb->select('m.file_id', 'fc.name')
            ->selectAlias('mem.epoch', 'datetaken')
            ->from('reel_event_media', 'm')
            ->join('m', 'memories', 'mem', $qb->expr()->eq('m.file_id', 'mem.fileid'))
            ->join('m', 'filecache', 'fc',  $qb->expr()->eq('m.file_id', 'fc.fileid'))
            ->where($qb->expr()->eq('m.event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('m.user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.included',  $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mem.isvideo', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        return $qb->executeQuery()->fetchAll();
    }

    private function loadBlurhashes(array $fileIds): array {
        $qb = $this->db->getQueryBuilder();
        // Select entire json column and parse in PHP to avoid MySQL-specific
        // JSON_UNQUOTE / JSON_EXTRACT functions (not available on PostgreSQL).
        $qb->select('file_id', 'json')
            ->from('files_metadata')
            ->where($qb->expr()->in(
                'file_id',
                $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $data = json_decode($row['json'] ?? '{}', true);
            $blurhash = $data['blurhash']['value'] ?? null;
            if (!empty($blurhash)) {
                $result[(int)$row['file_id']] = $blurhash;
            }
        }
        return $result;
    }

    private function markExcluded(int $eventId, string $userId, array $fileIds): void {
        foreach ($fileIds as $fileId) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('reel_event_media')
                ->set('included', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id',  $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id',  $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
    }
}
