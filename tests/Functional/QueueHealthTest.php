<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class QueueHealthTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminWarnsOnlyAboutJobsWaitingPastTheCronInterval(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->loginUser(UserFactory::createOne());
        $this->queue('-2 minutes');

        $client->request('GET', '/admin/work');
        self::assertSelectorNotExists('.alert-warning');

        $this->queue('-1 hour');
        $client->request('GET', '/admin/work');
        self::assertSelectorTextContains('.alert-warning', 'Uploads are not being processed');
    }

    // A ready job as the Doctrine transport stores it (UTC).
    private function queue(string $availableAt): void
    {
        $at = (new \DateTimeImmutable($availableAt, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        static::getContainer()->get(Connection::class)->insert('messenger_messages', [
            'body' => '', 'headers' => '[]', 'queue_name' => 'default', 'created_at' => $at, 'available_at' => $at,
        ]);
    }
}
