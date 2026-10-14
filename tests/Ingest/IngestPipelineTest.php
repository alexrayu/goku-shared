<?php

declare(strict_types=1);

namespace App\Tests\Ingest;

use App\Entity\Chapter;
use App\Entity\Page;
use App\Enum\PageStatus;
use App\Factory\ChapterFactory;
use App\Factory\PageFactory;
use App\Ingest\ArchiveIngestor;
use App\Ingest\DerivativeGenerator;
use App\Ingest\Message\GenerateChapterCovers;
use App\Ingest\Message\GenerateDerivative;
use App\Ingest\Message\IngestArchive;
use App\Ingest\StorageKeys;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

// Runs both handlers synchronously on a generated archive; needs the vips CLI.
final class IngestPipelineTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/ingest-test-'.bin2hex(random_bytes(4));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $storage = static::getContainer()->get('default.storage');
        foreach (['incoming', 'originals', 'derivatives', 'covers', 'thumbs'] as $dir) {
            $storage->deleteDirectory($dir);
        }
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    public function testArchiveBecomesNaturallyOrderedReadyPages(): void
    {
        $chapter = ChapterFactory::createOne();
        // Width encodes the source name, so the stored order is checkable after resizing.
        $key = $this->storeArchive($chapter, [
            'page_10.png' => [2400, 100],
            'page_2.png' => [50, 60],
            'page_1.jpg' => [40, 60],
            'page_11_strip.png' => [100, 20000],
            '__MACOSX/._page_1.jpg' => null,
            '.DS_Store' => null,
            'credits.txt' => null,
        ]);

        static::getContainer()->get(ArchiveIngestor::class)->ingest(new IngestArchive((int) $chapter->getId(), $key));

        $generator = static::getContainer()->get(DerivativeGenerator::class);
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(4, $sent);
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(GenerateDerivative::class, $message);
            $generator->generate($message);
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pages = $em->getRepository(Page::class)->findBy(['chapter' => $chapter->getId()], ['position' => 'ASC']);

        self::assertSame([10, 20, 30, 40], array_map(static fn (Page $p) => $p->getPosition(), $pages));
        // page_1 and page_2 kept as is, page_10 shrunk to 1200 wide, the strip capped at 113 tile rows
        // (14464 px), so the scrambled WebP, 144 px per cell row, stays within WebP's 16383 px.
        self::assertSame([[40, 60], [50, 60], [1200, 50]], array_map(
            static fn (Page $p) => [$p->getWidth(), $p->getHeight()],
            \array_slice($pages, 0, 3),
        ));
        self::assertSame(14464, $pages[3]->getHeight());
        self::assertLessThan(100, (int) $pages[3]->getWidth());

        $storage = static::getContainer()->get('default.storage');
        foreach ($pages as $page) {
            self::assertSame(PageStatus::Ready, $page->getStatus());
            self::assertTrue($storage->fileExists($page->getOriginalKey()));
            self::assertTrue($storage->fileExists(StorageKeys::derivative($page)));
            $tiles = (int) ceil($page->getWidth() / DerivativeGenerator::TILE_SIZE) * (int) ceil($page->getHeight() / DerivativeGenerator::TILE_SIZE);
            self::assertCount($tiles, explode(',', (string) $page->getTileOrder()));
        }
        self::assertTrue($storage->fileExists(StorageKeys::cover($chapter)));
        self::assertTrue($storage->fileExists(StorageKeys::thumbnail($chapter)));
        self::assertFalse($storage->fileExists($key), 'Incoming archive is deleted after ingest.');

        // Prod runs as an unprivileged user and Nginx reads through the group; dev as root hides bad modes.
        $derivative = static::getContainer()->getParameter('kernel.project_dir').'/var/storage-test/'.StorageKeys::derivative($pages[0]);
        self::assertSame(0o640, fileperms($derivative) & 0o7777);
        self::assertSame(0o750, fileperms(\dirname($derivative)) & 0o7777);
    }

    public function testChapterWithPagesRejectsArchiveWithoutRetry(): void
    {
        $chapter = ChapterFactory::createOne();
        PageFactory::createOne(['chapter' => $chapter]);
        $key = $this->storeArchive($chapter, ['page_1.png' => [10, 10]]);

        try {
            static::getContainer()->get(ArchiveIngestor::class)->ingest(new IngestArchive((int) $chapter->getId(), $key));
            self::fail('Expected an unrecoverable failure.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertFalse(static::getContainer()->get('default.storage')->fileExists($key));
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testArchiveOverThePageCapIsRejectedBeforeAnythingIsWritten(): void
    {
        $chapter = ChapterFactory::createOne();
        // Content is never read: the cap applies to the central directory first.
        $entries = [];
        for ($i = 1; $i <= 1001; ++$i) {
            $entries["page_$i.png"] = null;
        }
        $key = $this->storeArchive($chapter, $entries);

        try {
            static::getContainer()->get(ArchiveIngestor::class)->ingest(new IngestArchive((int) $chapter->getId(), $key));
            self::fail('Expected an unrecoverable failure.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('at most 1000', $e->getMessage());
        }

        self::assertSame(0, PageFactory::count(['chapter' => $chapter]));
        self::assertFalse(static::getContainer()->get('default.storage')->directoryExists(sprintf('originals/%d', $chapter->getId())));
    }

    public function testCoversAreRebuiltFromTheCurrentFirstPage(): void
    {
        $chapter = ChapterFactory::createOne();
        $original = StorageKeys::original($chapter, 'png');
        $file = $this->workDir.'/first.png';
        imagepng(imagecreatetruecolor(800, 1200) ?: self::fail(), $file);
        $storage = static::getContainer()->get('default.storage');
        $storage->write($original, (string) file_get_contents($file));
        PageFactory::createOne(['chapter' => $chapter, 'position' => 10, 'originalKey' => $original]);

        static::getContainer()->get(DerivativeGenerator::class)->regenerateCovers(new GenerateChapterCovers((int) $chapter->getId()));

        self::assertTrue($storage->fileExists(StorageKeys::cover($chapter)));
        self::assertTrue($storage->fileExists(StorageKeys::thumbnail($chapter)));
    }

    /**
     * @param array<string, array{int<1, max>, int<1, max>}|null> $entries name => image size, or null for a junk text entry
     */
    private function storeArchive(Chapter $chapter, array $entries): string
    {
        $zipPath = $this->workDir.'/chapter.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $size) {
            if (null === $size) {
                $zip->addFromString($name, 'junk');
                continue;
            }
            $file = $this->workDir.'/'.basename($name);
            $image = imagecreatetruecolor($size[0], $size[1]) ?: self::fail();
            match (strtolower(pathinfo($file, \PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => imagejpeg($image, $file),
                'webp' => imagewebp($image, $file),
                default => imagepng($image, $file),
            };
            $zip->addFile($file, $name);
        }
        $zip->close();

        $key = StorageKeys::incoming($chapter);
        $stream = fopen($zipPath, 'rb');
        self::assertIsResource($stream);
        static::getContainer()->get('default.storage')->writeStream($key, $stream);
        fclose($stream);

        return $key;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
