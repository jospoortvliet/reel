<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * EventDetectionService
 *
 * Reads photo/video metadata from Memories' tables and clusters them
 * into events based on time gaps and location changes.
 *
 * Memories tables used (read-only):
 *   oc_memories          — one row per indexed file (date, GPS, liveid, etc.)
 *   oc_memories_places   — links fileid → osm_id
 *   oc_memories_planet   — links osm_id → human-readable place name
 *   oc_memories_livephoto — links Live Photo stills to their video clips
 *
 * Reel tables written:
 *   oc_reel_events       — one row per detected event
 *   oc_reel_event_media  — one row per file belonging to an event
 */
class EventDetectionService {

    // A gap of more than this between consecutive photos triggers a new event
    private const TIME_GAP_SECONDS = 6 * 60 * 60; // 6 hours
    private const MIN_EVENT_ITEMS = 6;
    private const PLACE_CHANGE_MIN_GAP_SECONDS = 30 * 60; // 30 minutes
    private const PLACE_DOMINANCE_THRESHOLD = 0.6;

    // OSM admin_level for city-level granularity (8 = city, 6 = county, 4 = region)
    // We prefer the most specific level available
    private const PREFERRED_ADMIN_LEVELS = [8, 7, 6, 5, 4];

    public function __construct(
        private IDBConnection        $db,
        private LoggerInterface      $logger,
        private DuplicateFilterService $duplicateFilter,
        private MemoriesRepository   $memoriesRepository,
    ) {}

    /**
     * Main entry point. Detects events for a single user and writes them
     * to the Reel tables.
     *
     * Incremental mode keeps event IDs and user customisations where possible.
     * Rebuild mode drops and recreates all events/media for the user.
     */
    public function detectForUser(string $userId, bool $rebuild = false): int {
        $this->logger->info('Reel: starting event detection for user {user}', ['user' => $userId]);

        // 1. Load all media for this user from Memories, sorted by time
        $media = $this->loadMediaForUser($userId);

        if (empty($media)) {
            $this->logger->info('Reel: no indexed media found for user {user}', ['user' => $userId]);
            return 0;
        }

        $this->logger->info('Reel: loaded {count} media items for user {user}', [
            'count' => count($media),
            'user'  => $userId,
        ]);

        // 2. Cluster into events
        $clusters = $this->clusterIntoEvents($media);

        $this->logger->info('Reel: detected {count} events for user {user}', [
            'count' => count($clusters),
            'user'  => $userId,
        ]);

        if ($rebuild) {
            $this->logger->info('Reel: running detection in full rebuild mode for user {user}', ['user' => $userId]);
            $this->persistClustersRebuild($userId, $clusters);
        } else {
            // 3. Incrementally sync clusters into reel_events + reel_event_media
            $this->persistClustersIncremental($userId, $clusters);
        }

        return count($clusters);
    }

    // -------------------------------------------------------------------------
    // Step 1: Load media from Memories
    // -------------------------------------------------------------------------

    /**
     * Returns all media rows for a user, enriched with place name, sorted by epoch.
     * Each row is an associative array with keys:
     *   fileid, epoch, isvideo, liveid, lat, lon, video_duration, place_name
     */
    private function loadMediaForUser(string $userId): array {
        return $this->memoriesRepository->loadMediaForUser($userId);
    }

