<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Entity\Chapter;
use App\Entity\Page;
use App\Enum\PageStatus;
use App\Ingest\Message\GenerateChapterCovers;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Messenger\MessageBusInterface;

// Reorders a chapter's pages, removes them, or removes the whole chapter with their stored files.
// Rows go first, files after the flush: a failure leaves orphan files, never rows pointing at missing files.
final class ChapterPages
{
    // Same spacing as ingest; inserts take the free positions in between.
    private const POSITION_GAP = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilesystemOperator $defaultStorage,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Moves a page right after $afterId, or to the front without one. It takes the middle of the gap
     * between its new neighbours; only when no position is left are the chapter's pages respaced.
     * A new first page rebuilds the chapter's cover and thumbnail.
     *
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function move(Chapter $chapter, int $pageId, ?int $afterId): void
    {
        $this->assertIdle($chapter);
        $pages = $chapter->getPages()->getValues();
        $page = $this->pageOf($pages, $pageId);
        $others = array_values(array_filter($pages, static fn (Page $other): bool => $other !== $page));
        $index = null === $afterId ? 0 : 1 + (int) array_search($this->pageOf($others, $afterId), $others, true);

        $lower = 0 === $index ? 0 : $others[$index - 1]->getPosition();
        $upper = ($others[$index] ?? null)?->getPosition() ?? $lower + 2 * self::POSITION_GAP;
        if ($page->getPosition() > $lower && $page->getPosition() < $upper) {
            return;
        }
        $newFirst = 0 === $index ? $page : $others[0];

        if ($upper - $lower >= 2) {
            $page->setPosition(intdiv($lower + $upper, 2));
            $this->em->flush();
        } else {
            array_splice($others, $index, 0, [$page]);
            $this->respace($others);
        }

        if ($newFirst !== $pages[0]) {
            $this->bus->dispatch(new GenerateChapterCovers((int) $chapter->getId()));
        }
    }

    /**
     * Empties the chapter so a new archive can be uploaded.
     *
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function clear(Chapter $chapter): void
    {
        $this->assertIdle($chapter);
        $files = StorageKeys::pageFiles($chapter);

        $chapter->getPages()->clear();
        $this->em->flush();
        $this->deleteFiles($files['directories'], $files['files']);
    }

    /**
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function deleteChapter(Chapter $chapter): void
    {
        $this->assertIdle($chapter);
        // Keys need the id, which is gone after the flush.
        $files = StorageKeys::pageFiles($chapter);
        $incoming = StorageKeys::incomingDirectory($chapter);

        $this->em->remove($chapter);
        $this->em->flush();
        $this->deleteFiles([...$files['directories'], $incoming], $files['files']);
    }

    /**
     * @param list<Page> $pages
     */
    private function pageOf(array $pages, int $id): Page
    {
        foreach ($pages as $page) {
            if ($page->getId() === $id) {
                return $page;
            }
        }

        throw new \InvalidArgumentException('The page is no longer in this chapter. Reload the page.');
    }

    /**
     * The database checks the unique (chapter, position) row by row, so rewriting in place could hit a row
     * not yet updated; every page is parked on a negative position first.
     *
     * @param list<Page> $pages in their new order
     */
    private function respace(array $pages): void
    {
        $this->em->wrapInTransaction(function () use ($pages): void {
            foreach ($pages as $i => $page) {
                $page->setPosition(-1 - $i);
            }
            $this->em->flush();
            foreach ($pages as $i => $page) {
                $page->setPosition(($i + 1) * self::POSITION_GAP);
            }
            $this->em->flush();
        });
    }

    // The worker would write files for a page that no longer exists.
    private function assertIdle(Chapter $chapter): void
    {
        foreach ($chapter->getPages() as $page) {
            if (\in_array($page->getStatus(), [PageStatus::Pending, PageStatus::Processing], true)) {
                throw new \InvalidArgumentException('Pages are still being processed. Try again when they are ready or failed.');
            }
        }
    }

    /**
     * @param list<string> $directories
     * @param list<string> $files
     */
    private function deleteFiles(array $directories, array $files): void
    {
        foreach ($directories as $directory) {
            $this->defaultStorage->deleteDirectory($directory);
        }
        foreach ($files as $file) {
            $this->defaultStorage->delete($file);
        }
    }
}
