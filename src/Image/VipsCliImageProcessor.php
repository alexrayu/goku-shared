<?php

declare(strict_types=1);

namespace App\Image;

use Random\Randomizer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// libvips streams the image (shrink-on-load), so memory stays flat however tall the strip.
final class VipsCliImageProcessor implements ImageProcessor
{
    private const QUALITY = 80;

    public function __construct(private readonly Filesystem $filesystem = new Filesystem())
    {
    }

    public function toScrambledWebp(string $source, string $target, int $maxWidth, int $tileSize, int $gutter): array
    {
        // Random name, no spaces: vips takes the bandjoin inputs as one space-separated argument.
        $dir = sys_get_temp_dir().'/scramble_'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('Cannot create a temporary directory.');
        }

        try {
            // The packed image has a cell per tile row, so the reading height is capped at the rows that fit.
            $cell = $tileSize + 2 * $gutter;
            $reading = $dir.'/reading.v';
            $this->run([
                'vips', 'thumbnail', $source, $reading, (string) $maxWidth,
                '--height', (string) (intdiv(self::WEBP_MAX_SIDE, $cell) * $tileSize), '--size', 'down',
            ], untrustedInput: true);
            $width = (int) $this->run(['vipsheader', '-f', 'width', $reading]);
            $height = (int) $this->run(['vipsheader', '-f', 'height', $reading]);
            $columns = intdiv($width + $tileSize - 1, $tileSize);
            $rows = intdiv($height + $tileSize - 1, $tileSize);

            /** @var list<int> $order */
            $order = (new Randomizer())->shuffleArray(range(0, $columns * $rows - 1));

            // mapim index: every packed pixel holds the coordinate it copies from in the padded copy
            // (reading copy shifted by the gutter). Built at tile resolution as two matrices (x and y
            // origin of the tile in each slot), zoomed to cell size, plus the pixel's offset in its cell.
            $sourceOfSlot = array_flip($order);
            $origins = ['x' => '', 'y' => ''];
            for ($slot = 0; $slot < $columns * $rows; ++$slot) {
                $tile = $sourceOfSlot[$slot];
                $separator = 0 === ($slot + 1) % $columns ? "\n" : ' ';
                $origins['x'] .= ($tile % $columns) * $tileSize.$separator;
                $origins['y'] .= intdiv($tile, $columns) * $tileSize.$separator;
            }
            foreach ($origins as $axis => $values) {
                $this->filesystem->dumpFile("$dir/$axis.mat", "$columns $rows\n$values");
            }

            $size = (string) $cell;
            $this->run(['vips', 'bandjoin', "$dir/x.mat $dir/y.mat", "$dir/origin.v"]);
            $this->run(['vips', 'cast', "$dir/origin.v", "$dir/origin-int.v", 'ushort']);
            $this->run(['vips', 'zoom', "$dir/origin-int.v", "$dir/origin-full.v", $size, $size]);
            $this->run(['vips', 'xyz', "$dir/xy.v", (string) ($columns * $cell), (string) ($rows * $cell)]);
            $this->run(['vips', 'remainder_const', "$dir/xy.v", "$dir/offset.v", $size]);
            $this->run(['vips', 'add', "$dir/origin-full.v", "$dir/offset.v", "$dir/index.v"]);
            $this->run([
                'vips', 'embed', $reading, "$dir/padded.v", (string) $gutter, (string) $gutter,
                (string) ($columns * $tileSize + 2 * $gutter), (string) ($rows * $tileSize + 2 * $gutter), '--extend', 'copy',
            ]);
            $this->run([
                'vips', 'mapim', "$dir/padded.v", sprintf('%s[Q=%d,keep=none]', $target, self::QUALITY), "$dir/index.v",
                '--interpolate', 'nearest',
            ]);

            return [$width, $height, $order];
        } finally {
            $this->filesystem->remove($dir);
        }
    }

    public function toCover(string $source, string $target, int $width, int $height): void
    {
        $this->run([
            'vips', 'thumbnail', $source, sprintf('%s[Q=%d,keep=none]', $target, self::QUALITY), (string) $width,
            '--height', (string) $height, '--crop', 'attention', '--size', 'down',
        ], untrustedInput: true);
    }

    public function toFit(string $source, string $target, int $width, int $height): void
    {
        $this->run([
            'vips', 'thumbnail', $source, sprintf('%s[Q=%d,keep=none]', $target, self::QUALITY), (string) $width,
            '--height', (string) $height, '--size', 'down',
        ], untrustedInput: true);
    }

    /**
     * Uploaded files are sniffed by content, not extension: a ".png" could reach the PDF, SVG or
     * ImageMagick loaders. $untrustedInput limits vips to its fuzzed loaders (JPEG, PNG, WebP, GIF, ...).
     * Not for every call: the pipeline's own .v and .mat intermediates use loaders vips marks untrusted.
     *
     * @param list<string> $command
     */
    private function run(array $command, bool $untrustedInput = false): string
    {
        $process = new Process($command, env: $untrustedInput ? ['VIPS_BLOCK_UNTRUSTED' => '1'] : [], timeout: 120);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