    /**
     * Given a list of fileids, returns a map of fileid → best place name
     * by joining oc_memories_places → oc_memories_planet.
     */
    private function loadPlaceNames(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        // PostgreSQL limits bind parameters to 65535 per query; chunk to stay safe.
        $rows = [];
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('mp.fileid', 'pl.name', 'pl.admin_level')
                ->from('memories_places', 'mp')
                ->innerJoin('mp', 'memories_planet', 'pl', $qb->expr()->eq('mp.osm_id', 'pl.osm_id'))
                ->where($qb->expr()->in('mp.fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('pl.admin_level', 'DESC'); // most specific first

            $result = $qb->executeQuery();
            $rows   = array_merge($rows, $result->fetchAll());
            $result->closeCursor();
        }

        // For each fileid, pick the most specific admin level we prefer
        $placeMap = [];
        foreach ($rows as $row) {
            $fid   = (int)$row['fileid'];
            $level = (int)$row['admin_level'];

            // Keep the entry if we haven't seen this file yet,
            // or if this row is at a more preferred (higher) admin level
            if (!isset($placeMap[$fid]) || $level > $placeMap[$fid]['admin_level']) {
                $placeMap[$fid] = [
                    'name'        => $row['name'],
                    'admin_level' => $level,
                ];
            }
        }

        // Flatten to fileid → name
        return array_map(fn($v) => $v['name'], $placeMap);
    }

    // -------------------------------------------------------------------------
    // Step 2: Cluster into events
    // -------------------------------------------------------------------------

    /**
     * Splits a time-sorted list of media rows into event clusters.
     *
     * A new cluster is started when:
    *   - The time gap to the previous item exceeds TIME_GAP_SECONDS, OR
     *   - The place name changes (and both items have a known place)
     *
     * Returns an array of clusters, each being:
     *   [
     *     'date_start' => int (epoch),
     *     'date_end'   => int (epoch),
     *     'location'   => string|null,
     *     'title'      => string,
     *     'media'      => [ ...rows... ],
     *   ]
     */
    private function clusterIntoEvents(array $media): array {
        $clusters = [];
        $current  = null;

        foreach ($media as $item) {
            $epoch = (int)$item['epoch'];

            if ($current === null) {
                // First item — start the first cluster
                $current = $this->newCluster($item);
                continue;
            }

            // Compare against the most recent item already in the cluster,
            // so long days with steady activity do not split just because the
            // first and last items are more than 6 hours apart.
            $timeGap       = $epoch - $current['date_end'];
            $placeChanged  = $this->placeChanged($current, $item, $timeGap);

            if ($timeGap > self::TIME_GAP_SECONDS || $placeChanged) {
                // Finalise the current cluster and start a new one
                $clusters[] = $this->finaliseCluster($current);
                $current    = $this->newCluster($item);
            } else {
                // Add to current cluster
                $current['media'][]  = $item;
                $current['date_end'] = $epoch;

                // Update dominant location if this item has one
                if (!empty($item['place_name'])) {
                    $current['place_counts'][$item['place_name']]
                        = ($current['place_counts'][$item['place_name']] ?? 0) + 1;
                }
            }
        }

        // Don't forget the last cluster
        if ($current !== null) {
            $clusters[] = $this->finaliseCluster($current);
        }

        // Filter out small clusters — they tend to produce weak, noisy reels.
        return array_values(array_filter($clusters, fn($c) => count($c['media']) >= self::MIN_EVENT_ITEMS));
    }

    private function newCluster(array $item): array {
        $epoch = (int)$item['epoch'];
        return [
            'date_start'   => $epoch,
            'date_end'     => $epoch,
            'place_counts' => empty($item['place_name']) ? [] : [$item['place_name'] => 1],
            'media'        => [$item],
        ];
    }

    private function finaliseCluster(array $cluster): array {
        // Pick the most frequently occurring place name as the event location
        $location = null;
        if (!empty($cluster['place_counts'])) {
            arsort($cluster['place_counts']);
            $location = array_key_first($cluster['place_counts']);
        }

        $cluster['location'] = $location;
        $cluster['title']    = $this->buildTitle($cluster['date_start'], $location);

        unset($cluster['place_counts']); // no longer needed
        return $cluster;
    }

    /**
     * Returns true if the item's place name is known, different from the
     * cluster's dominant place, the cluster has enough items to have
     * established a dominant place, and there was a meaningful pause before
     * the new place appeared.
     */
    private function placeChanged(array $cluster, array $item, int $timeGap): bool {
        if (empty($item['place_name']))         return false;
        if (empty($cluster['place_counts']))    return false;
        if ($timeGap < self::PLACE_CHANGE_MIN_GAP_SECONDS) return false;

        arsort($cluster['place_counts']);
        $dominant = array_key_first($cluster['place_counts']);
        $dominantCount = (int)($cluster['place_counts'][$dominant] ?? 0);
        $totalCount = (int)array_sum($cluster['place_counts']);

        if ($totalCount <= 0 || ($dominantCount / $totalCount) < self::PLACE_DOMINANCE_THRESHOLD) {
            return false;
        }

        return $item['place_name'] !== $dominant
            && array_sum($cluster['place_counts']) >= 3; // need at least 3 items to trust dominant place
    }

    /**
     * Builds a human-readable event title, e.g. "Barcelona · March 2026"
     * or just "March 2026" if no location is known.
     */
    private function buildTitle(int $epoch, ?string $location): string {
        $date = (new \DateTime())->setTimestamp($epoch)->format('F Y');
        return $location ? "{$location} · {$date}" : $date;
    }

    // -------------------------------------------------------------------------
    // Step 3: Persist to Reel tables
    // -------------------------------------------------------------------------

    private function persistClustersIncremental(string $userId, array $clusters): void {
        $existing = $this->loadExistingEventsWithMedia($userId);
        $matchedEventIds = [];

        foreach ($clusters as $cluster) {
            $clusterFileIds = array_values(array_map(
                static fn(array $item): int => (int)$item['fileid'],
                $cluster['media']
            ));

            $eventId = $this->findBestExistingEventMatch($cluster, $clusterFileIds, $existing, $matchedEventIds);
            if ($eventId !== null) {
                $this->updateEventRow($eventId, $cluster);
                $this->syncEventMedia($eventId, $userId, $clusterFileIds);
                $matchedEventIds[] = $eventId;
                continue;
            }

            $newEventId = $this->insertEventWithMedia($userId, $cluster);
            $matchedEventIds[] = $newEventId;
        }

        foreach ($existing as $eventId => $_event) {
            if (!in_array((int)$eventId, $matchedEventIds, true)) {
                $this->deleteEvent((int)$eventId, $userId);
            }
        }
    }

    private function persistClustersRebuild(string $userId, array $clusters): void {
        $this->clearEventsForUser($userId);

        foreach ($clusters as $cluster) {
            $this->insertEventWithMedia($userId, $cluster);
        }
    }

    private function clearEventsForUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('reel_event_media')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->delete('reel_events')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }

