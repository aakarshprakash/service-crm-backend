<?php

namespace App\Support;

/**
 * Server-side safety net for job photos: clients downscale before upload, but a raw
 * 12–50 MP camera photo must still be accepted. Large or rotated images are re-encoded
 * as a JPEG no bigger than MAX_EDGE on the long side, with EXIF orientation applied.
 */
final class ImageOptimizer
{
    public const MAX_EDGE = 2000;

    /** Leave small, upright images untouched. */
    private const KEEP_BELOW_BYTES = 1_500_000;

    /** GD needs ~5 bytes per pixel; beyond this we keep the original instead. */
    private const MAX_PIXELS = 50_000_000;

    /** @return array{0: string, 1: string}|null [jpeg bytes, extension] or null to keep the original */
    public static function shrink(string $path, int $maxEdge = self::MAX_EDGE, int $quality = 82): ?array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $info = @getimagesize($path);
        if (! $info) {
            return null;
        }
        [$width, $height, $type] = $info;
        $bytes = (int) @filesize($path);

        $orientation = 1;
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }
        if (max($width, $height) <= $maxEdge && $bytes <= self::KEEP_BELOW_BYTES && $orientation === 1) {
            return null;
        }
        if ($width * $height > self::MAX_PIXELS) {
            return null;
        }

        $previousLimit = ini_get('memory_limit');
        @ini_set('memory_limit', '768M');
        try {
            $source = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                default => false,
            };
            if (! $source) {
                return null;
            }

            $scale = min(1, $maxEdge / max($width, $height));
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($newWidth, $newHeight);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255)); // JPEG has no alpha
            imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);

            $target = self::orient($target, $orientation);

            ob_start();
            imagejpeg($target, null, $quality);
            $jpeg = (string) ob_get_clean();
            imagedestroy($target);

            if ($jpeg === '' || ($scale === 1 && $orientation === 1 && strlen($jpeg) >= $bytes)) {
                return null;
            }

            return [$jpeg, 'jpg'];
        } catch (\Throwable) {
            return null;
        } finally {
            @ini_set('memory_limit', (string) $previousLimit);
        }
    }

    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
