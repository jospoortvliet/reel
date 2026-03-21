<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\Files\IRootFolder;
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
    private const MIN_LIVE_CLIP_SECONDS = 1.2;

    // OSM admin_level for city-level granularity (8 = city, 6 = county, 4 = region)
    // We prefer the most specific level available
    private const PREFERRED_ADMIN_LEVELS = [8, 7, 6, 5, 4];

    // --- Tag-based title enrichment ---

    // Fraction of items in a cluster that must carry a tag for it to appear in the title
    private const TAG_DOMINANT_THRESHOLD = 0.70;

    // Tags that are too generic, noisy, or meta to add value to an event title
    private const TAG_BLOCKLIST = [
        'Tagged by recognize', // prefix — matches any version string
        'People', 'Furniture', 'Landscape', 'Nature', 'Indoor', 'Outdoor',
        'Building', 'Info', 'Sand', 'Water', 'Display', 'Screen', 'Living',
        'Electronics', 'Vehicle', 'Document', 'Architecture', 'Object',
        'Structure', 'Transport', 'Text', 'Art', 'Still Life', 'Technology',
    ];

    /**
     * Specificity score for known tags. Higher = more specific = preferred when
     * multiple tags exceed the dominance threshold. Tags not listed get score 5.
     */
    private const TAG_SPECIFICITY = [
        // Animals – specific beats generic
        'Dog' => 20, 'Cat' => 20, 'Bird' => 20, 'Horse' => 20,
        'Fish' => 20, 'Rabbit' => 20,
        'Animal' => 8, 'Pet' => 8,
        // Nature – specific beats generic
        'Seashore' => 18, 'Beach' => 15, 'Mountains' => 18, 'Alpine' => 15,
        'Forest' => 12, 'Snow' => 12, 'Waterfall' => 18, 'Desert' => 15,
        // Activities / scenes
        'Camping' => 18, 'Skiing' => 18, 'Cycling' => 15, 'Swimming' => 15,
        'Stage' => 12, 'Concert' => 18, 'Music' => 12, 'Sport' => 8,
        // Landmarks
        'Church' => 12, 'Historic' => 10, 'Tower' => 10, 'Shop' => 8,
        'Restaurant' => 12,
        // People
        'Portrait' => 10,
        // Food
        'Food' => 8, 'Dining' => 8,
    ];

    private const EVENT_KIND_TIMELINE = 'timeline';
    private const EVENT_KIND_PETS = 'pets_year';
    private const EVENT_KIND_PERSON = 'person_year';
    private const EVENT_KIND_TRIP_SHORT = 'trip_short';
    private const EVENT_KIND_TRIP_LONG = 'trip_long';
    private const EVENT_KIND_YEAR_REVIEW = 'year_review';
    private const EVENT_KIND_SEASON = 'season';
    private const EVENT_KIND_TRADITION = 'tradition';
    private const EVENT_KIND_TIMELINE_SUB = 'timeline_sub';

    private const PET_YEAR_MIN_ITEMS = 10;
    private const PERSON_YEAR_MIN_ITEMS = 10;
    private const TRIP_MIN_ITEMS = 8;
    private const YEAR_REVIEW_MIN_ITEMS = 30;
    private const SEASON_MIN_ITEMS = 15;
    private const TRADITION_MIN_ITEMS = 10;
    private const TRIP_SHORT_MAX_SECONDS = 4 * 24 * 3600;
    private const TRIP_LONG_MAX_SECONDS = 28 * 24 * 3600;
    private const TRIP_SEGMENT_GAP_SECONDS = 3 * 24 * 3600;
    // A timeline cluster is suppressed when this fraction of its files are covered by a trip event
    private const TRIP_ABSORB_THRESHOLD = 0.9;

    private const LARGE_COUNTRIES = [
        'United States', 'USA', 'United States of America',
        'Brazil', 'Brasil',
        'Russia', 'Russian Federation',
        'India',
        'China', "People's Republic of China",
    ];

    private const PET_TAG_TITLES = [
        'Dog' => 'Dogs',
        'Cat' => 'Cats',
        'Bird' => 'Birds',
        'Horse' => 'Horses',
        'Rabbit' => 'Rabbits',
        'Fish' => 'Fish',
        'Animal' => 'Pets',
        'Pet' => 'Pets',
    ];

    private const SEASON_NAMES = [
        1 => 'Winter', 2 => 'Winter', 3 => 'Spring',
        4 => 'Spring', 5 => 'Spring', 6 => 'Summer',
        7 => 'Summer', 8 => 'Summer', 9 => 'Autumn',
        10 => 'Autumn', 11 => 'Autumn', 12 => 'Winter',
    ];

    /** @var (callable(string):void)|null */
    private $debugCallback = null;

    public function __construct(
        private IDBConnection        $db,
        private LoggerInterface      $logger,
        private UtilityFilterService $utilityFilter,
        private DuplicateFilterService $duplicateFilter,
        private DistinctFilterService $distinctFilter,
        private MemoriesRepository   $memoriesRepository,
        private IRootFolder          $rootFolder,
        private MusicService         $musicService,
    ) {}

    /**
     * Main entry point. Detects events for a single user and writes them
     * to the Reel tables.
     *
     * Incremental mode keeps event IDs and user customisations where possible.
     * Rebuild mode drops and recreates all events/media for the user.
     */
    public function detectForUser(string $userId, bool $rebuild = false, ?callable $onDebug = null): int {
        $this->debugCallback = $onDebug;
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

        // 1b. Enrich each media item with tags, people, and place hierarchy.
        $fileIds = array_map(static fn(array $i): int => (int)$i['fileid'], $media);
        $tagMap  = $this->memoriesRepository->loadTagsForFiles($fileIds);
        $placeHierarchyMap = $this->memoriesRepository->loadPlaceHierarchyForFiles($fileIds);
        $personMap = $this->memoriesRepository->loadFaceClustersForFiles($fileIds, $userId);
        foreach ($media as &$item) {
            $item['tags'] = $tagMap[(int)$item['fileid']] ?? [];
            $placeHierarchy = $placeHierarchyMap[(int)$item['fileid']] ?? [
                'country' => null,
                'region' => null,
                'city' => null,
                'timezone' => null,
            ];
            $item['place_hierarchy'] = $placeHierarchy;
            $item['country_name'] = $placeHierarchy['country'];
            $item['region_name'] = $placeHierarchy['region'];
            $item['city_name'] = $placeHierarchy['city'];
            $item['person_clusters'] = $personMap[(int)$item['fileid']] ?? [];
        }
        unset($item);

        // 2. Build the normal timeline events plus derived yearly/special events.
        $timelineClusters = $this->clusterIntoEvents($media);
        $tripEvents = $this->detectTripEvents($media);
        $tripDeDup = $this->splitTimelineClustersAgainstTrips($timelineClusters, $tripEvents);
        $timelineClusters = $tripDeDup['timeline'];
        $tripSubEvents = $tripDeDup['sub_events'];
        $clusters = array_merge(
            $timelineClusters,
            $this->detectPetYearEvents($media),
            $this->detectPersonYearEvents($media),
            $tripEvents,
            $tripSubEvents,
            $this->detectYearReviewEvents($media),
            $this->detectSeasonalEvents($media),
            $this->detectTraditionEvents($timelineClusters),
        );

        $this->logger->info('Reel: detected {count} events for user {user}', [
            'count' => count($clusters),
            'user'  => $userId,
        ]);

        try {
            if ($rebuild) {
                $this->logger->info('Reel: running detection in full rebuild mode for user {user}', ['user' => $userId]);
                $this->persistClustersRebuild($userId, $clusters);
            } else {
                // 3. Incrementally sync clusters into reel_events + reel_event_media
                $this->persistClustersIncremental($userId, $clusters);
            }

            return count($clusters);
        } finally {
            $this->debugCallback = null;
        }
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

    private function buildMediaSettings(string $userId, array $item, array $existingSettings = []): array {
        $settings = $existingSettings;
        $fileId = (int)$item['fileid'];
        $isVideo = !empty($item['isvideo']);
        $hasLiveVideo = false;

        if (!$isVideo && !empty($item['liveid'])) {
            $duration = $this->probeLiveVideoDurationSeconds($userId, $fileId);
            $hasLiveVideo = $duration !== null && $duration >= self::MIN_LIVE_CLIP_SECONDS;

            if ($duration !== null && !$hasLiveVideo) {
                $this->logger->info(
                    'Reel: storing live photo as still only (file {id}, {d}s < {min}s)',
                    ['id' => $fileId, 'd' => round($duration, 2), 'min' => self::MIN_LIVE_CLIP_SECONDS]
                );
            }
        }

        if ($isVideo) {
            $sourceDuration = max(0.0, (float)($item['video_duration'] ?? 0.0));
            [$defaultStart, $defaultLength] = $this->defaultVideoWindow($sourceDuration);
            $hasLiveVideo = false;
            if (!array_key_exists('video_start', $settings)) {
                $settings['video_start'] = $defaultStart;
            }
            if (!array_key_exists('video_length', $settings)) {
                $settings['video_length'] = $defaultLength;
            }
        } else {
            unset($settings['video_start'], $settings['video_length']);
        }

        $settings['has_live_video'] = $hasLiveVideo;
        if (!$hasLiveVideo) {
            $settings['use_live_video'] = false;
        } elseif (!array_key_exists('use_live_video', $settings)) {
            $settings['use_live_video'] = true;
        }

        return $settings;
    }

    private function defaultVideoWindow(float $sourceDuration): array {
        if ($sourceDuration <= 0.0) {
            return [0.0, 8.0];
        }

        $length = min(8.0, max(0.6, $sourceDuration));
        $start = $sourceDuration > ($length + 0.05)
            ? max(0.0, ($sourceDuration - $length) / 2.0)
            : 0.0;

        return [$start, $length];
    }

    private function probeLiveVideoDurationSeconds(string $userId, int $stillFileId): ?float {
    $liveVideoFileId = $this->memoriesRepository->findLiveVideoFileId($stillFileId);
    if ($liveVideoFileId === null) {
        $this->logger->debug('Reel: no paired .mov found for still {id}', ['id' => $stillFileId]);
        return null;
    }

    try {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $nodes = $userFolder->getById($liveVideoFileId);
        if (empty($nodes)) {
            $this->logger->warning('Reel: live video file {id} not found in user folder', ['id' => $liveVideoFileId]);
            return null;
        }

        $node = $nodes[0];
        $storage = $node->getStorage();

        if (!$storage->isLocal()) {
            // S3/remote storage: getLocalFile() returns null so ffprobe can't be used.
            // TODO: implement fopen() download-to-temp fallback for non-local storage.
            // Live photo .mov files are typically 2-8MB so a full download is acceptable.
            $this->logger->debug(
                'Reel: skipping ffprobe for live video {id} on non-local storage ({class}), using default duration',
                ['id' => $liveVideoFileId, 'class' => get_class($storage)]
            );
            return null;
        }

        $localFile = $storage->getLocalFile($node->getInternalPath());
        if ($localFile === null) {
            $this->logger->warning(
                'Reel: getLocalFile() returned null for {id} despite isLocal() being true',
                ['id' => $liveVideoFileId]
            );
            return null;
        }
        if (!file_exists($localFile)) {
            $this->logger->warning('Reel: local file {path} does not exist on disk', ['path' => $localFile]);
            return null;
        }

        $duration = $this->probeDurationWithFfprobe($localFile);
        if ($duration === null) {
            $this->logger->warning('Reel: ffprobe returned no usable duration for live video {id}', ['id' => $liveVideoFileId]);
        }
        return $duration;

    } catch (\Throwable $e) {
        $this->logger->error('Reel: unexpected error probing duration for still {id}: {msg}', [
            'id'        => $stillFileId,
            'msg'       => $e->getMessage(),
            'exception' => $e,
        ]);
        return null;
    }
}

private function probeDurationWithFfprobe(string $localPath): ?float {
    $cmd = [
        'ffprobe',
        '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $localPath,
    ];

    $process = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        $this->logger->error('Reel: failed to launch ffprobe for {path}', ['path' => $localPath]);
        return null;
    }

    fclose($pipes[0]);

    // Read stdout/stderr with a timeout to avoid hanging on corrupt files
    $stdout = '';
    $stderr = '';
    $timeout = 10;
    $start = time();

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        if (time() - $start > $timeout) {
            $this->logger->error('Reel: ffprobe timed out after {s}s for {path}', [
                'path' => $localPath,
                's'    => $timeout,
            ]);
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return null;
        }

        $read = [$pipes[1], $pipes[2]];
        $write = $except = [];
        if (stream_select($read, $write, $except, 1) === false) {
            break;
        }

        foreach ($read as $pipe) {
            if ($pipe === $pipes[1]) {
                $stdout .= fread($pipe, 4096);
            } else {
                $stderr .= fread($pipe, 4096);
            }
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $this->logger->warning('Reel: ffprobe exited {code} for {path}: {stderr}', [
            'path'   => $localPath,
            'code'   => $exitCode,
            'stderr' => trim($stderr),
        ]);
        return null;
    }

    if ($stdout === false || !is_numeric(trim($stdout))) {
        $this->logger->warning('Reel: ffprobe returned non-numeric output "{val}" for {path}', [
            'path' => $localPath,
            'val'  => trim((string)$stdout),
        ]);
        return null;
    }

    $duration = (float)trim($stdout);
    return $duration > 0.0 ? $duration : null;
}   

    private function emitMediaDebugLine(int $eventId, int $fileId, array $item, array $settings): void {
        if ($this->debugCallback === null) {
            return;
        }

        $type = !empty($item['isvideo'])
            ? 'video'
            : (!empty($item['liveid']) ? 'photo_live_memories' : 'photo');
        $path = (string)($item['path'] ?? '');

        ($this->debugCallback)(sprintf(
            'event=%d file_id=%d path="%s" type=%s has_live_video=%s use_live_video=%s video_start=%s video_length=%s',
            $eventId,
            $fileId,
            $path,
            $type,
            (($settings['has_live_video'] ?? false) ? 'true' : 'false'),
            (($settings['use_live_video'] ?? false) ? 'true' : 'false'),
            array_key_exists('video_start', $settings) ? (string)$settings['video_start'] : 'null',
            array_key_exists('video_length', $settings) ? (string)$settings['video_length'] : 'null',
        ));
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

                // Accumulate tag counts
                foreach ($item['tags'] ?? [] as $tag) {
                    $current['tag_counts'][$tag] = ($current['tag_counts'][$tag] ?? 0) + 1;
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
        $tagCounts = [];
        foreach ($item['tags'] ?? [] as $tag) {
            $tagCounts[$tag] = 1;
        }
        return [
            'date_start'   => $epoch,
            'date_end'     => $epoch,
            'place_counts' => empty($item['place_name']) ? [] : [$item['place_name'] => 1],
            'tag_counts'   => $tagCounts,
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

        $tag = $this->findDominantTag($cluster['tag_counts'] ?? [], count($cluster['media']));

        $cluster['location'] = $location;
        $cluster['title']    = $this->buildTitle($cluster['date_start'], $location, $tag);
        $cluster['dominant_tag'] = $tag;
        $cluster['event_kind'] = self::EVENT_KIND_TIMELINE;
        $cluster['event_key'] = null;

        unset($cluster['place_counts'], $cluster['tag_counts']); // no longer needed
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
    private function buildTitle(int $epoch, ?string $location, ?string $tag = null): string {
        $date = (new \DateTime())->setTimestamp($epoch)->format('F Y');
        $parts = array_filter([$location, $tag, $date]);
        return implode(' · ', $parts);
    }

    /**
     * Given a map of tag → occurrence-count and total item count for a cluster,
     * returns the single most-specific tag that exceeds TAG_DOMINANT_THRESHOLD,
     * or null if none qualifies.
     *
     * @param array<string, int> $tagCounts
     */
    private function findDominantTag(array $tagCounts, int $totalItems): ?string {
        if ($totalItems === 0 || empty($tagCounts)) {
            return null;
        }

        $threshold = (int)ceil($totalItems * self::TAG_DOMINANT_THRESHOLD);

        $qualifying = [];
        foreach ($tagCounts as $tag => $count) {
            if ($count >= $threshold && !$this->isTagBlocked($tag)) {
                $qualifying[$tag] = $count;
            }
        }

        if (empty($qualifying)) {
            return null;
        }

        // Sort by specificity desc, then by count desc
        uksort($qualifying, function (string $a, string $b) use ($qualifying): int {
            $sa = self::TAG_SPECIFICITY[$a] ?? 5;
            $sb = self::TAG_SPECIFICITY[$b] ?? 5;
            if ($sa !== $sb) {
                return $sb - $sa;
            }
            return $qualifying[$b] - $qualifying[$a];
        });

        return array_key_first($qualifying);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectPetYearEvents(array $media): array {
        $byYear = [];
        foreach ($media as $item) {
            $year = (int)date('Y', (int)$item['epoch']);
            $petTags = [];
            foreach ($item['tags'] ?? [] as $tag) {
                if (isset(self::PET_TAG_TITLES[$tag])) {
                    $petTags[$tag] = true;
                }
            }
            if (empty($petTags)) {
                continue;
            }

            $fileId = (int)$item['fileid'];
            $byYear[$year]['media'][$fileId] = $item;
            foreach (array_keys($petTags) as $tag) {
                $byYear[$year]['tag_counts'][$tag] = ($byYear[$year]['tag_counts'][$tag] ?? 0) + 1;
            }
        }

        $events = [];
        foreach ($byYear as $year => $group) {
            $items = array_values($group['media'] ?? []);
            if (count($items) < self::PET_YEAR_MIN_ITEMS) {
                continue;
            }

            $tag = $this->selectMostSpecificTag($group['tag_counts'] ?? []);
            $specificCount = (int)(($group['tag_counts'] ?? [])[$tag] ?? 0);
            $label = $specificCount >= (int)ceil(count($items) * 0.60)
                ? (self::PET_TAG_TITLES[$tag] ?? 'Pets')
                : 'Pets';
            $title = $label . ' · ' . $year;
            $events[] = $this->createDerivedEvent(
                self::EVENT_KIND_PETS,
                'pets:' . $year,
                $title,
                null,
                $items,
            );
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectPersonYearEvents(array $media): array {
        $groups = [];
        foreach ($media as $item) {
            $year = (int)date('Y', (int)$item['epoch']);
            $fileId = (int)$item['fileid'];
            foreach ($item['person_clusters'] ?? [] as $person) {
                $clusterId = (int)($person['cluster_id'] ?? 0);
                if ($clusterId <= 0) {
                    continue;
                }

                $groups[$year][$clusterId]['media'][$fileId] = $item;
                $groups[$year][$clusterId]['title'] = trim((string)($person['title'] ?? ''));
            }
        }

        $events = [];
        foreach ($groups as $year => $clusters) {
            foreach ($clusters as $clusterId => $group) {
                $items = array_values($group['media'] ?? []);
                if (count($items) < self::PERSON_YEAR_MIN_ITEMS) {
                    continue;
                }

                $label = trim((string)($group['title'] ?? ''));
                if ($label === '') {
                    $label = 'Person ' . $clusterId;
                }

                $events[] = $this->createDerivedEvent(
                    self::EVENT_KIND_PERSON,
                    'person:' . $clusterId . ':' . $year,
                    $label . ' · ' . $year,
                    null,
                    $items,
                );
            }
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectYearReviewEvents(array $media): array {
        $groups = [];
        foreach ($media as $item) {
            $year = (int)date('Y', (int)$item['epoch']);
            $groups[$year][] = $item;
        }

        $events = [];
        foreach ($groups as $year => $items) {
            if (count($items) < self::YEAR_REVIEW_MIN_ITEMS) {
                continue;
            }

            $events[] = $this->createDerivedEvent(
                self::EVENT_KIND_YEAR_REVIEW,
                'year-review:' . $year,
                'Best of ' . $year,
                null,
                $items,
            );
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectSeasonalEvents(array $media): array {
        $groups = [];
        foreach ($media as $item) {
            $month = (int)date('n', (int)$item['epoch']);
            $year = (int)date('Y', (int)$item['epoch']);
            $season = self::SEASON_NAMES[$month] ?? null;
            if ($season === null) {
                continue;
            }
            $groups[$year][$season][] = $item;
        }

        $events = [];
        foreach ($groups as $year => $seasons) {
            foreach ($seasons as $season => $items) {
                if (count($items) < self::SEASON_MIN_ITEMS) {
                    continue;
                }

                $events[] = $this->createDerivedEvent(
                    self::EVENT_KIND_SEASON,
                    'season:' . $year . ':' . $this->slugify($season),
                    $season . ' ' . $year,
                    null,
                    $items,
                );
            }
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectTripEvents(array $media): array {
        $byYear = [];
        foreach ($media as $item) {
            $year = (int)date('Y', (int)$item['epoch']);
            $byYear[$year][] = $item;
        }

        $events = [];
        foreach ($byYear as $year => $items) {
            usort($items, static fn(array $a, array $b): int => (int)$a['epoch'] <=> (int)$b['epoch']);
            $homeArea = $this->detectHomeArea($items);
            if ($homeArea === null) {
                continue;
            }

            $current = [];
            $currentArea = null;
            $lastEpoch = null;

            $flush = function () use (&$events, &$current, &$currentArea, $year): void {
                if (empty($current) || $currentArea === null) {
                    $current = [];
                    $currentArea = null;
                    return;
                }

                $duration = (int)end($current)['epoch'] - (int)reset($current)['epoch'];
                $count = count($current);
                if ($count < self::TRIP_MIN_ITEMS || $duration > self::TRIP_LONG_MAX_SECONDS) {
                    $current = [];
                    $currentArea = null;
                    return;
                }

                $kind = $duration <= self::TRIP_SHORT_MAX_SECONDS
                    ? self::EVENT_KIND_TRIP_SHORT
                    : self::EVENT_KIND_TRIP_LONG;
                $label = $this->buildTripLabel($current, $currentArea, $kind);
                $monthYear = date('F Y', (int)$current[0]['epoch']);
                $events[] = $this->createDerivedEvent(
                    $kind,
                    'trip:' . $kind . ':' . $year . ':' . $currentArea['key'] . ':' . date('Ymd', (int)$current[0]['epoch']),
                    'Trip to ' . $label . ' · ' . $monthYear,
                    $label,
                    $current,
                );

                $current = [];
                $currentArea = null;
            };

            foreach ($items as $item) {
                $itemArea = $this->tripAreaForItem($item);
                $epoch = (int)$item['epoch'];

                if ($itemArea === null) {
                    // Missing place hierarchy should not break an ongoing trip segment
                    // when the timeline remains contiguous.
                    if (
                        $currentArea !== null &&
                        ($lastEpoch === null || ($epoch - $lastEpoch) <= self::TRIP_SEGMENT_GAP_SECONDS)
                    ) {
                        $current[] = $item;
                    }
                    $lastEpoch = $epoch;
                    continue;
                }

                if ($itemArea['key'] === $homeArea['key']) {
                    $flush();
                    $lastEpoch = $epoch;
                    continue;
                }

                if (
                    $currentArea !== null &&
                    ($itemArea['key'] !== $currentArea['key'] || ($lastEpoch !== null && ($epoch - $lastEpoch) > self::TRIP_SEGMENT_GAP_SECONDS))
                ) {
                    $flush();
                }

                $current[] = $item;
                $currentArea = $itemArea;
                $lastEpoch = $epoch;
            }

            $flush();
        }

        return $events;
    }

    /**
     * Splits timeline clusters into:
     * - top-level timeline clusters that stay visible in the main list, and
     * - absorbed clusters moved under the owning trip as child sub-events.
     *
     * Tagged timeline clusters are always preserved as top-level events.
     *
     * @param array<int, array<string, mixed>> $timelineClusters
     * @param array<int, array<string, mixed>> $tripEvents
     * @return array{timeline: array<int, array<string, mixed>>, sub_events: array<int, array<string, mixed>>}
     */
    private function splitTimelineClustersAgainstTrips(array $timelineClusters, array $tripEvents): array {
        if (empty($tripEvents)) {
            return [
                'timeline' => $timelineClusters,
                'sub_events' => [],
            ];
        }

        $tripFileSets = [];
        foreach ($tripEvents as $trip) {
            $fileIds = array_map(static fn(array $item): int => (int)$item['fileid'], $trip['media']);
            $tripFileSets[] = [
                'event_key' => (string)($trip['event_key'] ?? ''),
                'set' => array_flip($fileIds),
            ];
        }

        $keptTimeline = [];
        $subEvents = [];

        foreach ($timelineClusters as $cluster) {
            if (!empty($cluster['dominant_tag'])) {
                $keptTimeline[] = $cluster;
                continue;
            }

            $clusterFileIds = array_map(static fn(array $item): int => (int)$item['fileid'], $cluster['media']);
            $clusterFileIds = array_values(array_unique($clusterFileIds));
            $total = count($clusterFileIds);
            if ($total === 0) {
                $keptTimeline[] = $cluster;
                continue;
            }

            $bestCoverage = 0.0;
            $bestTripKey = null;

            foreach ($tripFileSets as $tripData) {
                $covered = 0;
                foreach ($clusterFileIds as $fid) {
                    if (isset($tripData['set'][$fid])) {
                        $covered++;
                    }
                }

                $coverage = $covered / $total;
                if ($coverage > $bestCoverage) {
                    $bestCoverage = $coverage;
                    $bestTripKey = $tripData['event_key'];
                }
            }

            if ($bestCoverage >= self::TRIP_ABSORB_THRESHOLD && is_string($bestTripKey) && $bestTripKey !== '') {
                $subEvents[] = $this->createTimelineSubEvent($cluster, $bestTripKey);
                $this->logger->debug(
                    'Reel: moving timeline cluster "{title}" under trip {trip_key} ({pct}% overlap)',
                    [
                        'title' => $cluster['title'],
                        'trip_key' => $bestTripKey,
                        'pct' => round($bestCoverage * 100),
                    ]
                );
                continue;
            }

            $keptTimeline[] = $cluster;
        }

        return [
            'timeline' => $keptTimeline,
            'sub_events' => $subEvents,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $timelineClusters
     * @return array<int, array<string, mixed>>
     */
    private function detectTraditionEvents(array $timelineClusters): array {
        $groups = [];
        foreach ($timelineClusters as $cluster) {
            if (count($cluster['media']) < self::TRADITION_MIN_ITEMS || empty($cluster['location'])) {
                continue;
            }

            $person = $this->findDominantPersonCluster($cluster['media']);
            if ($person === null) {
                continue;
            }

            $month = (int)date('n', (int)$cluster['date_start']);
            $year = (int)date('Y', (int)$cluster['date_start']);
            $key = $this->slugify((string)$cluster['location']) . ':' . $month . ':' . $person['cluster_id'];
            $groups[$key]['years'][$year] = true;
            $groups[$key]['clusters'][] = [
                'cluster' => $cluster,
                'person' => $person,
                'year' => $year,
            ];
        }

        $events = [];
        foreach ($groups as $key => $group) {
            if (count($group['years']) < 2) {
                continue;
            }

            foreach ($group['clusters'] as $entry) {
                $cluster = $entry['cluster'];
                $person = $entry['person'];
                $events[] = $this->createDerivedEvent(
                    self::EVENT_KIND_TRADITION,
                    'tradition:' . $key . ':' . $entry['year'],
                    'Tradition with ' . $person['label'] . ' · ' . $cluster['location'] . ' · ' . $entry['year'],
                    $cluster['location'],
                    $cluster['media'],
                );
            }
        }

        return $events;
    }

    private function detectHomeArea(array $items): ?array {
        $counts = [];
        $areas = [];
        foreach ($items as $item) {
            $area = $this->tripAreaForItem($item);
            if ($area === null) {
                continue;
            }

            $counts[$area['key']] = ($counts[$area['key']] ?? 0) + 1;
            $areas[$area['key']] = $area;
        }

        if (empty($counts)) {
            return null;
        }

        arsort($counts);
        return $areas[array_key_first($counts)] ?? null;
    }

    private function tripAreaForItem(array $item): ?array {
        $country = trim((string)($item['country_name'] ?? ''));
        if ($country === '') {
            return null;
        }

        $region = trim((string)($item['region_name'] ?? ''));
        if (in_array($country, self::LARGE_COUNTRIES, true) && $region !== '') {
            return [
                'key' => 'region:' . $this->slugify($country . '-' . $region),
                'label' => $region,
                'country' => $country,
                'city' => trim((string)($item['city_name'] ?? '')),
            ];
        }

        return [
            'key' => 'country:' . $this->slugify($country),
            'label' => $country,
            'country' => $country,
            'city' => trim((string)($item['city_name'] ?? '')),
        ];
    }

    private function buildTripLabel(array $items, array $area, string $kind): string {
        if ($kind === self::EVENT_KIND_TRIP_SHORT) {
            $cityCounts = [];
            foreach ($items as $item) {
                $city = trim((string)($item['city_name'] ?? ''));
                if ($city !== '') {
                    $cityCounts[$city] = ($cityCounts[$city] ?? 0) + 1;
                }
            }
            if (!empty($cityCounts)) {
                arsort($cityCounts);
                return (string)array_key_first($cityCounts);
            }
        }

        return (string)$area['label'];
    }

    private function findDominantPersonCluster(array $media): ?array {
        $counts = [];
        $labels = [];
        foreach ($media as $item) {
            foreach ($item['person_clusters'] ?? [] as $person) {
                $clusterId = (int)($person['cluster_id'] ?? 0);
                if ($clusterId <= 0) {
                    continue;
                }
                $counts[$clusterId] = ($counts[$clusterId] ?? 0) + 1;
                $labels[$clusterId] = trim((string)($person['title'] ?? ''));
            }
        }

        if (empty($counts)) {
            return null;
        }

        arsort($counts);
        $clusterId = (int)array_key_first($counts);
        if ($counts[$clusterId] < max(3, (int)ceil(count($media) * 0.30))) {
            return null;
        }

        $label = trim((string)($labels[$clusterId] ?? ''));
        if ($label === '') {
            $label = 'Person ' . $clusterId;
        }

        return [
            'cluster_id' => $clusterId,
            'label' => $label,
        ];
    }

    private function selectMostSpecificTag(array $tagCounts): string {
        if (empty($tagCounts)) {
            return 'Animal';
        }

        uksort($tagCounts, function (string $a, string $b) use ($tagCounts): int {
            $sa = self::TAG_SPECIFICITY[$a] ?? 5;
            $sb = self::TAG_SPECIFICITY[$b] ?? 5;
            if ($sa !== $sb) {
                return $sb - $sa;
            }
            return $tagCounts[$b] <=> $tagCounts[$a];
        });

        return (string)array_key_first($tagCounts);
    }

    /**
     * @param array<int, array<string, mixed>> $media
     * @return array<string, mixed>
     */
    private function createDerivedEvent(string $kind, string $eventKey, string $title, ?string $location, array $media): array {
        usort($media, static fn(array $a, array $b): int => (int)$a['epoch'] <=> (int)$b['epoch']);

        return [
            'event_kind' => $kind,
            'event_key' => $eventKey,
            'title' => $title,
            'location' => $location,
            'date_start' => (int)$media[0]['epoch'],
            'date_end' => (int)$media[count($media) - 1]['epoch'],
            'media' => $media,
        ];
    }

    /**
     * @param array<string, mixed> $cluster
     * @return array<string, mixed>
     */
    private function createTimelineSubEvent(array $cluster, string $parentTripEventKey): array {
        $fileIds = array_values(array_unique(array_map(
            static fn(array $item): int => (int)$item['fileid'],
            $cluster['media']
        )));
        sort($fileIds, SORT_NUMERIC);

        $eventKey = 'trip-sub:' . $this->slugify($parentTripEventKey) . ':' . md5(implode(',', $fileIds));

        return [
            'event_kind' => self::EVENT_KIND_TIMELINE_SUB,
            'event_key' => $eventKey,
            'parent_event_key' => $parentTripEventKey,
            'title' => (string)$cluster['title'],
            'location' => $cluster['location'] ?? null,
            'date_start' => (int)$cluster['date_start'],
            'date_end' => (int)$cluster['date_end'],
            'media' => $cluster['media'],
        ];
    }

    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    // -------------------------------------------------------------------------
    // Step 3: Persist to Reel tables
    // -------------------------------------------------------------------------

    private function persistClustersIncremental(string $userId, array $clusters): void {
        $existing = $this->loadExistingEventsWithMedia($userId);
        $matchedEventIds = []; // keyed set for O(1) lookup
        $eventKeyToId = $this->buildExistingEventKeyMap($existing);

        foreach ($clusters as $cluster) {
            $cluster = $this->attachParentEventId($cluster, $eventKeyToId);
            $clusterFileIds = array_values(array_map(
                static fn(array $item): int => (int)$item['fileid'],
                $cluster['media']
            ));

            $eventId = $this->findBestExistingEventMatch($cluster, $clusterFileIds, $existing, $matchedEventIds);
            if ($eventId !== null) {
                $this->updateEventRow($eventId, $cluster);
                $this->syncEventMedia($eventId, $userId, $cluster['media']);
                $matchedEventIds[$eventId] = true;
                if (!empty($cluster['event_key'])) {
                    $eventKeyToId[(string)$cluster['event_key']] = $eventId;
                }
                continue;
            }

            $newEventId = $this->insertEventWithMedia($userId, $cluster);
            $matchedEventIds[$newEventId] = true;
            if (!empty($cluster['event_key'])) {
                $eventKeyToId[(string)$cluster['event_key']] = $newEventId;
            }
        }

        foreach ($existing as $eventId => $_event) {
            if (!isset($matchedEventIds[(int)$eventId])) {
                $this->deleteEvent((int)$eventId, $userId);
            }
        }
    }

    private function persistClustersRebuild(string $userId, array $clusters): void {
        $this->clearEventsForUser($userId);
        $eventKeyToId = [];

        foreach ($clusters as $cluster) {
            $cluster = $this->attachParentEventId($cluster, $eventKeyToId);
            $newEventId = $this->insertEventWithMedia($userId, $cluster);
            if (!empty($cluster['event_key'])) {
                $eventKeyToId[(string)$cluster['event_key']] = $newEventId;
            }
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
        $qb->select('e.id', 'e.date_start', 'e.date_end', 'e.location', 'e.event_kind', 'e.event_key', 'e.parent_event_id', 'm.file_id')
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
                    'event_kind' => $row['event_kind'] ?: self::EVENT_KIND_TIMELINE,
                    'event_key' => $row['event_key'] ?? null,
                    'parent_event_id' => $row['parent_event_id'] !== null ? (int)$row['parent_event_id'] : null,
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

    /**
     * @param array<int, array<string, mixed>> $existing
     * @return array<string, int>
     */
    private function buildExistingEventKeyMap(array $existing): array {
        $map = [];
        foreach ($existing as $event) {
            $eventKey = $event['event_key'] ?? null;
            if (is_string($eventKey) && $eventKey !== '') {
                $map[$eventKey] = (int)$event['id'];
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $cluster
     * @param array<string, int> $eventKeyToId
     * @return array<string, mixed>
     */
    private function attachParentEventId(array $cluster, array $eventKeyToId): array {
        $parentEventKey = $cluster['parent_event_key'] ?? null;
        if (!is_string($parentEventKey) || $parentEventKey === '') {
            $cluster['parent_event_id'] = null;
            return $cluster;
        }

        $cluster['parent_event_id'] = $eventKeyToId[$parentEventKey] ?? null;
        return $cluster;
    }

    private function findBestExistingEventMatch(array $cluster, array $clusterFileIds, array $existing, array $alreadyMatchedSet): ?int {
        $clusterKind = (string)($cluster['event_kind'] ?? self::EVENT_KIND_TIMELINE);
        $clusterKey = $cluster['event_key'] ?? null;

        if ($clusterKind !== self::EVENT_KIND_TIMELINE && is_string($clusterKey) && $clusterKey !== '') {
            foreach ($existing as $eventId => $event) {
                if (isset($alreadyMatchedSet[(int)$eventId])) {
                    continue;
                }
                if (($event['event_kind'] ?? self::EVENT_KIND_TIMELINE) === $clusterKind && ($event['event_key'] ?? null) === $clusterKey) {
                    return (int)$eventId;
                }
            }
            return null;
        }

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
            if (isset($alreadyMatchedSet[(int)$eventId])) {
                continue;
            }

            if (($event['event_kind'] ?? self::EVENT_KIND_TIMELINE) !== self::EVENT_KIND_TIMELINE) {
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
            ->set('parent_event_id', $cluster['parent_event_id'] === null
                ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
                : $qb->createNamedParameter((int)$cluster['parent_event_id'], IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function syncEventMedia(int $eventId, string $userId, array $clusterMedia): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'file_id', 'edit_settings')
            ->from('reel_event_media')
            ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $existingRows = $qb->executeQuery()->fetchAll();
        $existingByFileId = [];
        foreach ($existingRows as $row) {
            $existingByFileId[(int)$row['file_id']] = $row;
        }

        $seen      = [];
        $toDelete  = [];

        $this->db->beginTransaction();
        try {
            foreach ($clusterMedia as $order => $item) {
                $fileId = (int)$item['fileid'];
                $seen[$fileId] = true;

                if (isset($existingByFileId[$fileId])) {
                    $existing = $existingByFileId[$fileId];
                    $settings = !empty($existing['edit_settings'])
                        ? (json_decode((string)$existing['edit_settings'], true) ?? [])
                        : [];
                    $settings = $this->buildMediaSettings($userId, $item, $settings);
                    $this->emitMediaDebugLine($eventId, $fileId, $item, $settings);

                    $update = $this->db->getQueryBuilder();
                    $update->update('reel_event_media')
                        ->set('sort_order', $update->createNamedParameter($order, IQueryBuilder::PARAM_INT))
                        ->set('edit_settings', $update->createNamedParameter(json_encode($settings)))
                        ->where($update->expr()->eq('id', $update->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                    continue;
                }

                $insert = $this->db->getQueryBuilder();
                $settings = $this->buildMediaSettings($userId, $item);
                $this->emitMediaDebugLine($eventId, $fileId, $item, $settings);
                $insert->insert('reel_event_media')
                    ->values([
                        'event_id' => $insert->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
                        'user_id' => $insert->createNamedParameter($userId),
                        'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'included' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                        'sort_order' => $insert->createNamedParameter($order, IQueryBuilder::PARAM_INT),
                        'edit_settings' => $insert->createNamedParameter(json_encode($settings)),
                    ])
                    ->executeStatement();
            }

            foreach ($existingByFileId as $fileId => $rowId) {
                if (!isset($seen[$fileId])) {
                    $toDelete[] = $rowId;
                }
            }

            // Batch-delete removed rows in one query per chunk
            foreach (array_chunk($toDelete, 1000) as $chunk) {
                $delete = $this->db->getQueryBuilder();
                $delete->delete('reel_event_media')
                    ->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->executeStatement();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertEventWithMedia(string $userId, array $cluster): int {
        $now = time();
        $theme = $this->pickRandomTheme($userId);
        $eventKind = (string)($cluster['event_kind'] ?? self::EVENT_KIND_TIMELINE);
        $eventKey = $cluster['event_key'] ?? null;

        $qb = $this->db->getQueryBuilder();
        $qb->insert('reel_events')
            ->values([
                'user_id'    => $qb->createNamedParameter($userId),
                'event_kind' => $qb->createNamedParameter($eventKind),
                'event_key'  => $qb->createNamedParameter($eventKey),
                'title'      => $qb->createNamedParameter($cluster['title']),
                'date_start' => $qb->createNamedParameter($cluster['date_start'], IQueryBuilder::PARAM_INT),
                'date_end'   => $qb->createNamedParameter($cluster['date_end'], IQueryBuilder::PARAM_INT),
                'location'   => $qb->createNamedParameter($cluster['location']),
                'parent_event_id' => $cluster['parent_event_id'] === null
                    ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
                    : $qb->createNamedParameter((int)$cluster['parent_event_id'], IQueryBuilder::PARAM_INT),
                'theme'      => $qb->createNamedParameter($theme),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        $eventId = (int)$this->db->lastInsertId('oc_reel_events');

        $this->db->beginTransaction();
        try {
            foreach ($cluster['media'] as $order => $item) {
                $settings = $this->buildMediaSettings($userId, $item);
                $this->emitMediaDebugLine($eventId, (int)$item['fileid'], $item, $settings);
                $mqb = $this->db->getQueryBuilder();
                $mqb->insert('reel_event_media')
                    ->values([
                        'event_id' => $mqb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
                        'user_id' => $mqb->createNamedParameter($userId),
                        'file_id' => $mqb->createNamedParameter((int)$item['fileid'], IQueryBuilder::PARAM_INT),
                        'included' => $mqb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                        'sort_order' => $mqb->createNamedParameter($order, IQueryBuilder::PARAM_INT),
                        'edit_settings' => $mqb->createNamedParameter(json_encode($settings)),
                    ])
                    ->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $utilityExcluded = $this->utilityFilter->filterEvent($eventId, $userId, $this->debugCallback);
        if ($utilityExcluded > 0) {
            $this->logger->debug('Reel: excluded {n} utility media from new event {id}', [
                'n' => $utilityExcluded,
                'id' => $eventId,
            ]);
        }

        // Duplicate suppression is useful for all event kinds.
        $excluded = $this->duplicateFilter->filterEvent($eventId, $userId, $this->debugCallback);
        if ($excluded > 0) {
            $this->logger->debug('Reel: excluded {n} duplicates from new event {id}', [
                'n' => $excluded,
                'id' => $eventId,
            ]);
        }

        $distinctExcluded = $this->distinctFilter->filterEvent($eventId, $userId, $this->debugCallback);
        if ($distinctExcluded > 0) {
            $this->logger->debug('Reel: excluded {n} low-distinct media from new event {id}', [
                'n' => $distinctExcluded,
                'id' => $eventId,
            ]);
        }

        return $eventId;
    }

    private function pickRandomTheme(string $userId): string {
        $options = $this->musicService->getMusicOptions($userId);
        if (empty($options)) {
            return 'indie_pop';
        }
        return $options[random_int(0, count($options) - 1)]['value'];
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