    private function loadExistingEventsWithMedia(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('e.id', 'e.date_start', 'e.date_end', 'e.location', 'm.file_id')
            ->from('reel_events', 'e')
            ->leftJoin('e', 'reel_event_media', 'm', $qb->expr()->eq('e.id', 'm.event_id'))
            ->where($qb->expr()->eq('e.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('e.date_start', 'ASC')
            ->addOrderBy('m.sort_order', 'ASC');

        $rows = $qb->executeQuery()->fetchAll();
        $events = [];
        foreach ($rows as $row) {
            $eventId = (int)$row['id'];
            if (!isset($events[$eventId])) {
                $events[$eventId] = [
                    'id' => $eventId,
                    'date_start' => (int)$row['date_start'],
                    'date_end' => (int)$row['date_end'],
                    'location' => $row['location'] ?? null,
                    'file_ids' => [],
                    'file_id_set' => [],
                ];
            }

            if ($row['file_id'] !== null) {
                $fileId = (int)$row['file_id'];
                $events[$eventId]['file_ids'][] = $fileId;
                $events[$eventId]['file_id_set'][$fileId] = true;
            }
        }

        return $events;
    }

    private function findBestExistingEventMatch(array $cluster, array $clusterFileIds, array $existing, array $alreadyMatchedEventIds): ?int {
        if (empty($clusterFileIds)) {
            return null;
        }

        $clusterSet = array_fill_keys($clusterFileIds, true);
        $clusterCount = count($clusterSet);
        $clusterStart = (int)$cluster['date_start'];

        $bestId = null;
        $bestScore = -1.0;
        $bestOverlap = 0.0;

        foreach ($existing as $eventId => $event) {
            if (in_array((int)$eventId, $alreadyMatchedEventIds, true)) {
                continue;
            }

            $existingSet = $event['file_id_set'];
            if (empty($existingSet)) {
                continue;
            }

            $intersection = count(array_intersect_key($clusterSet, $existingSet));
            if ($intersection === 0) {
                continue;
            }

            $union = count($clusterSet + $existingSet);
            $overlap = $union > 0 ? ($intersection / $union) : 0.0;

            $dateDelta = abs($clusterStart - (int)$event['date_start']);
            $datePenalty = min(1.0, $dateDelta / (14 * 24 * 3600));
            $score = $overlap - (0.08 * $datePenalty);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestOverlap = $overlap;
                $bestId = (int)$eventId;
            }
        }

        if ($bestId === null) {
            return null;
        }

        if ($bestOverlap >= 0.55) {
            return $bestId;
        }

        // Small clusters are brittle; allow a softer threshold if at least
        // half the files overlap and there are only a few files involved.
        if ($clusterCount <= 4 && $bestOverlap >= 0.50) {
            return $bestId;
        }

        return null;
    }

    private function updateEventRow(int $eventId, array $cluster): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_events')
            ->set('title', $qb->createNamedParameter($cluster['title']))
            ->set('date_start', $qb->createNamedParameter((int)$cluster['date_start'], IQueryBuilder::PARAM_INT))
            ->set('date_end', $qb->createNamedParameter((int)$cluster['date_end'], IQueryBuilder::PARAM_INT))
            ->set('location', $qb->createNamedParameter($cluster['location']))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function syncEventMedia(int $eventId, string $userId, array $clusterFileIds): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'file_id')
            ->from('reel_event_media')
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $existingRows = $qb->executeQuery()->fetchAll();
        $existingByFileId = [];
        foreach ($existingRows as $row) {
            $existingByFileId[(int)$row['file_id']] = (int)$row['id'];
        }

