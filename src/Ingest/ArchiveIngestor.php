<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Entity\Chapter;
use App\Entity\Page;
use App\Ingest\Message\GenerateDerivative;
use App\Ingest\Message\IngestArchive;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ArchiveIngestor
{
    // Uncompressed size cap per image: guards against zip bombs filling the disk.
    private const ENTRY_MAX_BYTES = 64 * 1024 * 1024;
    // Whole-archive caps: the per-entry cap alone lets many entries fill the disk.
    private const MAX_PAGES = 1000;
    // Also caps the archive itself (ChunkedUpload).
    public const TOTAL_MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilesystemOperator $defaultStorage,
        private readonly MessageBusInterface $bus,
        private readonly ArchivePageOrder $pageOrder,
        private readonly StorageTempFile $tempFile,
    ) {
    }

    /**
     * Web side: store the upload, queue extraction. Nothing heavy runs in the request.
     *
     * @param string $name the file name the client sent
     *
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function accept(Chapter $chapter, File $file, string $name): void
    {
        if (!\in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), ['zip', 'cbz'], true)
            || 'application/zip' !== $file->getMimeType()) {
            throw new \InvalidArgumentException('Upload a .zip or .cbz archive.');
        }
        $this->assertNoPages($chapter);

        $key = StorageKeys::incoming($chapter);
        $stream = fopen($file->getPathname(), 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot read the uploaded file.');
        }
        try {
            $this->defaultStorage->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }

        $this->bus->dispatch(new IngestArchive((int) $chapter->getId(), $key));
    }

    // Worker side: extract pages, store originals, queue one derivative job per page.
    #[AsMessageHandler]
    public function ingest(IngestArchive $message): void
    {
        $chapter = $this->em->find(Chapter::class, $message->chapterId);

        try {
            if (null !== $chapter) {
                $this->extract($chapter, $message->archiveKey);
            }
        } catch (\InvalidArgumentException $e) {
            // Bad input does not improve on retry.
            $this->defaultStorage->delete($message->archiveKey);
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $this->defaultStorage->delete($message->archiveKey);
    }

    private function extract(Chapter $chapter, string $archiveKey): void
    {
        $this->assertNoPages($chapter);

        $path = $this->tempFile->download($archiveKey);
        $zip = new \ZipArchive();
        $opened = false;
        $written = [];

        try {
            if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
                throw new \InvalidArgumentException('Not a readable ZIP archive.');
            }
            $opened = true;

            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $entries = $this->pageOrder->sort($names);
            if ([] === $entries) {
                throw new \InvalidArgumentException('The archive contains no images.');
            }
            if (\count($entries) > self::MAX_PAGES) {
                throw new \InvalidArgumentException(sprintf('The archive has %d images; a chapter takes at most %d.', \count($entries), self::MAX_PAGES));
            }
            // Sizes from the central directory, before anything is written.
            $total = 0;
            foreach ($entries as $name) {
                $total += $zip->statName($name)['size'] ?? 0;
            }
            if ($total > self::TOTAL_MAX_BYTES) {
                throw new \InvalidArgumentException(sprintf('The images add up to more than %d GB uncompressed.', self::TOTAL_MAX_BYTES >> 30));
            }

            $position = 0;
            foreach ($entries as $name) {
                $stat = $zip->statName($name);
                if (false === $stat || $stat['size'] > self::ENTRY_MAX_BYTES) {
                    throw new \InvalidArgumentException(sprintf('%s exceeds %d MB.', $name, self::ENTRY_MAX_BYTES >> 20));
                }

                // Key is generated, never derived from the entry name: no path traversal.
                $key = StorageKeys::original($chapter, pathinfo($name, \PATHINFO_EXTENSION));
                $stream = $zip->getStream($name);
                if (false === $stream) {
                    throw new \RuntimeException(sprintf('Cannot read %s from the archive.', $name));
                }
                try {
                    $this->defaultStorage->writeStream($key, $stream);
                    $written[] = $key;
                } finally {
                    fclose($stream);
                }

                $chapter->getPages()->add(new Page($chapter, $position += 10, $key));
            }

            $this->em->flush();
        } catch (\Throwable $e) {
            // No page rows were flushed: drop the originals written so far.
            foreach ($written as $key) {
                $this->defaultStorage->delete($key);
            }
            throw $e;
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }

        foreach ($chapter->getPages() as $page) {
            $this->bus->dispatch(new GenerateDerivative((int) $page->getId()));
        }
    }

    // Re-upload is rejected: deleting the existing pages is an explicit admin step.
    private function assertNoPages(Chapter $chapter): void
    {
        if ($this->em->getRepository(Page::class)->count(['chapter' => $chapter]) > 0) {
            throw new \InvalidArgumentException('This chapter already has pages. Delete them before uploading again.');
        }
    }
}
