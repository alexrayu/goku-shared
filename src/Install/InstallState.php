<?php

declare(strict_types=1);

namespace App\Install;

use App\Entity\User;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

// Installer steps in order: database configured, schema created, first user. The site counts as
// installed once any user exists; only that positive answer is cached. After an upgrade, pending
// migrations reopen the installer as the updater.
final class InstallState
{
    private const KEY = 'app.installed';
    private const SCHEMA_CURRENT_KEY = 'app.schema_current';
    // Marks the DATABASE_URL shipped in .env; the installer's .env.local replaces it.
    private const PLACEHOLDER = '!ChangeMe!';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(DATABASE_URL)%')]
        private readonly string $databaseUrl,
        #[Autowire(service: 'cache.system')]
        private readonly CacheItemPoolInterface $releaseCache,
        /** @var \Closure(): DependencyFactory */
        #[AutowireServiceClosure('doctrine.migrations.dependency_factory')]
        private readonly \Closure $migrations,
    ) {
    }

    // Migrations this release ships that the database has not run. "None" is cached in cache.system,
    // which lives in the per-release cache dir: each upgrade checks once, then it costs a cache read.
    public function hasPendingMigrations(): bool
    {
        $item = $this->releaseCache->getItem(self::SCHEMA_CURRENT_KEY);
        if ($item->isHit()) {
            return false;
        }

        $pending = ($this->migrations)()->getMigrationStatusCalculator()->getNewMigrations()->count() > 0;
        if (!$pending) {
            $this->releaseCache->save($item->set(true));
        }

        return $pending;
    }

    // Once false, the database form is closed for good: a later outage never reopens it.
    public function isConfigured(): bool
    {
        return !str_contains($this->databaseUrl, self::PLACEHOLDER);
    }

    public function hasSchema(): bool
    {
        return $this->em->getConnection()->createSchemaManager()->tablesExist(['app_user']);
    }

    public function isInstalled(): bool
    {
        $item = $this->cache->getItem(self::KEY);
        if ($item->isHit()) {
            return true;
        }
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $exists = null !== $this->em->createQuery('SELECT u.id FROM '.User::class.' u')
                ->setMaxResults(1)
                ->getOneOrNullResult();
        } catch (TableNotFoundException) {
            return false;
        }
        if ($exists) {
            $this->cache->save($item->set(true));
        }

        return $exists;
    }
}
