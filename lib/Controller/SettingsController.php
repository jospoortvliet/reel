<?php

declare(strict_types=1);

namespace OCA\Reel\Controller;

use OCA\Reel\AppInfo\Application;
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
    private const DEFAULT_MOTION_STYLE = 'classic';
    private const DEFAULT_PAN_MISMATCH_THRESHOLD = 1.40;
    private const DEFAULT_PAN_SWEEP_MARGIN = 0.08;
    private const DEFAULT_PAN_PANORAMA_SWEEP_MARGIN = 0.02;
    private const DEFAULT_FACE_ZOOM_TARGET_FILL = 0.75;
    private const DEFAULT_NON_FACE_ZOOM_END = 1.42;

    public function __construct(
        string          $appName,
        IRequest        $request,
        private IConfig $config,
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
            'motion_style' => $this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'motion_style',
                self::DEFAULT_MOTION_STYLE,
            ),
            'pan_mismatch_threshold' => (float)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'pan_mismatch_threshold',
                (string)self::DEFAULT_PAN_MISMATCH_THRESHOLD,
            ),
            'pan_sweep_margin' => (float)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'pan_sweep_margin',
                (string)self::DEFAULT_PAN_SWEEP_MARGIN,
            ),
            'pan_panorama_sweep_margin' => (float)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'pan_panorama_sweep_margin',
                (string)self::DEFAULT_PAN_PANORAMA_SWEEP_MARGIN,
            ),
            'face_zoom_target_fill' => (float)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'face_zoom_target_fill',
                (string)self::DEFAULT_FACE_ZOOM_TARGET_FILL,
            ),
            'non_face_zoom_end' => (float)$this->config->getUserValue(
                $this->userId,
                Application::APP_ID,
                'non_face_zoom_end',
                (string)self::DEFAULT_NON_FACE_ZOOM_END,
            ),
        ]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'PUT', url: '/api/v1/settings')]
    public function updateSettings(
        ?int    $similarity_threshold = null,
        ?int    $burst_gap_seconds    = null,
        ?string $output_orientation   = null,
        ?string $motion_style = null,
        ?float  $pan_mismatch_threshold = null,
        ?float  $pan_sweep_margin = null,
        ?float  $pan_panorama_sweep_margin = null,
        ?float  $face_zoom_target_fill = null,
        ?float  $non_face_zoom_end = null,
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

        if ($motion_style !== null) {
            $allowed = ['classic', 'apple_subtle'];
            if (in_array($motion_style, $allowed, true)) {
                $this->config->setUserValue(
                    $this->userId,
                    Application::APP_ID,
                    'motion_style',
                    $motion_style,
                );
            }
        }

        if ($pan_mismatch_threshold !== null) {
            $pan_mismatch_threshold = max(1.05, min(2.50, $pan_mismatch_threshold));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'pan_mismatch_threshold',
                (string)$pan_mismatch_threshold,
            );
        }

        if ($pan_sweep_margin !== null) {
            $pan_sweep_margin = max(0.00, min(0.50, $pan_sweep_margin));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'pan_sweep_margin',
                (string)$pan_sweep_margin,
            );
        }

        if ($pan_panorama_sweep_margin !== null) {
            $pan_panorama_sweep_margin = max(0.00, min(0.50, $pan_panorama_sweep_margin));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'pan_panorama_sweep_margin',
                (string)$pan_panorama_sweep_margin,
            );
        }

        if ($face_zoom_target_fill !== null) {
            $face_zoom_target_fill = max(0.55, min(0.95, $face_zoom_target_fill));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'face_zoom_target_fill',
                (string)$face_zoom_target_fill,
            );
        }

        if ($non_face_zoom_end !== null) {
            $non_face_zoom_end = max(1.05, min(1.80, $non_face_zoom_end));
            $this->config->setUserValue(
                $this->userId,
                Application::APP_ID,
                'non_face_zoom_end',
                (string)$non_face_zoom_end,
            );
        }

        return $this->getSettings();
    }
}
