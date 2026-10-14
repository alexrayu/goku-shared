<?php

declare(strict_types=1);

namespace App\Image;

// Upload sniffed by content before any decoder sees it, with the size math the in-process
// processors (GD, Imagick) share. Dimensions are as displayed, after the EXIF orientation.
final readonly class SourceImage
{
    // GD and Imagick decoders the processors may call; anything else (SVG, PDF, ...) is refused.
    private const FORMATS = [\IMAGETYPE_JPEG => 'jpeg', \IMAGETYPE_PNG => 'png', \IMAGETYPE_WEBP => 'webp', \IMAGETYPE_GIF => 'gif'];

    private function __construct(
        public string $path,
        public string $format,
        public int $width,
        public int $height,
        public int $orientation,
    ) {
    }

    public static function inspect(string $path): self
    {
        $info = @getimagesize($path);
        $format = false === $info ? null : (self::FORMATS[$info[2]] ?? null);
        if (null === $format || $info[0] < 1 || $info[1] < 1) {
            throw new UnprocessableImage('Not a JPEG, PNG, WebP or GIF image.');
        }

        $orientation = 1;
        if ('jpeg' === $format && \function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = (int) (\is_array($exif) ? ($exif['Orientation'] ?? 1) : 1);
        }
        // Orientations 5-8 turn the image by 90 degrees.
        [$width, $height] = $orientation >= 5 && $orientation <= 8 ? [$info[1], $info[0]] : [$info[0], $info[1]];

        return new self($path, $format, $width, $height, $orientation);
    }

    /**
     * Shrunk to fit the box, never upscaled.
     *
     * @return array{int, int}
     */
    public function fit(int $maxWidth, int $maxHeight): array
    {
        $scale = min(1, $maxWidth / $this->width, $maxHeight / $this->height);

        return [max(1, (int) round($this->width * $scale)), max(1, (int) round($this->height * $scale))];
    }

    /**
     * Shrunk to cover the box and centre-cropped to it, never upscaled.
     *
     * @return array{int, int, int, int, int, int} output width and height; source crop x, y, width, height
     */
    public function cover(int $width, int $height): array
    {
        $scale = min(1, max($width / $this->width, $height / $this->height));
        $outWidth = min($width, max(1, (int) round($this->width * $scale)));
        $outHeight = min($height, max(1, (int) round($this->height * $scale)));
        $cropWidth = min($this->width, (int) round($outWidth / $scale));
        $cropHeight = min($this->height, (int) round($outHeight / $scale));

        return [$outWidth, $outHeight, intdiv($this->width - $cropWidth, 2), intdiv($this->height - $cropHeight, 2), $cropWidth, $cropHeight];
    }
}
