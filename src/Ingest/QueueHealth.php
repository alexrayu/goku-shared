<?php

declare(strict_types=1);

namespace App\Ingest;

use Doctrine\DBAL\Connection;
use Twig\Attribute\AsTwigFunction;

// The worker runs from the host's cron. Jobs that sit ready long past a cron interval mean the
// cron job is missing or failing; admin shows a warning (templates/bundles/EasyAdminBundle).
final class QueueHealth
{
    private const STALL_AFTER = '-10 minutes';

    public function __construct(private readonly Connection $connection)
    {
    }

    // Oldest job ready for longer than STALL_AFTER, in UTC like the transport's own timestamps.
    #[AsTwigFunction('queue_stalled_since')]
    public function stalledSince(): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $oldest = $this->connection->fetchOne(
            'SELECT MIN(available_at) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NULL AND available_at < ?',
            ['default', (new \DateTimeImmutable(self::STALL_AFTER, $utc))->format('Y-m-d H:i:s')],
        );

        return \is_string($oldest) ? new \DateTimeImmutable($oldest, $utc) : null;
    }
}
