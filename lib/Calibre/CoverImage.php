<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\Metadata\Text;

/** Turns an uploaded image into the JPEG that Calibre expects as cover.jpg. */
class CoverImage {
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;
    private const MAX_WIDTH = 2000;
    private const MAX_HEIGHT = 3000;

    public function toJpeg(string $data): string {
        if (strlen($data) > self::MAX_BYTES) {
            throw new CalibreException('The image is too large (at most 20 MB)');
        }
        $mime = Text::sniffImageMime($data);
        $size = @getimagesizefromstring($data);
        if ($mime === '' || $size === false) {
            throw new CalibreException('This is not an image that can be used as a cover (JPEG, PNG, WebP or GIF)');
        }
        [$width, $height] = $size;
        // Checked before decoding: a small file can unpack to gigabytes
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new CalibreException('The image has too many pixels');
        }
        if ($size[2] === IMAGETYPE_JPEG && $width <= self::MAX_WIDTH && $height <= self::MAX_HEIGHT) {
            return $data;
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new CalibreException('This server cannot convert images. Use a JPEG cover');
        }
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw new CalibreException('The image cannot be read');
        }
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $w = max(1, (int)round($width * $scale));
        $h = max(1, (int)round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        // JPEG has no transparency
        imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string)ob_get_clean();
        if ($jpeg === '') {
            throw new CalibreException('The image cannot be converted to JPEG');
        }
        return $jpeg;
    }
}
