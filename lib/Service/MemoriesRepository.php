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
     * Find the .mov sibling of a live photo still via name-swap in filecache.
     */
    public function findLiveVideoFileId(int $stillFileId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fc2.fileid')
            ->from('filecache', 'fc1')
            ->join('fc1', 'filecache', 'fc2',
                $qb->expr()->andX(
                    $qb->expr()->eq('fc2.parent', 'fc1.parent'),
                    $qb->expr()->eq(
                        'fc2.name',
                        $qb->createFunction(
                            "CONCAT(LEFT(fc1.name, LENGTH(fc1.name) - 4), '.mov')"
                        )
                    )
                )
            )
            ->where($qb->expr()->eq('fc1.fileid', $qb->createNamedParameter($stillFileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery()->fetch();
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
}
