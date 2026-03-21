<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Encapsulates all direct SQL access to Memories and filecache-related tables.
 */
class MemoriesRepository {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * Returns all media rows for a user, enriched with place name and sorted by epoch.
     */
    public function loadMediaForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'm.fileid',
                'm.epoch',
                'm.datetaken',
                'm.isvideo',
                'm.liveid',
                'm.lat',
                'm.lon',
                'm.video_duration',
            'fc.path',
            )
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'fc', $qb->expr()->eq('m.fileid', 'fc.fileid'))
            ->innerJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
            ->where($qb->expr()->eq('st.id', $qb->createNamedParameter('home::' . $userId)))
            ->andWhere($qb->expr()->eq('m.orphan', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.epoch', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return [];
        }

        $placeMap = $this->loadPlaceNames(array_column($rows, 'fileid'));
        foreach ($rows as &$row) {
            $row['place_name'] = $placeMap[$row['fileid']] ?? null;
        }
        unset($row);

        return $rows;
    }

    public function loadStillDimensions(int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('w', 'h')
            ->from('memories')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $row = $qb->executeQuery()->fetch();
        if (!$row) {
            return null;
        }

        return [
            'w' => (int)$row['w'],
            'h' => (int)$row['h'],
        ];
    }

    /**
     * Find the video sibling of a live photo still.
     * Prefer matching by Memories liveid, then fall back to sibling filename swap.
     */
    public function findLiveVideoFileId(int $stillFileId): ?int {
        $qbLive = $this->db->getQueryBuilder();
        $qbLive->select('liveid')
            ->from('memories')
            ->where($qbLive->expr()->eq('fileid', $qbLive->createNamedParameter($stillFileId, IQueryBuilder::PARAM_INT)));

        $stillMem = $qbLive->executeQuery()->fetch();
        $liveId = is_array($stillMem) ? trim((string)($stillMem['liveid'] ?? '')) : '';

        if ($liveId !== '') {
            $qbMatch = $this->db->getQueryBuilder();
            $qbMatch->select('fileid')
                ->from('memories')
                ->where($qbMatch->expr()->eq('liveid', $qbMatch->createNamedParameter($liveId)))
                ->andWhere($qbMatch->expr()->eq('isvideo', $qbMatch->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->andWhere($qbMatch->expr()->neq('fileid', $qbMatch->createNamedParameter($stillFileId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $video = $qbMatch->executeQuery()->fetch();
            if ($video) {
                return (int)$video['fileid'];
            }
        }

        // Fallback for databases without usable liveid metadata.
        $qb = $this->db->getQueryBuilder();
        $qb->select('name', 'parent')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($stillFileId, IQueryBuilder::PARAM_INT)));

        $still = $qb->executeQuery()->fetch();
        if (!$still) {
            return null;
        }

        $baseName = pathinfo((string)$still['name'], PATHINFO_FILENAME);
        if ($baseName === '') {
            return null;
        }

        $candidateNames = [
            $baseName . '.mov',
            $baseName . '.MOV',
            $baseName . '.mp4',
            $baseName . '.MP4',
        ];

        $qb2 = $this->db->getQueryBuilder();
        $qb2->select('fileid')
            ->from('filecache')
            ->where($qb2->expr()->eq('parent', $qb2->createNamedParameter((int)$still['parent'], IQueryBuilder::PARAM_INT)))
            ->andWhere($qb2->expr()->in('name', $qb2->createNamedParameter($candidateNames, IQueryBuilder::PARAM_STR_ARRAY)))
            ->setMaxResults(1);

        $result = $qb2->executeQuery()->fetch();
        return $result ? (int)$result['fileid'] : null;
    }

    /**
     * Returns best face focus point per file as normalized coordinates.
     *
     * Output shape: [fileId => ['fx' => float, 'fy' => float]]
     */
    public function loadPrimaryFaceFocuses(array $fileIds, string $userId): array {
        if (empty($fileIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'x', 'y', 'width', 'height')
            ->from('recognize_face_detections')
            ->where($qb->expr()->in(
                'file_id',
                $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $focus = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $fileId = (int)$row['file_id'];
            $w = (float)$row['width'];
            $h = (float)$row['height'];
            $fx = (float)$row['x'] + ($w / 2.0);
            $fy = (float)$row['y'] + ($h / 2.0);

            $centrality = max(0.0, 1.0 - (2.0 * sqrt(($fx - 0.5) ** 2 + ($fy - 0.5) ** 2)));
            $score = ($w * $h) * (0.7 + (0.3 * $centrality));

            if (!isset($focus[$fileId]) || $score > $focus[$fileId]['score']) {
                $focus[$fileId] = [
                    'fx' => $fx,
                    'fy' => $fy,
                    'score' => $score,
                ];
            }
        }

        foreach ($focus as $fileId => $value) {
            unset($focus[$fileId]['score']);
        }

        return $focus;
    }

    /**
     * Returns normalized face detections grouped by file id.
     *
     * Output shape:
     * [
     *   fileId => [
     *     ['fx' => float, 'fy' => float, 'w' => float, 'h' => float],
     *     ...
     *   ],
     * ]
     */
    public function loadFaceDetections(array $fileIds, string $userId): array {
        if (empty($fileIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'x', 'y', 'width', 'height')
            ->from('recognize_face_detections')
            ->where($qb->expr()->in(
                'file_id',
                $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $fileId = (int)$row['file_id'];
            $w = max(0.0, min(1.0, (float)$row['width']));
            $h = max(0.0, min(1.0, (float)$row['height']));
            $fx = max(0.0, min(1.0, (float)$row['x'] + ($w / 2.0)));
            $fy = max(0.0, min(1.0, (float)$row['y'] + ($h / 2.0)));
            $result[$fileId][] = [
                'fx' => $fx,
                'fy' => $fy,
                'w' => $w,
                'h' => $h,
            ];
        }

        return $result;
    }

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
                ->orderBy('pl.admin_level', 'DESC');

            $result = $qb->executeQuery();
            $rows = array_merge($rows, $result->fetchAll());
            $result->closeCursor();
        }

        $placeMap = [];
        foreach ($rows as $row) {
            $fid = (int)$row['fileid'];
            $level = (int)$row['admin_level'];
            if (!isset($placeMap[$fid]) || $level > $placeMap[$fid]['admin_level']) {
                $placeMap[$fid] = [
                    'name' => $row['name'],
                    'admin_level' => $level,
                ];
            }
        }

        return array_map(static fn(array $value): string => $value['name'], $placeMap);
    }

    /**
     * Returns systemtag names (from Recognize or manual) grouped by file id.
     * objectid is stored as VARCHAR so file ids are cast to strings for the query.
     *
     * Output shape: [fileId => ['TagName', ...]]
     *
     * @param int[] $fileIds
     * @return array<int, list<string>>
     */
    public function loadTagsForFiles(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        $tags = [];
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            $stringIds = array_map('strval', $chunk);
            $qb = $this->db->getQueryBuilder();
            $qb->select('m.objectid', 't.name')
                ->from('systemtag_object_mapping', 'm')
                ->innerJoin('m', 'systemtag', 't', $qb->expr()->eq('t.id', 'm.systemtagid'))
                ->where($qb->expr()->eq('m.objecttype', $qb->createNamedParameter('files')))
                ->andWhere($qb->expr()->in(
                    'm.objectid',
                    $qb->createNamedParameter($stringIds, IQueryBuilder::PARAM_STR_ARRAY),
                ));

            foreach ($qb->executeQuery()->fetchAll() as $row) {
                $tags[(int)$row['objectid']][] = (string)$row['name'];
            }
        }

        return $tags;
    }

    /**
     * Returns face clusters grouped by file id.
     * Files may contain multiple people; cluster_id <= 0 is ignored.
     *
     * Output shape:
     * [
     *   fileId => [
     *     ['cluster_id' => int, 'title' => string],
     *     ...
     *   ],
     * ]
     *
     * @param int[] $fileIds
     * @return array<int, list<array{cluster_id: int, title: string}>>
     */
    public function loadFaceClustersForFiles(array $fileIds, string $userId): array {
        if (empty($fileIds)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('d.file_id', 'd.cluster_id', 'c.title')
                ->from('recognize_face_detections', 'd')
                ->leftJoin(
                    'd',
                    'recognize_face_clusters',
                    'c',
                    $qb->expr()->andX(
                        $qb->expr()->eq('c.id', 'd.cluster_id'),
                        $qb->expr()->eq('c.user_id', 'd.user_id'),
                    )
                )
                ->where($qb->expr()->in('d.file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->gt('d.cluster_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

            $seen = [];
            foreach ($qb->executeQuery()->fetchAll() as $row) {
                $fileId = (int)$row['file_id'];
                $clusterId = (int)$row['cluster_id'];
                $dedupeKey = $fileId . ':' . $clusterId;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $result[$fileId][] = [
                    'cluster_id' => $clusterId,
                    'title' => trim((string)($row['title'] ?? '')),
                ];
            }
        }

        return $result;
    }

    /**
     * Returns place hierarchy grouped by file id.
     *
     * Output shape:
     * [
     *   fileId => [
     *     'country' => ?string,
     *     'region' => ?string,
     *     'city' => ?string,
     *     'timezone' => ?string,
     *   ],
     * ]
     *
     * @param int[] $fileIds
     * @return array<int, array{country: ?string, region: ?string, city: ?string, timezone: ?string}>
     */
    public function loadPlaceHierarchyForFiles(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('mp.fileid', 'pl.name', 'pl.admin_level')
                ->from('memories_places', 'mp')
                ->innerJoin('mp', 'memories_planet', 'pl', $qb->expr()->eq('mp.osm_id', 'pl.osm_id'))
                ->where($qb->expr()->in('mp.fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('mp.fileid', 'ASC')
                ->addOrderBy('pl.admin_level', 'ASC');

            $query = $qb->executeQuery();
            $rows = array_merge($rows, $query->fetchAll());
            $query->closeCursor();
        }

        $hierarchy = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['fileid'];
            $name = trim((string)$row['name']);
            $level = (int)$row['admin_level'];
            if ($name === '') {
                continue;
            }

            $hierarchy[$fileId] ??= [
                'country' => null,
                'region' => null,
                'city' => null,
                'timezone' => null,
            ];

            if ($level === -7 && $hierarchy[$fileId]['timezone'] === null) {
                $hierarchy[$fileId]['timezone'] = $name;
                continue;
            }
            if ($level === 2 && $hierarchy[$fileId]['country'] === null) {
                $hierarchy[$fileId]['country'] = $name;
                continue;
            }
            if ($level === 4 && $hierarchy[$fileId]['region'] === null) {
                $hierarchy[$fileId]['region'] = $name;
                continue;
            }
            if ($level >= 8) {
                $hierarchy[$fileId]['city'] = $name;
            }
        }

        return $hierarchy;
    }
}
