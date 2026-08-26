<?php

namespace App\Support;

class Thumbnail
{
    // The album grids are width-driven: a cell is 140–210 CSS px, so 480 px of
    // image is still sharp on a phone at 3x — and going below that visibly softens
    // the wall of strips, which is the page's whole point. A camera frame is
    // whatever the phone's camera made it (often 3x this). A composed strip is a
    // fixed size instead, set by its template: 648 px wide for the single-column
    // ones (so this trims) and 1272 px for the 2x2 grid (so it really shrinks).
    public const MAX_WIDTH = 480;

    private const QUALITY = 75;

    // Bytes in, JPEG bytes out. Strips are scaled by width and allowed to run as
    // long as they like; a frame keeps its shape. Nothing is ever upscaled.
    public static function fromImage(string $bytes): string
    {
        $image = imagecreatefromstring($bytes);
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= self::MAX_WIDTH) {
            return self::jpeg($image);
        }

        // imagecopyresampled, not imagescale: a 1200 px strip coming down to 480
        // needs the averaged samples, or the perforated edges alias into fringes.
        $thumbHeight = (int) round($height * self::MAX_WIDTH / $width);
        $thumb = imagecreatetruecolor(self::MAX_WIDTH, $thumbHeight);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, self::MAX_WIDTH, $thumbHeight, $width, $height);
        imagedestroy($image);

        return self::jpeg($thumb);
    }

    private static function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, self::QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }
}
