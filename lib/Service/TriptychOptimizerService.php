<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * TriptychOptimizerService (Tier A - runs after duplicate/distinct filtering)
 *
 * After duplicate and distinct filtering, looks for deselected media items
 * that could be re-enabled to create valid triptych segments in the render output.
 *
 * A triptych requires:
 *   1. Three consecutive photos (no videos)
 *   2. All with compatible orientation for the output format:
 *      - Portrait output (9:16): all items must be landscape (w > h)
 *      - Landscape output (16:9): all items must be portrait (w < h)
 *   3. Ideally up to 1 live photo can be converted to static for a triptych
 *
 * Algorithm (per event):
 *   1. Load all media (included + excluded) with dimensions
 *   2. For each excluded item:
 *      a. Check if it has triptych-compatible orientation
 *      b. Try 4 progressive strategies to find 2 companion items:
 *         - Previous 2 enabled + current
 *         - Previous 1 + current + next 1
 *         - Current + next 2 enabled
 *         - Smart re-enable: up to 2 additional excluded items to form triptych
 *      c. If valid combo found, mark all for re-enable
 *   3. Apply all re-enables to DB
 *
 * Returns: number of media items re-enabled
 */
class TriptychOptimizerService {

    private const MIN_EVENT_SIZE = 8;
    private const LOOKAHEAD_DISTANCE = 5;

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * Optimizes media selection in an event to enable triptych segments.
     * Returns number of media items re-enabled.
     */
    public function optimizeEvent(int $eventId, string $userId, ?callable $onDebug = null): int {
        $allMedia = $this->loadAllMedia($eventId, $userId);
        
        if (count($allMedia) < self::MIN_EVENT_SIZE) {
            $this->emitDebug($onDebug, sprintf(
                'triptych_opt event=%d skipped reason=too_small size=%d',
                $eventId,
                count($allMedia)
            ));
            return 0;
        }

        // Separate into included and excluded
        $included = array_filter($allMedia, fn(array $item) => $item['included'] === 1);
        $excluded = array_filter($allMedia, fn(array $item) => $item['included'] === 0);

        if (count($included) < 3 || empty($excluded)) {
            $this->emitDebug($onDebug, sprintf(
                'triptych_opt event=%d skipped reason=insufficient_media included=%d excluded=%d',
                $eventId,
                count($included),
                count($excluded)
            ));
            return 0;
        }

        // Build position maps for quick index lookups
        $includedList = array_values($included);
        usort($includedList, fn(array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);
        
        $allList = [];
        foreach ($allMedia as $item) {
            $allList[] = $item;
        }
        usort($allList, fn(array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        // Assume portrait output by default (most phone videos are portrait)
        $outputOrientation = 'portrait'; // Could be made configurable

        // Collect re-enables
        $reEnable = [];

        foreach ($excluded as $excludedItem) {
            if (isset($reEnable[$excludedItem['file_id']])) {
                // Already marked for re-enable
                continue;
            }

            if (!$this->hasTriptychOrientation($excludedItem, $outputOrientation)) {
                continue;
            }

            $position = $this->findPositionInSequence($excludedItem['sort_order'], $allList);
            if ($position === null) {
                continue;
            }

            $triptych = $this->findTriptychCombo($excludedItem, $position, $allList, $includedList, $outputOrientation);
            
            if ($triptych !== null) {
                // Mark all 3 items for re-enable
                foreach ($triptych as $fileId) {
                    $reEnable[$fileId] = true;
                }

                $this->emitDebug($onDebug, sprintf(
                    'triptych_opt: promoted files %s (positions %s)',
                    implode(',', array_map('strval', $triptych)),
                    implode(',', array_map(fn($fid) => (string)$this->findPositionInSequence(
                        $this->findSortOrderByFileId($fid, $allList),
                        $allList
                    ), $triptych))
                ));
            }
        }

        if (empty($reEnable)) {
            $this->emitDebug($onDebug, sprintf(
                'triptych_opt event=%d no_triptychs_found',
                $eventId
            ));
            return 0;
        }

        // Apply re-enables to DB
        $count = $this->markIncluded($eventId, $userId, array_keys($reEnable));

        $this->logger->info('Reel: triptych optimizer re-enabled {n} items in event {id}', [
            'n' => $count,
            'id' => $eventId,
        ]);

        $this->emitDebug($onDebug, sprintf(
            'triptych_opt event=%d re_enabled=%d',
            $eventId,
            $count
        ));

        return $count;
    }

    /**
     * Checks if an item has the orientation needed for the output format.
     * Portrait output (9:16) needs landscape photos (w > h).
     * Landscape output (16:9) needs portrait photos (w < h).
     */
    private function hasTriptychOrientation(array $item, string $outputOrientation): bool {
        if (!empty($item['isvideo'])) {
            return false; // No videos in triptychs
        }

        $w = (int)($item['w'] ?? 0);
        $h = (int)($item['h'] ?? 0);

        if ($w <= 0 || $h <= 0) {
            return false; // Missing dimensions
        }

        if ($outputOrientation === 'portrait') {
            // Portrait output needs landscape photos for 3-column layout
            return $w > $h;
        } else {
            // Landscape output needs portrait photos for 3-row layout
            return $w < $h;
        }
    }

    /**
     * Finds the sequence position of a sort_order value in the allList.
     */
    private function findPositionInSequence(int $sortOrder, array $allList): ?int {
        foreach ($allList as $pos => $item) {
            if ($item['sort_order'] === $sortOrder) {
                return $pos;
            }
        }
        return null;
    }

    /**
     * Finds sort_order for a given file_id.
     */
    private function findSortOrderByFileId(int $fileId, array $allList): int {
        foreach ($allList as $item) {
            if ($item['file_id'] === $fileId) {
                return $item['sort_order'];
            }
        }
        return 0;
    }

    /**
     * Attempts to find a valid triptych combo for an excluded item.
     * Returns [file_id_1, file_id_2, file_id_3] if found, null otherwise.
     *
     * @return array<int>|null
     */
    private function findTriptychCombo(
        array $excluded,
        int $position,
        array $allList,
        array $includedList,
        string $outputOrientation
    ): ?array {
        // Strategy A: Previous 2 + current
        $combo = $this->strategyPreviousTwo($excluded, $position, $allList, $outputOrientation);
        if ($combo !== null) {
            return $combo;
        }

        // Strategy B: Previous 1 + current + next 1
        $combo = $this->strategyPreviousOneNextOne($excluded, $position, $allList, $outputOrientation);
        if ($combo !== null) {
            return $combo;
        }

        // Strategy C: Current + next 2
        $combo = $this->strategyNextTwo($excluded, $position, $allList, $outputOrientation);
        if ($combo !== null) {
            return $combo;
        }

        // Strategy D: Smart re-enable (up to 2 more excluded items)
        $combo = $this->strategySmartReEnable($excluded, $position, $allList, $outputOrientation);
        if ($combo !== null) {
            return $combo;
        }

        return null;
    }

    /**
     * Strategy A: Previous 2 enabled + current
     * Finds the 2 most recent enabled items before the excluded item.
     *
     * @return array<int>|null
     */
    private function strategyPreviousTwo(
        array $excluded,
        int $position,
        array $allList,
        string $outputOrientation
    ): ?array {
        $found = [];
        for ($i = $position - 1; $i >= 0 && count($found) < 2; $i--) {
            $item = $allList[$i];
            if ($item['included'] === 1 && $this->hasTriptychOrientation($item, $outputOrientation)) {
                $found[] = $item;
            }
        }

        if (count($found) < 2) {
            return null;
        }

        return [$found[1]['file_id'], $found[0]['file_id'], $excluded['file_id']];
    }

    /**
     * Strategy B: Previous 1 enabled + current + next 1 enabled
     *
     * @return array<int>|null
     */
    private function strategyPreviousOneNextOne(
        array $excluded,
        int $position,
        array $allList,
        string $outputOrientation
    ): ?array {
        $prevItem = null;
        for ($i = $position - 1; $i >= 0; $i--) {
            $item = $allList[$i];
            if ($item['included'] === 1 && $this->hasTriptychOrientation($item, $outputOrientation)) {
                $prevItem = $item;
                break;
            }
        }

        if ($prevItem === null) {
            return null;
        }

        $nextItem = null;
        for ($i = $position + 1; $i < count($allList); $i++) {
            $item = $allList[$i];
            if ($item['included'] === 1 && $this->hasTriptychOrientation($item, $outputOrientation)) {
                $nextItem = $item;
                break;
            }
        }

        if ($nextItem === null) {
            return null;
        }

        return [$prevItem['file_id'], $excluded['file_id'], $nextItem['file_id']];
    }

    /**
     * Strategy C: Current + next 2 enabled
     *
     * @return array<int>|null
     */
    private function strategyNextTwo(
        array $excluded,
        int $position,
        array $allList,
        string $outputOrientation
    ): ?array {
        $found = [];
        for ($i = $position + 1; $i < count($allList) && count($found) < 2; $i++) {
            $item = $allList[$i];
            if ($item['included'] === 1 && $this->hasTriptychOrientation($item, $outputOrientation)) {
                $found[] = $item;
            }
        }

        if (count($found) < 2) {
            return null;
        }

        return [$excluded['file_id'], $found[0]['file_id'], $found[1]['file_id']];
    }

    /**
     * Strategy D: Smart re-enable
     * Looks for up to 2 additional excluded items within ±LOOKAHEAD_DISTANCE
     * that could form a valid triptych with the current excluded item.
     *
     * @return array<int>|null
     */
    private function strategySmartReEnable(
        array $excluded,
        int $position,
        array $allList,
        string $outputOrientation
    ): ?array {
        $candidates = [];

        // Look within lookahead distance before and after
        $start = max(0, $position - self::LOOKAHEAD_DISTANCE);
        $end = min(count($allList) - 1, $position + self::LOOKAHEAD_DISTANCE);

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $position) {
                continue;
            }

            $item = $allList[$i];
            if ($item['included'] === 0 && $this->hasTriptychOrientation($item, $outputOrientation)) {
                $item['_position'] = $i;
                $candidates[] = $item;
            }
        }

        if (count($candidates) < 2) {
            return null;
        }

        // Try all 2-combinations of candidates with the excluded item
        for ($i = 0; $i < count($candidates); $i++) {
            for ($j = $i + 1; $j < count($candidates); $j++) {
                $a = $candidates[$i];
                $b = $candidates[$j];

                // Sort by position to keep temporal sequence
                $items = [
                    [$excluded, $position],
                    [$a, $a['_position']],
                    [$b, $b['_position']],
                ];

                usort($items, fn(array $x, array $y) => $x[1] <=> $y[1]);

                // Check if they're sufficiently close in time (to maintain sequence)
                $pos1 = $items[0][1];
                $pos2 = $items[1][1];
                $pos3 = $items[2][1];

                if ($pos3 - $pos1 <= self::LOOKAHEAD_DISTANCE) {
                    return [$items[0][0]['file_id'], $items[1][0]['file_id'], $items[2][0]['file_id']];
                }
            }
        }

        return null;
    }

    // -----------------------------------------------
    // DB operations
    // -----------------------------------------------

    /**
     * Loads all media items (included and excluded) for an event with dimensions.
     */
    private function loadAllMedia(int $eventId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.file_id', 'm.sort_order', 'm.included', 'mem.isvideo', 'mem.w', 'mem.h', 'mem.epoch')
            ->from('reel_event_media', 'm')
            ->leftJoin('m', 'memories', 'mem', $qb->expr()->eq('m.file_id', 'mem.fileid'))
            ->where($qb->expr()->eq('m.event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)));

        $result = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $result[] = [
                'file_id' => (int)$row['file_id'],
                'sort_order' => (int)$row['sort_order'],
                'included' => (int)$row['included'],
                'isvideo' => (int)($row['isvideo'] ?? 0),
                'w' => (int)($row['w'] ?? 0),
                'h' => (int)($row['h'] ?? 0),
                'epoch' => (int)($row['epoch'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Marks media items as included (re-enables them).
     * Returns count of items updated.
     */
    private function markIncluded(int $eventId, string $userId, array $fileIds): int {
        if (empty($fileIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_event_media')
            ->set('included', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $qb->executeStatement();

        return count($fileIds);
    }

    private function emitDebug(?callable $onDebug, string $message): void {
        if ($onDebug !== null) {
            $onDebug($message);
        }
    }
}
