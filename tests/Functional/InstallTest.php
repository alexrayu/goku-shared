<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class InstallTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testEmptySiteRedirectsEverythingToInstaller(): void
    {
        $client = static::createClient();

        foreach (['/', '/login', '/admin', '/no/such/page'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/install', null, $path);
        }
    }

    public function testMismatchedPasswordsCreateNoUser(): void
    {
        $client = static::createClient();
        $client->request('POST', '/install', [
            'email' => 'a@example.com', 'password' => 'x', 'password_confirm' => 'y',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=alert]', 'Passwords do not match');
        UserFactory::assert()->count(0);
    }

    public function testInstallCreatesFirstUserLogsInAndClosesInstaller(): void
    {
        $client = static::createClient();
        $client->request('POST', '/install', [
            'email' => 'artist@example.com', 'password' => 'secret', 'password_confirm' => 'secret',
        ]);

        self::assertResponseRedirects('/admin');
        UserFactory::assert()->count(1);
        UserFactory::assert()->exists(['email' => 'artist@example.com']);

        // Logged in: the dashboard forwards to the Work list instead of the login form.
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/work');

        $client->request('GET', '/install');
        self::assertResponseRedirects('/login');
    }

    public function testPendingMigrationSendsPagesToTheUpdaterUntilALoggedInUserRunsIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne();
        // An upgrade as the site sees it: a migration the database has not recorded.
        $connection = static::getContainer()->get(Connection::class);
        $version = (string) $connection->fetchOne('SELECT version FROM doctrine_migration_versions');
        $connection->executeStatement('DELETE FROM doctrine_migration_versions');
        static::getContainer()->get('cache.system')->clear();

        $client->request('GET', '/');
        self::assertResponseRedirects('/install');
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/install');
        self::assertSelectorTextContains('main', 'Log in to finish the update');

        // The baseline cannot run twice, so restore the record instead of submitting the form.
        $client->loginUser($user);
        $client->request('GET', '/install');
        self::assertSelectorExists('button:contains("Update the database")');
        $connection->insert('doctrine_migration_versions', ['version' => $version, 'executed_at' => '2026-10-14 00:00:00', 'execution_time' => 1]);

        $client->request('GET', '/install');
        self::assertResponseRedirects('/login');
    }

    public function testExistingUserMeansNoRedirect(): void
    {
        $client = static::createClient();
        UserFactory::createOne();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }
}
