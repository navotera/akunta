<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AttachmentImageProcessor
{
    public const MAX_WIDTH = 2560;

    public const MAX_HEIGHT = 2560;

    public const THUMBNAIL_WIDTH = 320;

    public const THUMBNAIL_HEIGHT = 320;

    private const MAX_SOURCE_PIXELS = 40_000_000;

    private const MAIN_QUALITY = 84;

    private const THUMBNAIL_QUALITY = 76;

    /**
     * @return array<string, mixed>|null Null means the upload is not an image.
     */
    public function process(UploadedFile $file): ?array
    {
        $detectedMime = (string) $file->getMimeType();
        if (! str_starts_with($detectedMime, 'image/')) {
            return null;
        }

        $path = $file->getRealPath();
        $info = $path ? @getimagesize($path) : false;
        $supportedTypes = array_filter([
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF,
            defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : null,
        ]);

        if ($info === false || ! in_array($info[2] ?? null, $supportedTypes, true)) {
            throw ValidationException::withMessages([
                'file' => 'Format gambar tidak didukung. Gunakan JPEG, PNG, GIF, atau WebP.',
            ]);
        }

        $originalWidth = (int) $info[0];
        $originalHeight = (int) $info[1];
        if ($originalWidth <= 0 || $originalHeight <= 0
            || ($originalWidth * $originalHeight) > self::MAX_SOURCE_PIXELS) {
            throw ValidationException::withMessages([
                'file' => 'Resolusi gambar terlalu besar untuk diproses dengan aman.',
            ]);
        }

        $bytes = $path ? file_get_contents($path) : false;
        $source = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages([
                'file' => 'Isi file gambar tidak valid atau rusak.',
            ]);
        }

        try {
            $source = $this->applyExifOrientation($source, $path, (int) $info[2]);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            [$width, $height] = $this->fit($sourceWidth, $sourceHeight, self::MAX_WIDTH, self::MAX_HEIGHT);
            [$thumbnailWidth, $thumbnailHeight] = $this->fit(
                $sourceWidth,
                $sourceHeight,
                self::THUMBNAIL_WIDTH,
                self::THUMBNAIL_HEIGHT,
            );

            $main = null;
            $thumbnail = null;
            try {
                $main = $this->resize($source, $width, $height);
                $thumbnail = $this->resize($source, $thumbnailWidth, $thumbnailHeight);
                $mainContent = $this->encodeWebp($main, self::MAIN_QUALITY);
                $thumbnailContent = $this->encodeWebp($thumbnail, self::THUMBNAIL_QUALITY);
            } finally {
                if ($main instanceof GdImage) {
                    imagedestroy($main);
                }
                if ($thumbnail instanceof GdImage) {
                    imagedestroy($thumbnail);
                }
            }

            return [
                'content' => $mainContent,
                'thumbnail_content' => $thumbnailContent,
                'mime_type' => 'image/webp',
                'extension' => 'webp',
                'metadata' => [
                    'processed' => true,
                    'original_mime_type' => $detectedMime,
                    'original_size_bytes' => (int) $file->getSize(),
                    'original_width' => $originalWidth,
                    'original_height' => $originalHeight,
                    'width' => $width,
                    'height' => $height,
                    'resized' => $width !== $sourceWidth || $height !== $sourceHeight,
                    'quality' => self::MAIN_QUALITY,
                    'thumbnail' => [
                        'mime_type' => 'image/webp',
                        'width' => $thumbnailWidth,
                        'height' => $thumbnailHeight,
                        'size_bytes' => strlen($thumbnailContent),
                        'quality' => self::THUMBNAIL_QUALITY,
                    ],
                ],
            ];
        } finally {
            imagedestroy($source);
        }
    }

    private function applyExifOrientation(GdImage $image, string $path, int $imageType): GdImage
    {
        if ($imageType !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $degrees = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees, $background);
        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('Gagal mengoreksi orientasi gambar.');
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($image);

        return $rotated;
    }

    /** @return array{int, int} */
    private function fit(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    private function resize(GdImage $source, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        if (! $target instanceof GdImage) {
            throw new RuntimeException('Gagal menyiapkan gambar hasil.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source),
        );

        return $target;
    }

    private function encodeWebp(GdImage $image, int $quality): string
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('Dukungan WebP pada GD belum tersedia.');
        }

        ob_start();
        $encoded = imagewebp($image, null, $quality);
        $content = ob_get_clean();

        if (! $encoded || ! is_string($content) || $content === '') {
            throw new RuntimeException('Gagal mengompresi gambar ke WebP.');
        }

        return $content;
    }
}
