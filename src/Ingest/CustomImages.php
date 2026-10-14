<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Entity\SiteSettings;
use App\Entity\Work;
use App\Image\ImageProcessor;
use App\Image\UnprocessableImage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Exception\ProcessFailedException;

// Images the artist uploads in admin outside chapters: a work's chosen cover and the site logo.
// One image each, processed in the request (two conversions at most), not by the worker.
final class CustomImages
{
    // Header height 1.75rem at 2x, wide enough for a wordmark.
    private const LOGO_WIDTH = 480;
    private const LOGO_HEIGHT = 112;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilesystemOperator $defaultStorage,
        private readonly ImageProcessor $images,
    ) {
    }

    /**
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function storeWorkCover(Work $work, File $upload): void
    {
        $this->convert($upload, [
            ['jpg', StorageKeys::workCover($work), fn (string $s, string $t) => $this->images->toCover($s, $t, DerivativeGenerator::COVER_WIDTH, DerivativeGenerator::COVER_HEIGHT)],
            ['webp', StorageKeys::workThumbnail($work), fn (string $s, string $t) => $this->images->toCover($s, $t, DerivativeGenerator::THUMB_WIDTH, DerivativeGenerator::THUMB_HEIGHT)],
        ]);
        $work->setCoverVersion(($work->getCoverVersion() ?? 0) + 1);
        $this->em->flush();
    }

    public function deleteWorkCover(Work $work): void
    {
        $work->setCoverVersion(null);
        $this->em->flush();
        $this->defaultStorage->delete(StorageKeys::workCover($work));
        $this->defaultStorage->delete(StorageKeys::workThumbnail($work));
    }

    // Rows first, files after the flush, as in ChapterPages. Fails on the FK while chapters remain.
    public function deleteWork(Work $work): void
    {
        $files = [StorageKeys::workCover($work), StorageKeys::workThumbnail($work)];
        $this->em->remove($work);
        $this->em->flush();
        foreach ($files as $file) {
            $this->defaultStorage->delete($file);
        }
    }

    /**
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function storeLogo(SiteSettings $settings, File $upload): void
    {
        $this->convert($upload, [
            ['webp', StorageKeys::logo(), fn (string $s, string $t) => $this->images->toFit($s, $t, self::LOGO_WIDTH, self::LOGO_HEIGHT)],
        ]);
        $settings->setLogoVersion(($settings->getLogoVersion() ?? 0) + 1);
        $this->em->flush();
    }

    public function deleteLogo(SiteSettings $settings): void
    {
        $settings->setLogoVersion(null);
        $this->em->flush();
        $this->defaultStorage->delete(StorageKeys::logo());
    }

    /**
     * Converts every output to a temp file first, then stores them: a bad image changes nothing.
     *
     * @param list<array{string, string, callable(string, string): void}> $outputs extension, storage key, converter
     */
    private function convert(File $upload, array $outputs): void
    {
        $files = [];
        try {
            foreach ($outputs as [$extension, $key, $converter]) {
                $target = sprintf('%s/custom_%s.%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $extension);
                $files[$key] = $target;
                $converter($upload->getPathname(), $target);
            }
            foreach ($files as $key => $file) {
                $stream = fopen($file, 'rb') ?: throw new \RuntimeException('Cannot read the converted image.');
                try {
                    $this->defaultStorage->writeStream($key, $stream);
                } finally {
                    fclose($stream);
                }
            }
        } catch (ProcessFailedException $e) {
            throw new \InvalidArgumentException('This image could not be read. Upload a PNG, JPEG or WebP file.', 0, $e);
        } catch (UnprocessableImage $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
