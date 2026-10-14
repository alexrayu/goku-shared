<?php

declare(strict_types=1);

namespace App\Install;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;

// Installer steps that shared hosts without a shell cannot do by hand: database credentials into
// .env.local, then the migrations.
final class DatabaseSetup
{
    public function __construct(
        private readonly KernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Connects with the given credentials and returns the DATABASE_URL for them.
     *
     * @throws \InvalidArgumentException with a message safe to show the installer
     */
    public function dsn(string $host, int $port, string $name, string $user, string $password): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new \InvalidArgumentException('Enter a host name or IP address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Enter a port between 1 and 65535.');
        }
        if ('' === $name || '' === $user) {
            throw new \InvalidArgumentException('Enter the database name and user.');
        }

        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_mysql', 'host' => $host, 'port' => $port, 'dbname' => $name, 'user' => $user, 'password' => $password,
            ]);
            $version = (string) $connection->fetchOne('SELECT VERSION()');
            $connection->close();
        } catch (DbalException $e) {
            throw new \InvalidArgumentException('Cannot connect: '.$e->getMessage(), 0, $e);
        }

        // serverVersion spares Doctrine a version query per request.
        return sprintf(
            'mysql://%s:%s@%s:%d/%s?serverVersion=%s&charset=utf8mb4',
            rawurlencode($user), rawurlencode($password), $host, $port, rawurlencode($name), rawurlencode($version),
        );
    }

    /**
     * Writes .env.local with the database URL, a fresh APP_SECRET and the updater token. Never
     * overwrites an existing file.
     *
     * @return string|null the contents, when the file could not be written and must be created by hand
     */
    public function writeEnvLocal(string $dsn): ?string
    {
        // Single quotes: Dotenv takes the value literally. The URL-encoded DSN holds no quote.
        $contents = sprintf(
            "# Written by the installer.\nAPP_SECRET=%s\nDATABASE_URL='%s'\n# Asked for when an upgrade changes the database.\nUPDATE_TOKEN=%s\n",
            bin2hex(random_bytes(16)), $dsn, bin2hex(random_bytes(16)),
        );
        $path = $this->projectDir.'/.env.local';
        if (file_exists($path) || false === @file_put_contents($path, $contents, \LOCK_EX)) {
            return $contents;
        }
        // Holds the database password: keep other accounts on the host out.
        @chmod($path, 0o600);

        return null;
    }

    /**
     * @throws \RuntimeException with the command output
     */
    public function migrate(): void
    {
        // Hosts stop web requests after max_execution_time; a migration cut halfway leaves the schema
        // broken. Some hosts disable set_time_limit() itself.
        if (\function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $input = new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]);
        if (0 !== $application->run($input, $output)) {
            throw new \RuntimeException($output->fetch());
        }
    }
}
