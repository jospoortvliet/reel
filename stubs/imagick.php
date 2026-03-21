<?php

declare(strict_types=1);

// IDE-only fallback stubs for environments where ext-imagick symbols are unavailable
// to static analyzers. Runtime environments with ext-imagick loaded will use the
// real classes.
if (!class_exists('Imagick')) {
    class Imagick {
        public const FILTER_LANCZOS = 22;
        public const COLORSPACE_GRAY = 1;
        public const KERNEL_LAPLACIAN = 4;
        public const CHANNEL_GRAY = 2;

        public const ORIENTATION_LEFTTOP = 5;
        public const ORIENTATION_RIGHTTOP = 6;
        public const ORIENTATION_RIGHTBOTTOM = 7;
        public const ORIENTATION_LEFTBOTTOM = 8;

        public function __construct(?string $filename = null) {}
        public function readImage(string $filename): bool { return true; }
        public function setImageFormat(string $format): bool { return true; }
        public function setImageCompressionQuality(int $quality): bool { return true; }
        public function writeImage(string $filename = ''): bool { return true; }
        public function clear(): bool { return true; }
        public function destroy(): bool { return true; }
        public function resizeImage(int $columns, int $rows, int $filter, float $blur, bool $bestfit = false): bool { return true; }
        public function setColorspace(int $colorspace): bool { return true; }
        public function morphology(int $method, int $iterations, $kernel = null): bool { return true; }
        public function getImageChannelStatistics(): array { return []; }
        public function getImageOrientation(): int { return 1; }
        public function rotateImage($background, float $degrees): bool { return true; }
        public function stripImage(): bool { return true; }
        public function getImageWidth(): int { return 0; }
        public function getImageHeight(): int { return 0; }
        public function autoOrient(): bool { return true; }
        public function thumbnailImage(int $columns, int $rows, bool $bestfit = false, bool $fill = false, bool $legacy = false): bool { return true; }
        public function setImageColorspace(int $colorspace): bool { return true; }
        public function edgeImage(float $radius): bool { return true; }
        public function cropImage(int $width, int $height, int $x, int $y): bool { return true; }
        public function filter($kernel): bool { return true; }
    }
}

if (!class_exists('ImagickKernel')) {
    class ImagickKernel {
        public static function fromBuiltIn(int $kernel, string $args = ''): self {
            return new self();
        }
    }
}
