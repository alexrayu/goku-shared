<?php

declare(strict_types=1);

namespace App\Image;

use Random\Randomizer;

// Middle choice: ImageMagick keeps pixels outside PHP's memory_limit and shrinks JPEGs on load.
final class ImagickImageProcessor implements ImageProcessor
{
    private const QUALITY = 80;

    public function toScrambledWebp(string $source, string $target, int $maxWidth, int $tileSize, int $gutter): array
    {
        $input = SourceImage::inspect($source);
        $cell = $tileSize + 2 * $gutter;
        [$width, $height] = $input->fit($maxWidth, intdiv(self::WEBP_MAX_SIDE, $cell) * $tileSize);
        $columns = intdiv($width + $tileSize - 1, $tileSize);
        $rows = intdiv($height + $tileSize - 1, $tileSize);
        $paddedWidth = $columns * $tileSize + 2 * $gutter;
        $paddedHeight = $rows * $tileSize + 2 * $gutter;

        $image = $this->load($input, max($width, $height));
        $image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);

        // Reading copy shifted by the gutter, its edge pixels repeated outwards (vips embed --extend copy).
        $canvas = $this->canvas($paddedWidth, $paddedHeight);
        $canvas->compositeImage($image, \Imagick::COMPOSITE_COPY, $gutter, $gutter);
        $right = $gutter + $width;
        $bottom = $gutter + $height;
        $this->stretch($image, [0, 0, 1, $height], $canvas, [0, $gutter, $gutter, $height]);
        $this->stretch($image, [$width - 1, 0, 1, $height], $canvas, [$right, $gutter, $paddedWidth - $right, $height]);
        $image->clear();
        $this->stretch($canvas, [0, $gutter, $paddedWidth, 1], $canvas, [0, 0, $paddedWidth, $gutter]);
        $this->stretch($canvas, [0, $bottom - 1, $paddedWidth, 1], $canvas, [0, $bottom, $paddedWidth, $paddedHeight - $bottom]);

        /** @var list<int> $order */
        $order = (new Randomizer())->shuffleArray(range(0, $columns * $rows - 1));
        $packed = $this->canvas($columns * $cell, $rows * $cell);
        foreach ($order as $tile => $slot) {
            $region = $canvas->getImageRegion($cell, $cell, ($tile % $columns) * $tileSize, intdiv($tile, $columns) * $tileSize);
            $packed->compositeImage($region, \Imagick::COMPOSITE_COPY, ($slot % $columns) * $cell, intdiv($slot, $columns) * $cell);
            $region->clear();
        }
        $canvas->clear();
        $this->save($packed, $target);

        return [$width, $height, $order];
    }

    public function toCover(string $source, string $target, int $width, int $height): void
    {
        $input = SourceImage::inspect($source);
        [$outWidth, $outHeight, $x, $y, $cropWidth, $cropHeight] = $input->cover($width, $height);

        // Shrink-on-load may have scaled the decode: crop in the decoded image's own units.
        $image = $this->load($input, max($width, $height));
        $scale = $image->getImageWidth() / $input->width;
        $image->cropImage((int) round($cropWidth * $scale), (int) round($cropHeight * $scale), (int) round($x * $scale), (int) round($y * $scale));
        $image->setImagePage(0, 0, 0, 0);
        $image->resizeImage($outWidth, $outHeight, \Imagick::FILTER_LANCZOS, 1);
        $this->save($image, $target);
    }

    public function toFit(string $source, string $target, int $width, int $height): void
    {
        $input = SourceImage::inspect($source);
        [$outWidth, $outHeight] = $input->fit($width, $height);

        $image = $this->load($input, max($width, $height));
        $image->resizeImage($outWidth, $outHeight, \Imagick::FILTER_LANCZOS, 1);
        $this->save($image, $target);
    }

    /**
     * The decoder is named explicitly: content sniffing could reach ImageMagick's SVG, PDF or MSL
     * coders. JPEGs decode at the smallest DCT scale still at least $size on both sides.
     */
    private function load(SourceImage $input, int $size): \Imagick
    {
        $image = new \Imagick();
        try {
            if ('jpeg' === $input->format) {
                $image->setOption('jpeg:size', sprintf('%dx%d', $size, $size));
            }
            $image->readImage(sprintf('%s:%s[0]', $input->format, $input->path));
            $image->autoOrient();
            if (\Imagick::COLORSPACE_CMYK === $image->getImageColorspace()) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $image->stripImage();
        } catch (\ImagickException $e) {
            throw new UnprocessableImage('The image could not be decoded.', 0, $e);
        }

        return $image;
    }

    private function canvas(int $width, int $height): \Imagick
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('transparent'));

        return $image;
    }

    /**
     * Copies the 1 px strip $from of $source over $to in $target, repeated by point sampling.
     *
     * @param array{int, int, int, int} $from x, y, width, height
     * @param array{int, int, int, int} $to   x, y, width, height
     */
    private function stretch(\Imagick $source, array $from, \Imagick $target, array $to): void
    {
        if ($to[2] < 1 || $to[3] < 1) {
            return;
        }
        $strip = $source->getImageRegion($from[2], $from[3], $from[0], $from[1]);
        $strip->sampleImage($to[2], $to[3]);
        $target->compositeImage($strip, \Imagick::COMPOSITE_COPY, $to[0], $to[1]);
        $strip->clear();
    }

    // Format from the target extension; JPEG has no alpha, so it is flattened on white.
    private function save(\Imagick $image, string $target): void
    {
        if ('webp' === strtolower(pathinfo($target, \PATHINFO_EXTENSION))) {
            $image->setImageFormat('webp');
        } else {
            $image->setImageBackgroundColor(new \ImagickPixel('white'));
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $image->setImageFormat('jpeg');
        }
        $image->setImageCompressionQuality(self::QUALITY);
        $image->writeImage($target);
    }
}
