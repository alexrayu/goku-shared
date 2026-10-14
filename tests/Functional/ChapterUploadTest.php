<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ChapterFactory;
use App\Factory\PageFactory;
use App\Factory\UserFactory;
use App\Ingest\Message\IngestArchive;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ChapterUploadTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // One kernel for the test, so the in-memory transport keeps what the request sent.
        $this->client->disableReboot();
        $this->client->loginUser(UserFactory::createOne());
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('default.storage')->deleteDirectory('incoming');
        parent::tearDown();
    }

    public function testArchiveIsStoredAndQueued(): void
    {
        $chapter = ChapterFactory::createOne();

        $this->submit((int) $chapter->getId(), $this->zipFile());

        self::assertResponseRedirects();
        $sent = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $sent);
        self::assertCount(1, $sent->getSent());
        $message = $sent->getSent()[0]->getMessage();
        self::assertInstanceOf(IngestArchive::class, $message);
        self::assertTrue(static::getContainer()->get('default.storage')->fileExists($message->archiveKey));
    }

    public function testNonZipIsRejected(): void
    {
        $chapter = ChapterFactory::createOne();
        $text = tempnam(sys_get_temp_dir(), 'up').'.zip';
        file_put_contents($text, 'not a zip');

        $this->submit((int) $chapter->getId(), $text);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'Upload a .zip or .cbz archive.');
    }

    public function testChapterWithPagesIsRejected(): void
    {
        $chapter = ChapterFactory::createOne();
        PageFactory::createOne(['chapter' => $chapter]);

        $this->submit((int) $chapter->getId(), $this->zipFile());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'already has pages');
    }

    public function testSlicesAreReassembledAndQueuedAfterTheLast(): void
    {
        $chapter = ChapterFactory::createOne();
        $zip = (string) file_get_contents($this->zipFile());
        $half = intdiv(\strlen($zip), 2);

        self::assertSame(['done' => false], $this->sendChunk((int) $chapter->getId(), substr($zip, 0, $half), 0, \strlen($zip)));
        $result = $this->sendChunk((int) $chapter->getId(), substr($zip, $half), $half, \strlen($zip));

        self::assertArrayHasKey('redirect', $result);
        $sent = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $sent);
        $message = $sent->getSent()[0]->getMessage();
        self::assertInstanceOf(IngestArchive::class, $message);
        self::assertSame($zip, static::getContainer()->get('default.storage')->read($message->archiveKey));
    }

    public function testNewUploadRemovesAbandonedStagedFiles(): void
    {
        $chapter = ChapterFactory::createOne();
        $staging = static::getContainer()->getParameter('kernel.project_dir').'/var/storage-test/staging';
        @mkdir($staging, 0o750, true);
        touch($staging.'/chapter-0.part', time() - 2 * 86400);

        $this->sendChunk((int) $chapter->getId(), 'xx', 0, 100);

        self::assertFileDoesNotExist($staging.'/chapter-0.part');
        self::assertFileExists(sprintf('%s/chapter-%d.part', $staging, $chapter->getId()));
    }

    public function testSliceOutOfSequenceIsRefused(): void
    {
        $chapter = ChapterFactory::createOne();

        $result = $this->sendChunk((int) $chapter->getId(), 'xx', 10, 100);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'The upload was interrupted. Start it again.'], $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendChunk(int $chapterId, string $bytes, int $offset, int $size): array
    {
        $crawler = $this->client->request('GET', sprintf('/admin/chapter/%d/upload', $chapterId));
        $form = $crawler->filter('[data-controller=chapter-upload]');
        $path = (string) tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $bytes);

        $this->client->request('POST', (string) $form->attr('data-chapter-upload-url-value'), [
            '_token' => $form->attr('data-chapter-upload-token-value'), 'name' => 'chapter.cbz', 'offset' => $offset, 'size' => $size,
        ], ['chunk' => new UploadedFile($path, 'chunk', null, null, true)]);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function submit(int $chapterId, string $file): Crawler
    {
        $crawler = $this->client->request('GET', sprintf('/admin/chapter/%d/upload', $chapterId));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Upload')->form();
        $field = $form['archive'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($file);

        return $this->client->submit($form);
    }

    private function zipFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'up').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('page_1.png', 'x');
        $zip->close();

        return $path;
    }
}
