<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Entity\Chapter;
use App\Entity\Page;
use App\Enum\PageStatus;
use App\Image\ImageProcessor;
use App\Image\UnprocessableImage;
use App\Ingest\Message\GenerateChapterCovers;
use App\Ingest\Message\GenerateDerivative;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class DerivativeGenerator
{
    private const MAX_WIDTH = 1200;
    // Passed to the reader controller (assets/controllers/reader_controller.js) by the reader template.
    public const TILE_SIZE = 128;
    // Without it lossy WebP seams gradients and screentones at tile edges. 4 px still left traces in
    // the browser; 8 px makes 144 px cells, aligned to WebP's 16 px blocks, for ~25% more bytes.
    public const TILE_GUTTER = 8;
    // Open Graph's recommended link-preview size. Shared with chosen work covers (CustomImages).
    public const COVER_WIDTH = 1200;
    public const COVER_HEIGHT = 630;
    // 5:7, close to a printed manga volume; 2x the listing card width.
    public const THUMB_WIDTH = 400;
    public const THUMB_HEIGHT = 560;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilesystemOperator $defaultStorage,
        private readonly ImageProcessor $images,
        private readonly StorageTempFile $tempFile,
    ) {
    }

    #[AsMessageHandler]
    public function generate(GenerateDerivative $message): void
    {
        $page = $this->em->find(Page::class, $message->pageId);
        if (null === $page) {
            return;
        }
        $page->setStatus(PageStatus::Processing);
        $this->em->flush();

        $source = $this->tempFile->download($page->getOriginalKey());
        $target = $source.'.webp';
        try {
            [$width, $height, $order] = $this->images->toScrambledWebp($source, $target, self::MAX_WIDTH, self::TILE_SIZE, self::TILE_GUTTER);
            $this->store($target, StorageKeys::derivative($page));

            if ($page->getChapter()->getPages()->first() === $page) {
                $this->storeCovers($page->getChapter(), $source);
            }
        } catch (UnprocessableImage $e) {
            // Same input, same outcome: fail the page now instead of after the retries.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } finally {
            $this->remove($source, $target);
        }

        // Reading-copy dimensions: what the reader displays and reserves space for.
        $page->setReadingCopy($width, $height, $order)->setStatus(PageStatus::Ready);
        $this->em->flush();
    }

    // A reorder put another page first: rebuild the chapter's cover and thumbnail from it.
    #[AsMessageHandler]
    public function regenerateCovers(GenerateChapterCovers $message): void
    {
        $first = $this->em->find(Chapter::class, $message->chapterId)?->getPages()->first();
        if (!$first instanceof Page) {
            return;
        }

        $source = $this->tempFile->download($first->getOriginalKey());
        try {
            $this->storeCovers($first->getChapter(), $source);
        } finally {
            $this->remove($source);
        }
    }

    // Unscrambled link preview and listing thumbnail, both from the chapter's first page.
    private function storeCovers(Chapter $chapter, string $source): void
    {
        $cover = $source.'.jpg';
        $thumb = $source.'.thumb.webp';
        try {
            $this->images->toCover($source, $cover, self::COVER_WIDTH, self::COVER_HEIGHT);
            $this->store($cover, StorageKeys::cover($chapter));
            $this->images->toCover($source, $thumb, self::THUMB_WIDTH, self::THUMB_HEIGHT);
            $this->store($thumb, StorageKeys::thumbnail($chapter));
        } finally {
            $this->remove($cover, $thumb);
        }
    }

    private function remove(string ...$files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function store(string $file, string $key): void
    {
        $stream = fopen($file, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot read the generated image.');
        }
        try {
            $this->defaultStorage->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    // Retries exhausted: make the failure visible on the page instead of leaving it "processing".
    #[AsEventListener]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof GenerateDerivative) {
            return;
        }

        $page = $this->em->find(Page::class, $message->pageId);
        if (null !== $page) {
            $page->setStatus(PageStatus::Failed);
            $this->em->flush();
        }
    }
}
