<?php

declare(strict_types=1);

namespace OCA\Reel\Controller;

use OCA\Reel\AppInfo\Application;
use OCA\Reel\Service\MusicService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends OCSController {

    // Defaults — must match DuplicateFilterService constants
    private const DEFAULT_SIMILARITY_THRESHOLD = 16;
    private const DEFAULT_BURST_GAP_SECONDS    = 5;
    private const DEFAULT_OUTPUT_ORIENTATION   = 'landscape_16_9';
    private const DEFAULT_AUTO_CREATE_VIDEOS   = '0';

    public function __construct(
        string          $appName,
        IRequest        $request,
        private IConfig $config,
        private MusicService $musicService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    private function requireUserId(): ?DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }
        return null;
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/settings')]
    public function getSettings(): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;

        return new DataResponse([
            'similarity_threshold' => (int)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'similarity_threshold',
                (string)self::DEFAULT_SIMILARITY_THRESHOLD,
            ),
            'burst_gap_seconds' => (int)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'burst_gap_seconds',
                (string)self::DEFAULT_BURST_GAP_SECONDS,
            ),
            'output_orientation' => $this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'output_orientation',
                self::DEFAULT_OUTPUT_ORIENTATION,
            ),
            'custom_music_folder' => $this->musicService->getCustomMusicFolderPath($this->userId),
            'auto_create_videos' => $this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'auto_create_videos',
                self::DEFAULT_AUTO_CREATE_VIDEOS,
            ) === '1',
        ]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/settings')]
    public function updateSettings(
        ?int    $similarity_threshold = null,
        ?int    $burst_gap_seconds    = null,
        ?string $output_orientation   = null,
        ?string $custom_music_folder  = null,
        ?bool   $auto_create_videos   = null,
    ): DataResponse {
        if ($guard = $this->requireUserId()) return $guard;

        if ($similarity_threshold !== null) {
            $similarity_threshold = max(1, min(30, $similarity_threshold));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'similarity_threshold',
                (string)$similarity_threshold,
            );
        }

        if ($burst_gap_seconds !== null) {
            $burst_gap_seconds = max(1, min(30, $burst_gap_seconds));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'burst_gap_seconds',
                (string)$burst_gap_seconds,
            );
        }

        if ($output_orientation !== null) {
            $allowed = ['landscape_16_9', 'portrait_9_16', 'square_1_1'];
            if (in_array($output_orientation, $allowed, true)) {
                $this->config->setUserValue(
                    $this->userId,
                    Application::APP_ID,
                    'output_orientation',
                    $output_orientation,
                );
            }
        }

        if ($custom_music_folder !== null) {
            try {
                $this->musicService->setCustomMusicFolderPath($this->userId, $custom_music_folder);
            } catch (\Throwable $e) {
                return new DataResponse(['error' => 'Invalid custom music folder'], 400);
            }
        }

        if ($auto_create_videos !== null) {
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'auto_create_videos',
                $auto_create_videos ? '1' : '0',
            );
        }

        return $this->getSettings();
    }
}
