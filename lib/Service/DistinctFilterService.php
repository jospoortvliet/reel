<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * DistinctFilterService
 *
 * Reduces event size by excluding visually similar, temporally close items,
 * while preserving anchors and semantically interesting media.
 */
class DistinctFilterService {

    private const MIN_APPLY_ITEMS = 10;
    private const MIN_TARGET_ITEMS = 10;
    private const MAX_TARGET_ITEMS = 65;

    private const DYNAMIC_NEAR_THRESHOLD = 10.0;
    private const DYNAMIC_FAR_THRESHOLD = 24.0;
    private const DYNAMIC_TIME_TAU_SECONDS = 2.5 * 3600.0;

    private const SOFT_ANCHOR_GAP_SECONDS = 3 * 3600;
    private const MIN_VIDEO_KEEP_FRACTION = 0.40;
    private const RECENT_COMPARE_WINDOW_SECONDS = 2 * 3600;
    private const RECENT_COMPARE_MAX_ITEMS = 4;

    private const TAG_BLOCKLIST = [
        'Tagged by recognize',
        'People', 'Furniture', 'Landscape', 'Nature', 'Indoor', 'Outdoor',
        'Building', 'Info', 'Sand', 'Water', 'Display', 'Screen', 'Living',
        'Electronics', 'Vehicle', 'Document', 'Architecture', 'Object',
        'Structure', 'Transport', 'Text', 'Art', 'Still Life', 'Technology',
    ];

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * Applies distinct filtering to included media in an event.
     * Returns number of media rows marked excluded.
     */
    public function filterEvent(int $eventId, string $userId, ?callable $onDebug = null): int {
        $items = $this->loadIncludedMedia($eventId, $userId);
        $count = count($items);
        if ($count <= self::MIN_APPLY_ITEMS) {
            $this->emitDebug($onDebug, sprintf(
                'distinct event=%d skipped before=%d reason=min_apply<=%d',
                $eventId,
                $count,
                self::MIN_APPLY_ITEMS
            ));
            return 0;
        }

        usort($items, static fn(array $a, array $b): int => ((int)$a['datetaken'] <=> (int)$b['datetaken']));

        $target = $this->targetCount($count);
        if ($target >= $count) {
            $this->emitDebug($onDebug, sprintf(
                'distinct event=%d skipped before=%d target=%d reason=target>=before',
                $eventId,
                $count,
                $target
            ));
            return 0;
        }

        $fileIds = array_map(static fn(array $i): int => (int)$i['file_id'], $items);
        $blurhashes = $this->loadBlurhashes($fileIds);
        $tagsByFile = $this->loadTagsForFiles($fileIds);
        $faceScores = $this->loadFaceScores($fileIds, $userId);

        $hardKeep = $this->buildHardKeepSet($items, $tagsByFile);
        $softKeep = $this->buildSoftKeepSet($items);

        $best = null;
        $bestAlpha = 1.0;
        $low = 0.45;
        $high = 3.20;

        for ($i = 0; $i < 12; $i++) {
            $mid = ($low + $high) / 2.0;
            $candidate = $this->simulateKeepSet($items, $blurhashes, $hardKeep, $softKeep, $faceScores, $mid);

            if ($best === null || abs(count($candidate) - $target) < abs(count($best) - $target)) {
                $best = $candidate;
                $bestAlpha = $mid;
            }

            if (count($candidate) > $target) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        if ($best === null) {
            return 0;
        }

        $best = $this->enforceVideoFloor($items, $best);
        $best = $this->trimTowardsTarget($items, $best, $hardKeep, $target);

        $keepSet = array_fill_keys($best, true);
        $excluded = [];
        foreach ($items as $item) {
            $fileId = (int)$item['file_id'];
            if (!isset($keepSet[$fileId])) {
                $excluded[] = $fileId;
            }
        }

        if (empty($excluded)) {
            return 0;
        }

        $this->markExcluded($eventId, $userId, $excluded);

        $this->logger->debug('Reel: distinct filter event {id}: before={before}, target={target}, after={after}, excluded={excluded}, alpha={alpha}', [
            'id' => $eventId,
            'before' => $count,
            'target' => $target,
            'after' => $count - count($excluded),
            'excluded' => count($excluded),
            'alpha' => round($bestAlpha, 4),
        ]);

        $missingHashes = 0;
        foreach ($items as $item) {
            if (!isset($blurhashes[(int)$item['file_id']])) {
                $missingHashes++;
            }
        }

        $this->emitDebug($onDebug, sprintf(
            'distinct event=%d before=%d target=%d after=%d excluded=%d alpha=%.4f hard_keep=%d soft_keep=%d missing_hash=%d',
            $eventId,
            $count,
            $target,
            $count - count($excluded),
            count($excluded),
            $bestAlpha,
            count($hardKeep),
            count($softKeep),
            $missingHashes,
        ));

        $this->emitDroppedSampleDebug($onDebug, $items, $excluded);

        return count($excluded);
    }

    private function targetCount(int $n): int {
        if ($n <= self::MIN_TARGET_ITEMS) {
            return $n;
        }

        // Stronger log-shaped compression:
        // ~26/30, ~41/64, ~57/120, ~65/170+.
        $x = max(1.0, (float)($n - self::MIN_TARGET_ITEMS));
        $ln = log(1.0 + ($x / 10.0));
        $compressed = self::MIN_TARGET_ITEMS + ($x / (1.0 + 0.22 * $ln * $ln));

        return (int)max(self::MIN_TARGET_ITEMS, min(self::MAX_TARGET_ITEMS, round($compressed)));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $blurhashes
     * @param array<int, bool> $hardKeep
     * @param array<int, bool> $softKeep
     * @param array<int, float> $faceScores
     * @return array<int, int>
     */
    private function simulateKeepSet(array $items, array $blurhashes, array $hardKeep, array $softKeep, array $faceScores, float $alpha): array {
        $kept = [];
        $keptMeta = [];

        foreach ($items as $item) {
            $fileId = (int)$item['file_id'];
            $epoch = (int)$item['datetaken'];
            $isVideo = !empty($item['isvideo']);

            if (isset($hardKeep[$fileId])) {
                $kept[] = $fileId;
                $keptMeta[] = [
                    'file_id' => $fileId,
                    'datetaken' => $epoch,
                    'isvideo' => $isVideo,
                    'face_score' => (float)($faceScores[$fileId] ?? 0.0),
                ];
                continue;
            }

            if (empty($keptMeta)) {
                $kept[] = $fileId;
                $keptMeta[] = [
                    'file_id' => $fileId,
                    'datetaken' => $epoch,
                    'isvideo' => $isVideo,
                    'face_score' => (float)($faceScores[$fileId] ?? 0.0),
                ];
                continue;
            }

            $referenceDistance = null;
            $referenceThreshold = null;
            $currHash = $blurhashes[$fileId] ?? null;

            $slice = count($keptMeta) > self::RECENT_COMPARE_MAX_ITEMS
                ? array_slice($keptMeta, -self::RECENT_COMPARE_MAX_ITEMS)
                : $keptMeta;

            $referenceFileId = null;
            foreach (array_reverse($slice) as $m) {
                $dt = max(0, $epoch - (int)$m['datetaken']);
                if ($dt > self::RECENT_COMPARE_WINDOW_SECONDS) {
                    continue;
                }

                $dynamicThreshold = $this->dynamicThreshold($dt, $alpha);

                if (isset($softKeep[$fileId])) {
                    // Soft anchors should survive slightly more often, but not dominate.
                    $dynamicThreshold *= 0.90;
                }
                if ($isVideo) {
                    $dynamicThreshold *= 0.78;
                }

                $prevHash = $blurhashes[(int)$m['file_id']] ?? null;
                if ($currHash === null || $prevHash === null) {
                    continue;
                }

                $distance = $this->hammingDistance($prevHash, $currHash);
                if ($referenceDistance === null || $distance < $referenceDistance) {
                    $referenceDistance = $distance;
                    $referenceThreshold = $dynamicThreshold;
                    $referenceFileId = (int)$m['file_id'];
                }
            }

            if ($referenceDistance === null || $referenceThreshold === null) {
                $kept[] = $fileId;
                $keptMeta[] = [
                    'file_id' => $fileId,
                    'datetaken' => $epoch,
                    'isvideo' => $isVideo,
                    'face_score' => (float)($faceScores[$fileId] ?? 0.0),
                ];
                continue;
            }

            if ($referenceDistance <= $referenceThreshold) {
                $currFaceScore = (float)($faceScores[$fileId] ?? 0.0);

                // Slight bias toward stronger face composition when visuals are similar.
                if ($referenceFileId !== null && !isset($hardKeep[$referenceFileId])) {
                    $refFaceScore = 0.0;
                    foreach ($keptMeta as $meta) {
                        if ((int)$meta['file_id'] === $referenceFileId) {
                            $refFaceScore = (float)($meta['face_score'] ?? 0.0);
                            break;
                        }
                    }

                    $preferFaceCandidate = ($currFaceScore > 0.0 && $refFaceScore <= 0.0)
                        || ($currFaceScore > ($refFaceScore * 1.18));

                    if ($preferFaceCandidate) {
                        $kept = array_values(array_filter(
                            $kept,
                            static fn(int $fid): bool => $fid !== $referenceFileId
                        ));
                        $keptMeta = array_values(array_filter(
                            $keptMeta,
                            static fn(array $meta): bool => (int)$meta['file_id'] !== $referenceFileId
                        ));

                        $kept[] = $fileId;
                        $keptMeta[] = [
                            'file_id' => $fileId,
                            'datetaken' => $epoch,
                            'isvideo' => $isVideo,
                            'face_score' => $currFaceScore,
                        ];
                        continue;
                    }
                }

                // Prefer keeping a clip over a near-identical still when possible.
                if ($isVideo && $referenceFileId !== null && !isset($hardKeep[$referenceFileId])) {
                    $refIsVideo = false;
                    foreach ($keptMeta as $meta) {
                        if ((int)$meta['file_id'] === $referenceFileId) {
                            $refIsVideo = !empty($meta['isvideo']);
                            break;
                        }
                    }

                    if (!$refIsVideo) {
                        $kept = array_values(array_filter(
                            $kept,
                            static fn(int $fid): bool => $fid !== $referenceFileId
                        ));
                        $keptMeta = array_values(array_filter(
                            $keptMeta,
                            static fn(array $meta): bool => (int)$meta['file_id'] !== $referenceFileId
                        ));

                        $kept[] = $fileId;
                        $keptMeta[] = [
                            'file_id' => $fileId,
                            'datetaken' => $epoch,
                            'isvideo' => $isVideo,
                            'face_score' => $currFaceScore,
                        ];
                    }
                }
                continue;
            }

            $kept[] = $fileId;
            $keptMeta[] = [
                'file_id' => $fileId,
                'datetaken' => $epoch,
                'isvideo' => $isVideo,
                'face_score' => (float)($faceScores[$fileId] ?? 0.0),
            ];
        }

        return array_values(array_unique($kept));
    }

    private function dynamicThreshold(int $dtSeconds, float $alpha): float {
        $base = self::DYNAMIC_NEAR_THRESHOLD
            + (self::DYNAMIC_FAR_THRESHOLD - self::DYNAMIC_NEAR_THRESHOLD)
            * (1.0 - exp(-($dtSeconds / self::DYNAMIC_TIME_TAU_SECONDS)));

        return max(1.0, $base * $alpha);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<int, string>> $tagsByFile
     * @return array<int, bool>
     */
    private function buildHardKeepSet(array $items, array $tagsByFile): array {
        $keep = [];
        if (empty($items)) {
            return $keep;
        }

        $firstId = (int)$items[0]['file_id'];
        $lastId = (int)$items[count($items) - 1]['file_id'];
        $keep[$firstId] = true;
        $keep[$lastId] = true;

        $n = count($items);
        $tagCounts = [];
        foreach ($items as $item) {
            $fileId = (int)$item['file_id'];
            foreach ($tagsByFile[$fileId] ?? [] as $tag) {
                $tag = (string)$tag;
                if ($this->isTagBlocked($tag)) {
                    continue;
                }
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }

        $rareCutoff = max(2, (int)ceil($n * 0.03));

        foreach ($items as $item) {
            $fileId = (int)$item['file_id'];
            foreach ($tagsByFile[$fileId] ?? [] as $tag) {
                $tag = (string)$tag;
                if ($this->isTagBlocked($tag)) {
                    continue;
                }

                if (($tagCounts[$tag] ?? 0) <= $rareCutoff) {
                    $keep[$fileId] = true;
                    break;
                }
            }
        }

        return $keep;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, bool>
     */
    private function buildSoftKeepSet(array $items): array {
        $keep = [];
        for ($i = 1, $n = count($items); $i < $n; $i++) {
            $prev = (int)$items[$i - 1]['datetaken'];
            $curr = (int)$items[$i]['datetaken'];
            if (($curr - $prev) >= self::SOFT_ANCHOR_GAP_SECONDS) {
                $keep[(int)$items[$i]['file_id']] = true;
            }
        }
        return $keep;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int> $keepIds
     * @return array<int, int>
     */
    private function enforceVideoFloor(array $items, array $keepIds): array {
        $totalVideos = 0;
        $videoById = [];
        foreach ($items as $item) {
            $fileId = (int)$item['file_id'];
            $isVideo = !empty($item['isvideo']);
            $videoById[$fileId] = $isVideo;
            if ($isVideo) {
                $totalVideos++;
            }
        }

        if ($totalVideos === 0) {
            return $keepIds;
        }

        $minVideoKeep = max(1, (int)ceil($totalVideos * self::MIN_VIDEO_KEEP_FRACTION));
        $keepSet = array_fill_keys($keepIds, true);
        $keptVideos = 0;
        foreach ($keepIds as $fid) {
            if (!empty($videoById[$fid])) {
                $keptVideos++;
            }
        }

        if ($keptVideos >= $minVideoKeep) {
            return $keepIds;
        }

        foreach ($items as $item) {
            $fid = (int)$item['file_id'];
            if (empty($videoById[$fid]) || isset($keepSet[$fid])) {
                continue;
            }
            $keepSet[$fid] = true;
            $keepIds[] = $fid;
            $keptVideos++;
            if ($keptVideos >= $minVideoKeep) {
                break;
            }
        }

        return array_values(array_unique($keepIds));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int> $keepIds
     * @param array<int, bool> $hardKeep
     * @return array<int, int>
     */
    private function trimTowardsTarget(array $items, array $keepIds, array $hardKeep, int $target): array {
        if (count($keepIds) <= $target) {
            return $keepIds;
        }

        $orderedKeep = [];
        $keepSet = array_fill_keys($keepIds, true);
        foreach ($items as $item) {
            $fid = (int)$item['file_id'];
            if (isset($keepSet[$fid])) {
                $orderedKeep[] = $fid;
            }
        }

        $canDrop = [];
        foreach ($orderedKeep as $fid) {
            if (!isset($hardKeep[$fid])) {
                $canDrop[] = $fid;
            }
        }

        $toDrop = min(count($orderedKeep) - $target, count($canDrop));
        if ($toDrop <= 0) {
            return $orderedKeep;
        }

        // Drop every k-th removable item to preserve temporal coverage.
        $dropSet = [];
        $step = max(1.0, count($canDrop) / max(1, $toDrop));
        for ($i = 0; $i < $toDrop; $i++) {
            $idx = (int)floor($i * $step);
            $idx = min($idx, count($canDrop) - 1);
            $dropSet[$canDrop[$idx]] = true;
        }

        return array_values(array_filter($orderedKeep, static fn(int $fid): bool => !isset($dropSet[$fid])));
    }

    private function isTagBlocked(string $tag): bool {
        $lower = strtolower($tag);
        foreach (self::TAG_BLOCKLIST as $blocked) {
            if (str_starts_with($lower, strtolower($blocked))) {
                return true;
            }
        }
        return false;
    }

    private function emitDebug(?callable $onDebug, string $line): void {
        if ($onDebug !== null) {
            $onDebug($line);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int> $excluded
     */
    private function emitDroppedSampleDebug(?callable $onDebug, array $items, array $excluded): void {
        if ($onDebug === null || empty($excluded)) {
            return;
        }

        $excludedSet = array_fill_keys($excluded, true);
        $shown = 0;
        foreach ($items as $item) {
            $fid = (int)$item['file_id'];
            if (!isset($excludedSet[$fid])) {
                continue;
            }

            $onDebug(sprintf(
                'distinct drop file_id=%d ts=%d name="%s" isvideo=%s',
                $fid,
                (int)$item['datetaken'],
                (string)($item['name'] ?? ''),
                !empty($item['isvideo']) ? 'true' : 'false'
            ));

            $shown++;
            if ($shown >= 12) {
                break;
            }
        }
    }

    private function hammingDistance(string $a, string $b): int {
        $len = min(strlen($a), strlen($b));
        $distance = abs(strlen($a) - strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                $distance++;
            }
        }
        return $distance;
    }

    /**
     * @param array<int> $fileIds
     * @return array<int, float>
     */
    private function loadFaceScores(array $fileIds, string $userId): array {
        if (empty($fileIds)) {
            return [];
        }

        $scores = array_fill_keys($fileIds, 0.0);

        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'x', 'y', 'width', 'height')
            ->from('recognize_face_detections')
            ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $fileId = (int)$row['file_id'];
            $cx = (float)$row['x'] + (float)$row['width'] / 2.0;
            $cy = (float)$row['y'] + (float)$row['height'] / 2.0;
            $size = (float)$row['width'] * (float)$row['height'];
            $centrality = 1.0 - 2.0 * sqrt(($cx - 0.5) ** 2 + ($cy - 0.5) ** 2);

            $scores[$fileId] = (float)($scores[$fileId] ?? 0.0) + $size * max(0.0, $centrality);
        }

        return $scores;
    }

    /**
     * @return array<int, array{file_id:int, datetaken:int, isvideo:int, name:string}>
     */
    private function loadIncludedMedia(int $eventId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.file_id', 'fc.name')
            ->selectAlias('mem.epoch', 'datetaken')
            ->selectAlias('mem.isvideo', 'isvideo')
            ->from('reel_event_media', 'm')
            ->join('m', 'memories', 'mem', $qb->expr()->eq('m.file_id', 'mem.fileid'))
            ->leftJoin('m', 'filecache', 'fc', $qb->expr()->eq('m.file_id', 'fc.fileid'))
            ->where($qb->expr()->eq('m.event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.included', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        return array_map(static function (array $row): array {
            return [
                'file_id' => (int)$row['file_id'],
                'datetaken' => (int)$row['datetaken'],
                'isvideo' => (int)$row['isvideo'],
                'name' => (string)($row['name'] ?? ''),
            ];
        }, $qb->executeQuery()->fetchAll());
    }

    /** @param array<int> $fileIds @return array<int, string> */
    private function loadBlurhashes(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'json')
            ->from('files_metadata')
            ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $data = json_decode($row['json'] ?? '{}', true);
            $blurhash = $data['blurhash']['value'] ?? null;
            if (is_string($blurhash) && $blurhash !== '') {
                $result[(int)$row['file_id']] = $blurhash;
            }
        }
        return $result;
    }

    /** @param array<int> $fileIds @return array<int, array<int, string>> */
    private function loadTagsForFiles(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $objectIds = array_map('strval', $chunk);

            $qb->select('stm.objectid', 'st.name')
                ->from('systemtag_object_mapping', 'stm')
                ->innerJoin('stm', 'systemtag', 'st', $qb->expr()->eq('stm.systemtagid', 'st.id'))
                ->where($qb->expr()->eq('stm.objecttype', $qb->createNamedParameter('files')))
                ->andWhere($qb->expr()->in('stm.objectid', $qb->createNamedParameter($objectIds, IQueryBuilder::PARAM_STR_ARRAY)));

            $rows = array_merge($rows, $qb->executeQuery()->fetchAll());
        }

        $out = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['objectid'];
            $tag = trim((string)($row['name'] ?? ''));
            if ($tag === '') {
                continue;
            }
            $out[$fileId][] = $tag;
        }
        return $out;
    }

    /** @param array<int> $fileIds */
    private function markExcluded(int $eventId, string $userId, array $fileIds): void {
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('reel_event_media')
                ->set('included', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }
}
