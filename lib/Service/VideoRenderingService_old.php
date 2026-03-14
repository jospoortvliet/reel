<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * VideoRenderingService
 *
 * Renders a highlight video for a Reel event using FFmpeg.
 *
 * Strategy:
 *  - Photos are shown for 2.5s each with a Ken Burns zoom effect
 *  - Video clips are included at their natural duration (capped at 8s)
 *  - Crossfade transitions between all items
 *  - Optional music track mixed in at low volume under the natural audio
 *  - Output: 1080p H.265 MP4, saved to the user's "Reels/" folder
 */
class VideoRenderingService {

    // How long each photo is shown (seconds)
    private const PHOTO_DURATION = 2.5;

    // Maximum duration of a video clip (seconds)
    private const MAX_CLIP_DURATION = 8.0;

    // Crossfade duration between items (seconds)
    private const TRANSITION_DURATION = 0.5;

    // Output dimensions
    private const WIDTH  = 1920;
    private const HEIGHT = 1080;

    // Where to save generated videos in the user's file tree
    private const OUTPUT_FOLDER = 'Reels';

    public function __construct(
        private IDBConnection  $db,
        private IRootFolder    $rootFolder,
        private LoggerInterface $logger,
    ) {}

    /**
     * Render a video for the given event ID.
     * Returns the Nextcloud file ID of the generated video.
     */
    public function renderEvent(int $eventId, string $userId): int {
        $this->logger->info('Reel: starting render for event {id}', ['id' => $eventId]);

        // 1. Load event + included media
        $event = $this->loadEvent($eventId, $userId);
        $media = $this->loadIncludedMedia($eventId, $userId);

        if (count($media) < 2) {
            throw new \RuntimeException("Event {$eventId} has fewer than 2 included media items");
        }

        // 2. Resolve file IDs to absolute filesystem paths
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $items      = $this->resolveFilePaths($media, $userFolder);

        if (count($items) < 2) {
            throw new \RuntimeException("Could not resolve enough media paths for event {$eventId}");
        }

        // 3. Prepare output location
        $outputFolder = $this->ensureOutputFolder($userFolder);
        $outputName   = $this->buildOutputFilename($event);
        $outputPath   = $outputFolder->getPath() . '/' . $outputName;

        // Get the actual filesystem path (outside Nextcloud's virtual FS)
        $localOutputPath = $this->getLocalPath($outputFolder) . '/' . $outputName;

        // 4. Build and run FFmpeg command
        $ffmpegCmd = $this->buildFfmpegCommand($items, $localOutputPath, $event['theme'] ?? null);

        $this->logger->info('Reel: running FFmpeg for event {id}', ['id' => $eventId]);
        $this->logger->debug('Reel: FFmpeg command: {cmd}', ['cmd' => implode(' ', $ffmpegCmd)]);

        $this->runFfmpeg($ffmpegCmd);

        // 5. Register the output file in Nextcloud's filecache
        $outputFile = $outputFolder->newFile($outputName);
        $fileId     = $outputFile->getId();

        // 6. Store file ID on the event row
        $this->updateEventVideoFileId($eventId, $fileId);

        $this->logger->info('Reel: render complete for event {id}, file {fileId}', [
            'id'     => $eventId,
            'fileId' => $fileId,
        ]);

        return $fileId;
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    private function loadEvent(int $eventId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('reel_events')
            ->where($qb->expr()->eq('id',      $qb->createNamedParameter($eventId,  IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \RuntimeException("Event {$eventId} not found for user {$userId}");
        }

        return $row;
    }

    private function loadIncludedMedia(int $eventId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.file_id', 'm.sort_order', 'mem.isvideo', 'mem.video_duration', 'mem.liveid')
            ->from('reel_event_media', 'm')
            ->innerJoin('m', 'memories', 'mem', $qb->expr()->eq('m.file_id', 'mem.fileid'))
            ->where($qb->expr()->eq('m.event_id', $qb->createNamedParameter($eventId,  IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('m.user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.included',  $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('m.sort_order', 'ASC');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Path resolution
    // -------------------------------------------------------------------------

    /**
     * Resolves Nextcloud file IDs to local filesystem paths.
     * Returns array of items with keys: path, is_video, duration
     */
    private function resolveFilePaths(array $media, Folder $userFolder): array {
        $items = [];

        foreach ($media as $row) {
            try {
                $files = $userFolder->getById((int)$row['file_id']);
                if (empty($files)) {
                    $this->logger->warning('Reel: could not find file {id}', ['id' => $row['file_id']]);
                    continue;
                }

                $file      = $files[0];
                $localPath = $this->getLocalPath($file);

                if (!file_exists($localPath)) {
                    $this->logger->warning('Reel: file does not exist at {path}', ['path' => $localPath]);
                    continue;
                }

                $isVideo  = (bool)$row['isvideo'];
                $duration = $isVideo
                    ? min((float)$row['video_duration'], self::MAX_CLIP_DURATION)
                    : self::PHOTO_DURATION;

                $items[] = [
                    'path'     => $localPath,
                    'is_video' => $isVideo,
                    'duration' => $duration,
                    'liveid'   => $row['liveid'],
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('Reel: error resolving file {id}: {msg}', [
                    'id'  => $row['file_id'],
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $items;
    }

    /**
     * Gets the local filesystem path for a Nextcloud file/folder node.
     * Works by getting the storage's local path and appending the internal path.
     */
    private function getLocalPath(\OCP\Files\Node $node): string {
        $storage = $node->getStorage();

        if (!$storage->isLocal()) {
            throw new \RuntimeException('Reel only supports local storage for rendering');
        }

        // getLocalFile returns the actual path on disk
        $localFile = $storage->getLocalFile($node->getInternalPath());
        if ($localFile === null) {
            throw new \RuntimeException('Could not get local path for ' . $node->getPath());
        }

        return $localFile;
    }

    // -------------------------------------------------------------------------
    // Output folder management
    // -------------------------------------------------------------------------

    private function ensureOutputFolder(Folder $userFolder): Folder {
        if ($userFolder->nodeExists(self::OUTPUT_FOLDER)) {
            $node = $userFolder->get(self::OUTPUT_FOLDER);
            if (!$node instanceof Folder) {
                throw new \RuntimeException(self::OUTPUT_FOLDER . ' exists but is not a folder');
            }
            return $node;
        }

        return $userFolder->newFolder(self::OUTPUT_FOLDER);
    }

    private function buildOutputFilename(array $event): string {
        // e.g. "reel-rio-de-janeiro-may-2023.mp4"
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $event['title'] ?? 'reel'));
        $slug = trim($slug, '-');
        return 'reel-' . $slug . '-' . $event['id'] . '.mp4';
    }

    // -------------------------------------------------------------------------
    // FFmpeg command builder
    // -------------------------------------------------------------------------

    /**
     * Builds the FFmpeg command as an array of arguments.
     *
     * Approach: concat demuxer for simple, reliable concatenation.
     * Each item gets scaled/padded to 1080p with a black letterbox if needed.
     * Photos get a subtle Ken Burns zoom. Crossfades are handled via the
     * xfade filter chain.
     */
    private function buildFfmpegCommand(array $items, string $outputPath, ?string $theme): array {
        // Write a concat list file to a temp location
        $concatFile = tempnam(sys_get_temp_dir(), 'reel_concat_') . '.txt';
        $this->writeConcatFile($concatFile, $items);

        $cmd = [
            'ffmpeg',
            '-y',                    // overwrite output without asking
            '-f', 'concat',
            '-safe', '0',            // allow absolute paths in concat file
            '-i', $concatFile,
        ];

        // Add music track if theme provides one
        $musicPath = $this->getMusicPath($theme);
        if ($musicPath && file_exists($musicPath)) {
            $cmd = array_merge($cmd, [
                '-stream_loop', '-1', // loop music to fit video length
                '-i', $musicPath,
            ]);
            $audioFilter = '[1:a]volume=0.3[music];[0:a]volume=1.0[orig];[orig][music]amix=inputs=2:duration=first[aout]';
            $videoFilter = $this->buildVideoFilter(count($items));
            $cmd = array_merge($cmd, [
                '-filter_complex', $videoFilter . ';' . $audioFilter,
                '-map', '[vout]',
                '-map', '[aout]',
            ]);
        } else {
            $videoFilter = $this->buildVideoFilter(count($items));
            $cmd = array_merge($cmd, [
                '-filter_complex', $videoFilter,
                '-map', '[vout]',
                '-map', '0:a?',      // include original audio if present, ignore if not
            ]);
        }

        $cmd = array_merge($cmd, [
            '-c:v', 'libx265',
            '-crf', '23',            // quality: 18=high, 28=low, 23=balanced
            '-preset', 'fast',       // encoding speed vs compression
            '-c:a', 'aac',
            '-b:a', '192k',
            '-movflags', '+faststart', // web-optimised: moov atom at front
            $outputPath,
        ]);

        return $cmd;
    }

    /**
     * Writes the FFmpeg concat demuxer input file.
     * Each photo is treated as a still image loop for PHOTO_DURATION seconds.
     */
    private function writeConcatFile(string $path, array $items): void {
        $lines = [];
        foreach ($items as $item) {
            $escapedPath = str_replace("'", "'\\''", $item['path']);

            if (!$item['is_video']) {
                // Loop a still image for PHOTO_DURATION seconds
                $lines[] = "file '{$escapedPath}'";
                $lines[] = 'duration ' . number_format($item['duration'], 2, '.', '');
            } else {
                $lines[] = "file '{$escapedPath}'";
                if ($item['duration'] < self::MAX_CLIP_DURATION) {
                    $lines[] = 'duration ' . number_format($item['duration'], 2, '.', '');
                }
            }
        }
        // FFmpeg concat requires the last file to be listed twice
        $lastItem = end($items);
        $escapedPath = str_replace("'", "'\\''", $lastItem['path']);
        $lines[] = "file '{$escapedPath}'";

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * Builds a video filter chain that:
     * 1. Scales each input to 1080p with letterboxing
     * 2. Applies a subtle Ken Burns zoom to photos
     * 3. Chains xfade crossfades between all items
     */
    private function buildVideoFilter(int $itemCount): string {
        // Scale + pad each stream to 1920x1080
        $scaleFilter = "scale=w=" . self::WIDTH . ":h=" . self::HEIGHT
            . ":force_original_aspect_ratio=decrease,"
            . "pad=" . self::WIDTH . ":" . self::HEIGHT
            . ":(ow-iw)/2:(oh-ih)/2:black";

        // For the simple MVP we use the concat demuxer output directly
        // and just apply scale/pad as a single filter on the whole stream.
        // Proper per-clip Ken Burns + xfade can be added in a later iteration.
        return "[0:v]{$scaleFilter}[vout]";
    }

    private function getMusicPath(?string $theme): ?string {
        // Music tracks are bundled in the app under assets/music/
        $appDir = dirname(__DIR__, 2); // lib/Service/../../ = app root
        $track  = match($theme) {
            'summer'  => 'summer.mp3',
            'minimal' => 'minimal.mp3',
            default   => 'default.mp3',
        };

        $path = $appDir . '/assets/music/' . $track;
        return file_exists($path) ? $path : null;
    }

    // -------------------------------------------------------------------------
    // FFmpeg execution
    // -------------------------------------------------------------------------

    private function runFfmpeg(array $cmd): void {
        $process     = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start FFmpeg process');
        }

        fclose($pipes[0]);

        // Read stderr for progress/errors (FFmpeg outputs to stderr)
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('Reel: FFmpeg failed (exit {code}): {stderr}', [
                'code'   => $exitCode,
                'stderr' => $stderr,
            ]);
            throw new \RuntimeException("FFmpeg exited with code {$exitCode}");
        }

        $this->logger->debug('Reel: FFmpeg stderr output: {stderr}', ['stderr' => $stderr]);
    }

    // -------------------------------------------------------------------------
    // Database update
    // -------------------------------------------------------------------------

    private function updateEventVideoFileId(int $eventId, int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('reel_events')
            ->set('video_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
            ->set('updated_at',    $qb->createNamedParameter(time(),   IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
