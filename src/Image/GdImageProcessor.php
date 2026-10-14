<?php

declare(strict_types=1);

namespace App\Image;

use Random\Randomizer;

// Fallback for hosts with neither libvips nor Imagick. GD decodes whole images into PHP memory,
// so each job checks its peak against memory_limit first and refuses what would not fit.
final class GdImageProcessor implements ImageProcessor
{
    private const QUALITY = 80;
    // Measured: 4-byte truecolor pixels plus the PNG decoder's buffer of the whole source.
    // A 1600x20000 strip peaks at 342 MB, so a 256M memory_limit refuses it.
    private const BYTES_PER_PIXEL = 7;

    public function toScrambledWebp(string $source, string $target, int $maxWidth, int $tileSize, int $gutter): array
    {
        $input = SourceImage::inspect($source);
        $cell = $tileSize + 2 * $gutter;
        [$width, $height] = $input->fit($maxWidth, intdiv(self::WEBP_MAX_SIDE, $cell) * $tileSize);
        $columns = intdiv($width + $tileSize - 1, $tileSize);
        $rows = intdiv($height + $tileSize - 1, $tileSize);
        $paddedWidth = $columns * $tileSize + 2 * $gutter;
        $paddedHeight = $rows * $tileSize + 2 * $gutter;

        // Peaks: source + padded while resizing, then padded + packed.
        $padded = $paddedWidth * $paddedHeight;
        $this->assertMemory($padded + max($input->width * $input->height, $columns * $rows * $cell * $cell));

        // Reading copy shifted by the gutter, its edge pixels repeated outwards (vips embed --extend copy).
        $image = $this->load($input);
        $canvas = $this->canvas($paddedWidth, $paddedHeight);
        imagecopyresampled($canvas, $image, $gutter, $gutter, 0, 0, $width, $height, $input->width, $input->height);
        unset($image);
        $right = $gutter + $width;
        $bottom = $gutter + $height;
        imagecopyresized($canvas, $canvas, 0, $gutter, $gutter, $gutter, $gutter, $height, 1, $height);
        imagecopyresized($canvas, $canvas, $right, $gutter, $right - 1, $gutter, $paddedWidth - $right, $height, 1, $height);
        imagecopyresized($canvas, $canvas, 0, 0, 0, $gutter, $paddedWidth, $gutter, $paddedWidth, 1);
        imagecopyresized($canvas, $canvas, 0, $bottom, 0, $bottom - 1, $paddedWidth, $paddedHeight - $bottom, $paddedWidth, 1);

        /** @var list<int> $order */
        $order = (new Randomizer())->shuffleArray(range(0, $columns * $rows - 1));
        $packed = $this->canvas($columns * $cell, $rows * $cell);
        foreach ($order as $tile => $slot) {
            imagecopy(
                $packed, $canvas,
                ($slot % $columns) * $cell, intdiv($slot, $columns) * $cell,
                ($tile % $columns) * $tileSize, intdiv($tile, $columns) * $tileSize,
                $cell, $cell,
            );
        }
        unset($canvas);
        $this->save($packed, $target);

        return [$width, $height, $order];
    }

    public function toCover(string $source, string $target, int $width, int $height): void
    {
        $input = SourceImage::inspect($source);
        [$outWidth, $outHeight, $x, $y, $cropWidth, $cropHeight] = $input->cover($width, $height);
        $this->assertMemory($input->width * $input->height + $outWidth * $outHeight);

        $output = $this->canvas($outWidth, $outHeight);
        imagecopyresampled($output, $this->load($input), 0, 0, $x, $y, $outWidth, $outHeight, $cropWidth, $cropHeight);
        $this->save($output, $target);
    }

    public function toFit(string $source, string $target, int $width, int $height): void
    {
        $input = SourceImage::inspect($source);
        [$outWidth, $outHeight] = $input->fit($width, $height);
        $this->assertMemory($input->width * $input->height + $outWidth * $outHeight);

        $output = $this->canvas($outWidth, $outHeight);
        imagecopyresampled($output, $this->load($input), 0, 0, 0, 0, $outWidth, $outHeight, $input->width, $input->height);
        $this->save($output, $target);
    }

    private function assertMemory(int $pixels): void
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit > 0 && $pixels * self::BYTES_PER_PIXEL > $limit - memory_get_usage()) {
            throw new UnprocessableImage(sprintf('The image is too large to process within the host\'s memory_limit (%s).', ini_get('memory_limit')));
        }
    }

    private function load(SourceImage $input): \GdImage
    {
        $image = match ($input->format) {
            'jpeg' => @imagecreatefromjpeg($input->path),
            'png' => @imagecreatefrompng($input->path),
            'webp' => @imagecreatefromwebp($input->path),
            'gif' => @imagecreatefromgif($input->path),
            default => false,
        };
        if (!$image instanceof \GdImage) {
            throw new UnprocessableImage('The image could not be decoded.');
        }
        imagepalettetotruecolor($image);

        // EXIF orientation; imagerotate turns counter-clockwise.
        $image = match ($input->orientation) {
            3 => imagerotate($image, 180, 0),
            5, 6, 7 => imagerotate($image, 270, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (false === $image) {
            throw new UnprocessableImage('The image could not be rotated.');
        }
        match ($input->orientation) {
            2, 5 => imageflip($image, \IMG_FLIP_HORIZONTAL),
            4, 7 => imageflip($image, \IMG_FLIP_VERTICAL),
            default => null,
        };

        return $image;
    }

    // Truecolor canvas that copies alpha as is instead of blending it.
    private function canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if (false === $image) {
            throw new \RuntimeException('Cannot allocate the image.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    // Format from the target extension; JPEG has no alpha, so it is flattened on white.
    private function save(\GdImage $image, string $target): void
    {
        if ('webp' === strtolower(pathinfo($target, \PATHINFO_EXTENSION))) {
            $saved = imagewebp($image, $target, self::QUALITY);
        } else {
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            if (false === $flat) {
                throw new \RuntimeException('Cannot allocate the image.');
            }
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $saved = imagejpeg($flat, $target, self::QUALITY);
        }
        if (!$saved) {
            throw new \RuntimeException('Cannot write the image.');
        }
    }
}
