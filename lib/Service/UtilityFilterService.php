<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\Files\IRootFolder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * UtilityFilterService (Tier A)
 *
 * Conservatively excludes "utility" shots (receipts, menus, screen/text docs)
 * before duplicate/distinct filtering.
 */
class UtilityFilterService {

    private const MIN_APPLY_ITEMS = 8;
    private const ANCHOR_GAP_SECONDS = 3 * 3600;

    private const WEAK_UTILITY_KEYWORDS = [
        'document', 'text', 'screen', 'display', 'screenshot', 'note',
    ];

    private const STRONG_UTILITY_KEYWORDS = [
        'receipt', 'invoice', 'bill', 'menu', 'qr', 'barcode', 'serial', 'code',
    ];

    private const FILENAME_HINTS = [
        'receipt', 'invoice', 'bill', 'menu', 'qr', 'barcode', 'code', 'serial', 'note',
    ];

    private const OCR_WORD_THRESHOLD = 20;
    private const OCR_MIN_CONFIDENCE = 60;
    private const EXPOSURE_MEAN_LOW = 0.08;
    private const EXPOSURE_MEAN_HIGH = 0.92;
    private const EXPOSURE_STDDEV_MAX = 0.06;

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {}

    public function filterEvent(int $eventId, string $userId, ?callable $onDebug = null): int {
        $items = $this->loadIncludedMedia($eventId, $userId);
        if (count($items) < self::MIN_APPLY_ITEMS) {
            $this->emitDebug($onDebug, sprintf(
                'utility event=%d skipped before=%d reason=min_apply<%d',
                $eventId,
                count($items),
                self::MIN_APPLY_ITEMS
            ));
            return 0;
        }

        usort($items, static fn(array $a, array $b): int => ((int)$a['datetaken'] <=> (int)$b['datetaken']));

        $protected = $this->buildProtectedSet($items);
        $fileIds = array_map(static fn(array $item): int => (int)$item['file_id'], $items);
        $tagsByFile = $this->loadTagsForFiles($fileIds);
        $faceScores = $this->loadFaceScores($fileIds, $userId);

        $toExclude = [];
        $reasons = [];

        foreach ($items as $item) {
            $fileId = (int)$item['file_id'];
            $name = strtolower((string)($item['name'] ?? ''));
            $isVideo = !empty($item['isvideo']);

            if ($isVideo || isset($protected[$fileId])) {
                continue;
            }

            if (($faceScores[$fileId] ?? 0.0) > 0.0) {
                continue;
            }

            $tags = array_map(static fn(string $t): string => strtolower($t), $tagsByFile[$fileId] ?? []);
            if (empty($tags)) {
                continue;
            }

            $weakHits = $this->countKeywordHits($tags, self::WEAK_UTILITY_KEYWORDS);
            $strongHits = $this->countKeywordHits($tags, self::STRONG_UTILITY_KEYWORDS);
            $nameHits = $this->countSubstringHits($name, self::FILENAME_HINTS);
            $localPath = $this->resolveLocalPath($userId, $fileId);

            $drop = false;
            if ($strongHits >= 1) {
                $drop = true;
            } elseif ($weakHits >= 2) {
                $drop = true;
            } elseif ($weakHits >= 1 && $nameHits >= 1) {
                $drop = true;
            }

            $ocrWords = 0;
            // Run OCR mainly when tags/name suggest utility content but signal is borderline.
            if (!$drop && $localPath !== null && ($strongHits >= 1 || $weakHits >= 1 || $nameHits >= 1)) {
                $ocrWords = $this->countConfidentWords($localPath, self::OCR_MIN_CONFIDENCE);
                if ($ocrWords >= self::OCR_WORD_THRESHOLD) {
                    $drop = true;
                }
            }

            $exposure = null;
            if (!$drop && $localPath !== null) {
                $exposure = $this->analyzeExposure($localPath);
                if (!empty($exposure['problematic'])) {
                    $drop = true;
                }
            }

            if (!$drop) {
                continue;
            }

            $toExclude[] = $fileId;
            $reasons[$fileId] = sprintf(
                'weak=%d strong=%d name=%d ocr=%d exp=%s',
                $weakHits,
                $strongHits,
                $nameHits,
                $ocrWords,
                (!empty($exposure['problematic']) ? 'problematic' : 'ok')
            );
        }

        if (empty($toExclude)) {
            $this->emitDebug($onDebug, sprintf('utility event=%d excluded=0', $eventId));
            return 0;
        }

        $this->markExcluded($eventId, $userId, $toExclude);

        $this->logger->debug('Reel: utility prefilter excluded {n} media from event {id}', [
            'n' => count($toExclude),
            'id' => $eventId,
        ]);

        $this->emitDebug($onDebug, sprintf(
            'utility event=%d before=%d excluded=%d protected=%d',
            $eventId,
            count($items),
            count($toExclude),
            count($protected)
        ));

        $shown = 0;
        $excludedSet = array_fill_keys($toExclude, true);
        foreach ($items as $item) {
            $fid = (int)$item['file_id'];
            if (!isset($excludedSet[$fid])) {
                continue;
            }
            $this->emitDebug($onDebug, sprintf(
                'utility drop file_id=%d ts=%d name="%s" reason=%s',
                $fid,
                (int)$item['datetaken'],
                (string)($item['name'] ?? ''),
                (string)($reasons[$fid] ?? 'n/a')
            ));
            $shown++;
            if ($shown >= 12) {
                break;
            }
        }

        return count($toExclude);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, bool>
     */
    private function buildProtectedSet(array $items): array {
        $set = [];
        if (empty($items)) {
            return $set;
        }

        $set[(int)$items[0]['file_id']] = true;
        $set[(int)$items[count($items) - 1]['file_id']] = true;

        for ($i = 1, $n = count($items); $i < $n; $i++) {
            $prev = (int)$items[$i - 1]['datetaken'];
            $curr = (int)$items[$i]['datetaken'];
            if (($curr - $prev) >= self::ANCHOR_GAP_SECONDS) {
                $set[(int)$items[$i]['file_id']] = true;
            }
        }

        return $set;
    }

    /** @param array<int, string> $tags */
    private function countKeywordHits(array $tags, array $keywords): int {
        $hits = 0;
        foreach ($tags as $tag) {
            foreach ($keywords as $keyword) {
                if (str_contains($tag, $keyword)) {
                    $hits++;
                    break;
                }
            }
        }
        return $hits;
    }

    /** @param array<int, string> $needles */
    private function countSubstringHits(string $haystack, array $needles): int {
        $hits = 0;
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                $hits++;
            }
        }
        return $hits;
    }

    private function emitDebug(?callable $onDebug, string $line): void {
        if ($onDebug !== null) {
            $onDebug($line);
        }
    }

    private function resolveLocalPath(string $userId, int $fileId): ?string {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            if (empty($nodes)) {
                return null;
            }

            $node = $nodes[0];
            $storage = $node->getStorage();
            if (!$storage->isLocal()) {
                return null;
            }

            $localPath = $storage->getLocalFile($node->getInternalPath());
            if ($localPath === null || !file_exists($localPath)) {
                return null;
            }

            return $localPath;
        } catch (\Throwable) {
            return null;
        }
    }

    private function countConfidentWords(string $imagePath, int $minConfidence = self::OCR_MIN_CONFIDENCE): int {
        $ocrPath = $imagePath;
        $tempPath = null;

        // Tesseract reliability for HEIC/AVIF varies; convert first frame to JPEG when possible.
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['heic', 'heif', 'avif'], true) && class_exists(\Imagick::class)) {
            try {
                $tempPath = tempnam(sys_get_temp_dir(), 'reel_ocr_');
                if ($tempPath !== false) {
                    $jpgPath = $tempPath . '.jpg';
                    @unlink($tempPath);
                    $img = new \Imagick();
                    $img->readImage($imagePath . '[0]');
                    $img->setImageFormat('jpeg');
                    $img->setImageCompressionQuality(90);
                    $img->writeImage($jpgPath);
                    $img->clear();
                    $img->destroy();
                    $ocrPath = $jpgPath;
                    $tempPath = $jpgPath;
                }
            } catch (\Throwable) {
                $ocrPath = $imagePath;
                $tempPath = null;
            }
        }

        try {
            $escaped = escapeshellarg($ocrPath);
            $cmd = "tesseract {$escaped} stdout --psm 1 tsv 2>/dev/null";
            $tsv = shell_exec($cmd);

            if (!is_string($tsv) || trim($tsv) === '') {
                return 0;
            }

            $count = 0;
            $lines = explode("\n", trim($tsv));
            array_shift($lines); // header

            foreach ($lines as $line) {
                $fields = explode("\t", $line);
                if (count($fields) < 12) {
                    continue;
                }

                $conf = (int)$fields[10];
                $text = trim((string)$fields[11]);
                if ($text !== '' && $conf >= $minConfidence) {
                    $count++;
                }
            }

            return $count;
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /** @return array{mean:float,stddev:float,underexposed:bool,overexposed:bool,problematic:bool}|null */
    private function analyzeExposure(string $localPath): ?array {
        if (!class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $img = new \Imagick();
            $img->readImage($localPath . '[0]');
            $img->thumbnailImage(256, 256, true);
            $img->setImageColorspace(\Imagick::COLORSPACE_GRAY);

            $stats = $img->getImageChannelStatistics();
            $img->clear();
            $img->destroy();

            $quantum = 65535.0;
            $gray = $stats[\Imagick::CHANNEL_GRAY] ?? [];
            $mean = (float)($gray['mean'] ?? 0.0) / max(1.0, $quantum);
            $stddev = (float)($gray['standardDeviation'] ?? 0.0) / max(1.0, $quantum);

            $isUnderexposed = $mean < self::EXPOSURE_MEAN_LOW && $stddev < self::EXPOSURE_STDDEV_MAX;
            $isOverexposed = $mean > self::EXPOSURE_MEAN_HIGH && $stddev < self::EXPOSURE_STDDEV_MAX;

            return [
                'mean' => $mean,
                'stddev' => $stddev,
                'underexposed' => $isUnderexposed,
                'overexposed' => $isOverexposed,
                'problematic' => $isUnderexposed || $isOverexposed,
            ];
        } catch (\Throwable) {
            return null;
        }
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

    /** @param array<int> $fileIds @return array<int, float> */
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
