<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Work;
use App\Enum\WorkType;
use App\Factory\ChapterFactory;
use App\Factory\UserFactory;
use App\Factory\WorkFactory;
use App\Ingest\StorageKeys;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BrandingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // One kernel: the array cache holding the settings survives between requests, as in production.
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        foreach (['site', 'covers', 'thumbs'] as $dir) {
            static::getContainer()->get('default.storage')->deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function testDefaultsComeFromTheEnvUntilSettingsAreSaved(): void
    {
        UserFactory::createOne();

        $this->client->request('GET', '/');
        self::assertSelectorTextSame('.intro h1', 'Goku');
        self::assertStringContainsString('--accent: #ff6a4d', (string) $this->client->getResponse()->getContent());
        self::assertSame('/favicon.svg', $this->client->getCrawler()->filter('link[rel=icon]')->attr('href'));

        $this->client->request('GET', '/favicon.svg');
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('fill="#ff6a4d"', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/about');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSettingsRebrandTheSite(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        // First visit creates the row from the env defaults.
        $this->client->request('GET', '/admin/site-settings');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Save and continue editing')->form([
            'SiteSettings[name]' => 'Ink & Tide',
            'SiteSettings[tagline]' => 'Comics by Ink.',
            'SiteSettings[bio]' => 'Draws at night.',
            'SiteSettings[links]' => 'Bluesky | https://bsky.app/profile/ink',
            'SiteSettings[accent]' => '#1d4ed8',
        ]);
        $this->attach($form, 'SiteSettings[logoUpload]', $this->image(1500, 400));
        $this->client->submit($form);
        self::assertResponseRedirects();

        // A fresh anonymous visitor sees the saved brand at once: saving cleared the cached copy.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextSame('.intro h1', 'Ink & Tide');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('--accent: #1d4ed8; --on-accent: #ffffff', $content);
        $logo = (string) $crawler->filter('.site-header .brand img')->attr('src');
        self::assertSame('/media/site/logo-1.webp', $logo);
        self::assertSame($logo, $crawler->filter('link[rel=icon]')->attr('href'));

        $this->client->request('GET', $logo);
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
        [$width, $height] = getimagesizefromstring((string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/storage-test/'.StorageKeys::logo())) ?: [0, 0];
        self::assertSame([420, 112], [$width, $height], 'Fitted to the header height, aspect kept.');

        $crawler = $this->client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.bio', 'Draws at night.');
        self::assertSame('https://bsky.app/profile/ink', $crawler->filter('.links a[rel~=me]')->attr('href'));
    }

    public function testChosenCoverReplacesTheFirstPageAndCanBeRemoved(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $work = WorkFactory::createOne(['slug' => 'saga', 'type' => WorkType::Series]);
        ChapterFactory::createOne(['work' => $work, 'published' => true]);

        $crawler = $this->client->request('GET', sprintf('/admin/work/%d/edit', $work->getId()));
        $form = $crawler->selectButton('Save changes')->form();
        $this->attach($form, 'Work[coverUpload]', $this->image(1000, 1500));
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/saga');
        $thumb = sprintf('/media/work/%d/thumb-1.webp', $work->getId());
        self::assertSame($thumb, $crawler->filter('.work-head img.cover')->attr('src'));
        self::assertSame(sprintf('http://localhost/media/work/%d/cover-1.jpg', $work->getId()), $crawler->filter('meta[property="og:image"]')->attr('content'));
        $this->client->request('GET', $thumb);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->loginUser(UserFactory::first());
        $crawler = $this->client->request('GET', sprintf('/admin/work/%d/edit', $work->getId()));
        $this->client->submit($crawler->selectButton('Save changes')->form(['Work[removeCover]' => true]));

        $saved = static::getContainer()->get(EntityManagerInterface::class)->find(Work::class, $work->getId());
        self::assertNull($saved?->getCoverVersion());
        self::assertFalse(static::getContainer()->get('default.storage')->fileExists(StorageKeys::workThumbnail($work)));
    }

    public function testCoverOfAnUnpublishedWorkStaysPrivate(): void
    {
        UserFactory::createOne();
        $work = WorkFactory::createOne();
        ChapterFactory::createOne(['work' => $work, 'published' => false]);
        $work->setCoverVersion(1);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        static::getContainer()->get('default.storage')->write(StorageKeys::workThumbnail($work), 'bytes');

        $this->client->request('GET', sprintf('/media/work/%d/thumb-1.webp', $work->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    private function attach(Form $form, string $field, string $path): void
    {
        $input = $form[$field];
        self::assertInstanceOf(FileFormField::class, $input);
        $input->upload($path);
    }

    /**
     * A real PNG with transparency.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function image(int $width, int $height): string
    {
        $path = sprintf('%s/brand_%s.png', sys_get_temp_dir(), bin2hex(random_bytes(4)));
        $image = imagecreatetruecolor($width, $height) ?: self::fail();
        imagesavealpha($image, true);
        imagepng($image, $path);

        return $path;
    }
}
