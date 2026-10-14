<?php

declare(strict_types=1);

namespace App\Tests\Image;

use App\Image\ImageProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// One contract for every ImageProcessor. Fixtures and checks use GD. Each tile of the source is
// one flat grey, so a misplaced tile shows as a wrong mean.
abstract class ImageProcessorTestCase extends TestCase
{
    private const TILE = 128;
    private const GUTTER = 8;
    private const CELL = self::TILE + 2 * self::GUTTER;
    private const COLUMNS = 5;
    private const ROWS = 4;
    // Partial edge tiles on the right and bottom: 600 = 4 * 128 + 88, 500 = 3 * 128 + 116.
    private const WIDTH = 600;
    private const HEIGHT = 500;

    protected string $dir;

    abstract protected function processor(): ImageProcessor;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/image_test_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Unset when a subclass skipped before setUp.
        if (isset($this->dir)) {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testTilesLandInTheSlotsTheOrderNames(): void
    {
        $packed = $this->dir.'/packed.webp';

        [$width, $height, $order] = $this->processor()->toScrambledWebp($this->tileSource(), $packed, 1200, self::TILE, self::GUTTER);

        self::assertSame([self::WIDTH, self::HEIGHT], [$width, $height]);
        $sorted = $order;
        sort($sorted);
        self::assertSame(range(0, self::COLUMNS * self::ROWS - 1), $sorted, 'Order is a permutation.');
        self::assertNotSame(range(0, self::COLUMNS * self::ROWS - 1), $order, 'Tiles are shuffled.');
        $image = imagecreatefromwebp($packed) ?: self::fail('Not a WebP.');
        self::assertSame([self::COLUMNS * self::CELL, self::ROWS * self::CELL], [imagesx($image), imagesy($image)]);

        foreach ($order as $tile => $slot) {
            $x = ($slot % self::COLUMNS) * self::CELL;
            $y = intdiv($slot, self::COLUMNS) * self::CELL;
            $tileWidth = min(self::TILE, self::WIDTH - ($tile % self::COLUMNS) * self::TILE);
            $tileHeight = min(self::TILE, self::HEIGHT - intdiv($tile, self::COLUMNS) * self::TILE);
            self::assertEqualsWithDelta($this->grey($tile), $this->mean($image, $x + self::GUTTER, $y + self::GUTTER, $tileWidth, $tileHeight), 2.0, "Tile $tile in slot $slot");

            // Gutter: the left neighbour's pixels, or the tile's own edge repeated on the first column.
            $left = 0 === $tile % self::COLUMNS ? $tile : $tile - 1;
            self::assertEqualsWithDelta($this->grey($left), $this->mean($image, $x + 1, $y + self::GUTTER + 16, 4, 32), 6.0, "Left gutter of tile $tile");
        }
    }

    public function testCoverIsCroppedToTheRequestedBoxInTheTargetFormat(): void
    {
        foreach (['cover.jpg' => \IMAGETYPE_JPEG, 'thumb.webp' => \IMAGETYPE_WEBP] as $name => $type) {
            $cover = $this->dir.'/'.$name;
            $this->processor()->toCover($this->tileSource(), $cover, 300, 200);

            self::assertSame([300, 200, $type], \array_slice(getimagesize($cover) ?: [], 0, 3));
        }
    }

    // A small source is cropped to the box but not scaled up to fill it (vips thumbnail --size down).
    public function testCoverAndFitNeverUpscale(): void
    {
        $this->processor()->toCover($this->tileSource(), $this->dir.'/cover.jpg', 1200, 630);
        $this->processor()->toFit($this->tileSource(), $this->dir.'/fit.webp', 1200, 1200);

        self::assertSame([600, 500], \array_slice(getimagesize($this->dir.'/cover.jpg') ?: [], 0, 2));
        self::assertSame([600, 500], \array_slice(getimagesize($this->dir.'/fit.webp') ?: [], 0, 2));
    }

    public function testFitKeepsAspectAndTransparency(): void
    {
        $source = imagecreatetruecolor(400, 100) ?: self::fail();
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, (int) imagecolorallocatealpha($source, 255, 0, 0, 127));
        imagepng($source, $this->dir.'/logo.png');

        $this->processor()->toFit($this->dir.'/logo.png', $this->dir.'/logo.webp', 200, 200);

        $image = imagecreatefromwebp($this->dir.'/logo.webp') ?: self::fail();
        self::assertSame([200, 50], [imagesx($image), imagesy($image)]);
        self::assertSame(127, (imagecolorat($image, 100, 25) >> 24) & 0x7F, 'Transparent pixel stays transparent.');
    }

    public function testUploadedFilesCannotReachOtherDecoders(): void
    {
        // SVG behind an image extension: decoders that sniff content would render it.
        $disguised = $this->dir.'/page.png';
        file_put_contents($disguised, '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64"/></svg>');

        $this->expectException(\RuntimeException::class);
        $this->processor()->toCover($disguised, $this->dir.'/cover.jpg', 32, 32);
    }

    // Distinct, well-separated greys, so lossy WebP cannot blur one tile's value into another's.
    private function grey(int $tile): float
    {
        return 10.0 + $tile * 12;
    }

    private function tileSource(): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT) ?: self::fail();
        for ($tile = 0; $tile < self::COLUMNS * self::ROWS; ++$tile) {
            /** @var int<0, 255> $grey */
            $grey = (int) $this->grey($tile);
            $x = ($tile % self::COLUMNS) * self::TILE;
            $y = intdiv($tile, self::COLUMNS) * self::TILE;
            imagefilledrectangle($image, $x, $y, $x + self::TILE - 1, $y + self::TILE - 1, (int) imagecolorallocate($image, $grey, $grey, $grey));
        }
        imagepng($image, $this->dir.'/source.png');

        return $this->dir.'/source.png';
    }

    // Mean of the green channel over a rectangle; the fixtures are grey.
    private function mean(\GdImage $image, int $x, int $y, int $width, int $height): float
    {
        $sum = 0;
        for ($j = $y; $j < $y + $height; ++$j) {
            for ($i = $x; $i < $x + $width; ++$i) {
                $sum += (imagecolorat($image, $i, $j) >> 8) & 0xFF;
            }
        }

        return $sum / ($width * $height);
    }
}
