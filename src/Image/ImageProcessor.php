<?php

declare(strict_types=1);

namespace App\Image;

// Implementations: libvips CLI, Imagick, GD; ImageProcessorFactory picks what the host offers.
interface ImageProcessor
{
    // WebP cannot exceed this on either side; very tall strips are scaled to fit.
    public const WEBP_MAX_SIDE = 16383;

    /**
     * Shrinks $source to at most $maxWidth wide (never upscaled), cuts it into $tileSize squares
     * and writes them to $target as one WebP with the tiles in shuffled slots, row-major,
     * order[sourceTile] = slot (goku-static's order format). Each slot is a cell of
     * $tileSize + 2 * $gutter: the tile plus $gutter pixels of its real surroundings, so lossy
     * compression does not bleed one tile's edge into an unrelated neighbour. Edge tiles are
     * padded to full size.
     *
     * @return array{int, int, list<int>} reading width, reading height, tile order
     */
    public function toScrambledWebp(string $source, string $target, int $maxWidth, int $tileSize, int $gutter): array;

    /**
     * Writes an unscrambled copy of $source cropped to $width x $height (never upscaled), for link
     * previews and listing thumbnails. The format follows $target's extension.
     */
    public function toCover(string $source, string $target, int $width, int $height): void;

    /**
     * Writes $source shrunk to fit within $width x $height, uncropped, aspect and transparency kept
     * (never upscaled). The format follows $target's extension.
     */
    public function toFit(string $source, string $target, int $width, int $height): void;
}
