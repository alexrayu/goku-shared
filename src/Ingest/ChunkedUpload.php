<?php

declare(strict_types=1);

namespace App\Ingest;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

// Reassembles an archive sent in slices below the host's request size limit. One upload per id at a
// time; a new upload for the same id starts the staging file over. Staged next to the storage, so a
// large archive lands on the same disk and quota.
final class ChunkedUpload
{
    private const MAX_CHUNK = 16 * 1024 * 1024;
    // Room in post_max_size for the form fields around the slice.
    private const FIELDS = 64 * 1024;
    // Staged files untouched this long belong to abandoned uploads.
    private const ABANDONED_AFTER = 86400;

    public function __construct(
        #[Autowire('%env(resolve:STORAGE_PATH)%/staging')]
        private readonly string $dir,
    ) {
    }

    // Slice size the client sends: what the host accepts per request, at most MAX_CHUNK.
    public static function chunkSize(): int
    {
        return max(self::FIELDS, (int) min(UploadedFile::getMaxFilesize(), self::MAX_CHUNK) - self::FIELDS);
    }

    /**
     * Appends $chunk at $offset of a $size-byte file.
     *
     * @return File|null the assembled file after the last slice; the caller removes it
     *
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function append(string $id, UploadedFile $chunk, int $offset, int $size): ?File
    {
        if ($size < 1 || $size > ArchiveIngestor::TOTAL_MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('Upload an archive of at most %d GB.', ArchiveIngestor::TOTAL_MAX_BYTES >> 30));
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0o750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }

        // The first slice starts over; every other one must continue exactly where the file ends.
        $path = sprintf('%s/%s.part', $this->dir, $id);
        if (0 === $offset) {
            $this->removeAbandoned();
        }
        clearstatcache(true, $path);
        $length = (int) $chunk->getSize();
        if ((0 !== $offset && (!is_file($path) || filesize($path) !== $offset)) || $offset + $length > $size) {
            throw new \InvalidArgumentException('The upload was interrupted. Start it again.');
        }

        $in = fopen($chunk->getPathname(), 'rb');
        $out = fopen($path, 0 === $offset ? 'wb' : 'ab');
        if (false === $in || false === $out) {
            throw new \RuntimeException('Cannot stage the upload.');
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        return $offset + $length === $size ? new File($path) : null;
    }

    private function removeAbandoned(): void
    {
        foreach (glob($this->dir.'/*.part') ?: [] as $file) {
            if (filemtime($file) < time() - self::ABANDONED_AFTER) {
                @unlink($file);
            }
        }
    }
}
