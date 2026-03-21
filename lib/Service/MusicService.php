<?php

declare(strict_types=1);

namespace OCA\Reel\Service;

use OCA\Reel\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class MusicService {

    private const SUPPORTED_EXTENSIONS = ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'opus'];

    public function __construct(
        private IConfig $config,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<int, array{value: string, label: string, kind: string, genre?: string}>
     */
    public function getMusicOptions(string $userId): array {
        $options = [
            ['value' => 'indie_pop', 'label' => 'Indie Pop (random)', 'kind' => 'genre', 'genre' => 'indie_pop'],
            ['value' => 'acoustic_folk', 'label' => 'Acoustic Folk (random)', 'kind' => 'genre', 'genre' => 'acoustic_folk'],
            ['value' => 'cinematic_orchestral', 'label' => 'Cinematic Orchestral (random)', 'kind' => 'genre', 'genre' => 'cinematic_orchestral'],
        ];

        foreach ($this->getCachedCustomMusicFiles($userId) as $custom) {
            $options[] = [
                'value' => 'custom:' . $custom['relativePath'],
                'label' => $custom['label'],
                'kind' => 'custom',
            ];
        }

        return $options;
    }

    public function getCustomMusicFolderPath(string $userId): ?string {
        $value = trim((string)$this->config->getUserValue(
            $userId,
            Application::APP_ID,
            'custom_music_folder',
            '',
        ));

        return $value === '' ? null : $value;
    }

    /**
     * @throws \RuntimeException
     */
    public function setCustomMusicFolderPath(string $userId, ?string $folderPath): void {
        $clean = trim((string)$folderPath);
        if ($clean === '') {
            $this->config->deleteUserValue($userId, Application::APP_ID, 'custom_music_folder');
            $this->config->deleteUserValue($userId, Application::APP_ID, 'custom_music_files_cache');
            return;
        }

        $folder = $this->resolveUserFolder($userId, $clean);
        if ($folder === null) {
            throw new \RuntimeException('Folder not found');
        }

        $this->config->setUserValue($userId, Application::APP_ID, 'custom_music_folder', $clean);
        $this->refreshCache($userId);
    }

    /**
     * Scan the custom music folder and persist the file list to user config.
     * Called when the folder is saved and by the nightly background job.
     */
    public function refreshCache(string $userId): void {
        $base = $this->getCustomMusicFolderPath($userId);
        if ($base === null) {
            $this->config->deleteUserValue($userId, Application::APP_ID, 'custom_music_files_cache');
            return;
        }

        $files = $this->listCustomMusicFiles($userId);
        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'custom_music_files_cache',
            json_encode($files, JSON_THROW_ON_ERROR),
        );
    }

    public function getRandomBundledMusicFile(string $genre): ?string {
        $candidates = match ($genre) {
            'acoustic_folk' => glob($this->bundledMusicDir() . '/Acoustic Folk song *.mp3') ?: [],
            'cinematic_orchestral' => glob($this->bundledMusicDir() . '/Cinematic Orchestral song *.mp3') ?: [],
            default => glob($this->bundledMusicDir() . '/Indie Pop song *.mp3') ?: [],
        };

        if (empty($candidates)) {
            return null;
        }

        return (string)$candidates[random_int(0, count($candidates) - 1)];
    }

    public function resolveCustomMusicFilePath(string $userId, string $relativePath): ?string {
        $base = $this->getCustomMusicFolderPath($userId);
        if ($base === null) {
            return null;
        }

        $relativePath = trim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $folder = $this->resolveUserFolder($userId, $base);
        if ($folder === null) {
            return null;
        }

        try {
            $node = $folder->get($relativePath);
        } catch (\Throwable) {
            return null;
        }

        if ($node->getType() !== Node::TYPE_FILE) {
            return null;
        }

        if (!$this->isSupportedExtension($node->getName())) {
            return null;
        }

        $storage = $node->getStorage();
        if (!$storage->isLocal()) {
            return null;
        }

        $localPath = $storage->getLocalFile($node->getInternalPath());
        if (!is_string($localPath) || $localPath === '' || !file_exists($localPath)) {
            return null;
        }

        return $localPath;
    }

    /**
     * @return array<int, array{relativePath: string, label: string}>
     */
    private function getCachedCustomMusicFiles(string $userId): array {
        $json = $this->config->getUserValue($userId, Application::APP_ID, 'custom_music_files_cache', '');
        if ($json === '') {
            return [];
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @return array<int, array{relativePath: string, label: string}>
     */
    private function listCustomMusicFiles(string $userId): array {
        $base = $this->getCustomMusicFolderPath($userId);
        if ($base === null) {
            return [];
        }

        $folder = $this->resolveUserFolder($userId, $base);
        if ($folder === null) {
            return [];
        }

        $results = [];
        $this->scanFolderRecursive($folder, '', $results);

        usort(
            $results,
            static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label'])
        );

        return $results;
    }

    /**
     * @param array<int, array{relativePath: string, label: string}> $results
     */
    private function scanFolderRecursive(Folder $folder, string $prefix, array &$results): void {
        try {
            $children = $folder->getDirectoryListing();
        } catch (\Throwable $e) {
            $this->logger->debug('Reel: could not list custom music folder: {msg}', ['msg' => $e->getMessage()]);
            return;
        }

        foreach ($children as $child) {
            if ($child->getType() === Node::TYPE_FOLDER) {
                $nextPrefix = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
                $this->scanFolderRecursive($child, $nextPrefix, $results);
                continue;
            }

            if ($child->getType() !== Node::TYPE_FILE || !$this->isSupportedExtension($child->getName())) {
                continue;
            }

            $relativePath = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
            $results[] = [
                'relativePath' => $relativePath,
                'label' => $relativePath,
            ];
        }
    }

    private function resolveUserFolder(string $userId, string $path): ?Folder {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node = $userFolder->get(trim($path, '/'));
            return $node->getType() === Node::TYPE_FOLDER ? $node : null;
        } catch (\Throwable $e) {
            $this->logger->debug('Reel: custom music folder lookup failed: {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function isSupportedExtension(string $filename): bool {
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    private function bundledMusicDir(): string {
        return dirname(__DIR__, 2) . '/assets/music';
    }
}