        $seen = [];
        foreach ($clusterFileIds as $order => $fileId) {
            $fileId = (int)$fileId;
            $seen[$fileId] = true;

            if (isset($existingByFileId[$fileId])) {
                $update = $this->db->getQueryBuilder();
                $update->update('reel_event_media')
                    ->set('sort_order', $update->createNamedParameter($order, IQueryBuilder::PARAM_INT))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($existingByFileId[$fileId], IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                continue;
            }

            $insert = $this->db->getQueryBuilder();
            $insert->insert('reel_event_media')
                ->values([
                    'event_id' => $insert->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
                    'user_id' => $insert->createNamedParameter($userId),
                    'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                    'included' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    'sort_order' => $insert->createNamedParameter($order, IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement();
        }

        if (!empty($existingByFileId)) {
            foreach ($existingByFileId as $fileId => $rowId) {
                if (!isset($seen[$fileId])) {
                    $delete = $this->db->getQueryBuilder();
                    $delete->delete('reel_event_media')
                        ->where($delete->expr()->eq('id', $delete->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                }
            }
        }
    }

    private function insertEventWithMedia(string $userId, array $cluster): int {
        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('reel_events')
            ->values([
                'user_id'    => $qb->createNamedParameter($userId),
                'title'      => $qb->createNamedParameter($cluster['title']),
                'date_start' => $qb->createNamedParameter($cluster['date_start'], IQueryBuilder::PARAM_INT),
                'date_end'   => $qb->createNamedParameter($cluster['date_end'], IQueryBuilder::PARAM_INT),
                'location'   => $qb->createNamedParameter($cluster['location']),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        $eventId = (int)$this->db->lastInsertId('oc_reel_events');

        foreach ($cluster['media'] as $order => $item) {
            $mqb = $this->db->getQueryBuilder();
            $mqb->insert('reel_event_media')
                ->values([
                    'event_id' => $mqb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
                    'user_id' => $mqb->createNamedParameter($userId),
                    'file_id' => $mqb->createNamedParameter((int)$item['fileid'], IQueryBuilder::PARAM_INT),
                    'included' => $mqb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    'sort_order' => $mqb->createNamedParameter($order, IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement();
        }

        // Apply duplicate suppression only for newly created events.
        $excluded = $this->duplicateFilter->filterEvent($eventId, $userId);
        if ($excluded > 0) {
            $this->logger->debug('Reel: excluded {n} duplicates from new event {id}', [
                'n' => $excluded,
                'id' => $eventId,
            ]);
        }

        return $eventId;
    }

    private function deleteEvent(int $eventId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('reel_event_media')
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->delete('reel_events')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }
}
