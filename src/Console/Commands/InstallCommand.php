<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class InstallCommand extends Command
{
    protected $signature = 'grant:install
        {--force : Overwrite published config, dbmate migration, and enums}
        {--key-type= : User key type: int, uuid, or ulid (defaults to grant.key_type)}
        {--migrate : Run dbmate up when the dbmate binary is available}';

    protected $description = 'Install Grant configuration, dbmate migration, and enums.';

    /** @var array<string, array<string, array{0: string, 1: string}>> */
    private const array TYPES = [
        'sqlite' => [
            'int' => ['INTEGER NOT NULL', 'INTEGER NULL'],
            'uuid' => ['VARCHAR(36) NOT NULL', 'VARCHAR(36) NULL'],
            'ulid' => ['VARCHAR(26) NOT NULL', 'VARCHAR(26) NULL'],
        ],
        'mysql' => [
            'int' => ['BIGINT UNSIGNED NOT NULL', 'BIGINT UNSIGNED NULL'],
            'uuid' => ['CHAR(36) NOT NULL', 'CHAR(36) NULL'],
            'ulid' => ['CHAR(26) NOT NULL', 'CHAR(26) NULL'],
        ],
        'pgsql' => [
            'int' => ['BIGINT NOT NULL', 'BIGINT NULL'],
            'uuid' => ['CHAR(36) NOT NULL', 'CHAR(36) NULL'],
            'ulid' => ['CHAR(26) NOT NULL', 'CHAR(26) NULL'],
        ],
    ];

    public function __construct(private readonly Repository $config)
    {
        parent::__construct();
    }

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'grant-config', '--force' => $this->option('force')]);

        $keyType = $this->resolveKeyType();

        if ($keyType === null) {
            return self::FAILURE;
        }

        $driver = $this->resolveDriver();

        if ($driver === null) {
            return self::FAILURE;
        }

        $this->updateKeyType($files, $keyType);
        $this->writeMigration($files, $driver, $keyType);
        $this->writeEnums($files);

        if ($this->option('migrate')) {
            $this->runDbmate();
        }

        $this->components->info('Grant installed successfully.');
        $this->components->bulletList([
            'Add `Shipfastlabs\Grant\HasRoles`, `Shipfastlabs\Grant\Auth\Authenticatable`, and Laravel\'s `Authorizable` to your Doctrine user entity.',
            'Set `auth.providers.users.model` to that entity and `auth.providers.users.driver` to `doctrine`.',
            'Run `php artisan migrate`, then `dbmate up` with `DATABASE_URL` set, to create the role_assignments table.',
        ]);

        return self::SUCCESS;
    }

    private function resolveKeyType(): ?string
    {
        $keyType = $this->option('key-type') ?? $this->config->get('grant.key_type', 'int');

        if (! is_string($keyType) || ! isset(self::TYPES['sqlite'][$keyType])) {
            $this->components->error('The --key-type option must be one of: int, uuid, ulid.');

            return null;
        }

        return $keyType;
    }

    private function resolveDriver(): ?string
    {
        $connection = $this->config->get('grant.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : $this->config->get('database.default', 'mysql');
        $connection = is_string($connection) ? $connection : 'mysql';

        $driver = $this->config->get("database.connections.{$connection}.driver", $connection);
        $driver = match ($driver) {
            'mariadb' => 'mysql',
            default => is_string($driver) ? $driver : $connection,
        };

        if (! isset(self::TYPES[$driver])) {
            $this->components->error(InvalidConfigurationException::driver($driver)->getMessage());

            return null;
        }

        return $driver;
    }

    private function updateKeyType(Filesystem $files, string $keyType): void
    {
        $path = config_path('grant.php');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);
        $updated = preg_replace("/'key_type' => '[^']*'/", "'key_type' => '{$keyType}'", $contents, 1);

        if (is_string($updated) && $updated !== $contents) {
            $files->put($path, $updated);
        }
    }

    private function writeMigration(Filesystem $files, string $driver, string $keyType): void
    {
        $sql = $files->get(__DIR__."/../../../database/dbmate/{$driver}/create_role_assignments.sql.stub");
        [$key, $keyNullable] = self::TYPES[$driver][$keyType];
        $sql = str_replace(['{{key}}', '{{key_nullable}}'], [$key, $keyNullable], $sql);

        $directory = $this->migrationDirectory();
        $existing = $files->glob($directory.'/*_create_role_assignments.sql')[0] ?? null;
        $existing = is_string($existing) ? $existing : null;

        if ($existing !== null && ! $this->option('force')) {
            $this->components->warn("Migration [{$existing}] already exists; use --force to overwrite.");

            return;
        }

        $path = $existing ?? $directory.'/'.date('YmdHis').'_create_role_assignments.sql';

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $sql);

        $this->components->info("Migration [{$path}] created successfully.");
    }

    private function writeEnums(Filesystem $files): void
    {
        foreach (['permissions' => 'Permission', 'roles' => 'Role'] as $key => $name) {
            $enum = $this->config->get("grant.{$key}");

            if (! is_string($enum) || enum_exists($enum)) {
                continue;
            }

            $path = app_path("Enums/{$name}.php");
            $files->ensureDirectoryExists(dirname($path));
            $files->copy(__DIR__."/../../../stubs/{$name}.php.stub", $path);
            $this->components->info("Enum [{$path}] created successfully.");
        }
    }

    private function migrationDirectory(): string
    {
        $path = $this->config->get('grant.dbmate_path', 'db/migrations');
        $path = is_string($path) && $path !== '' ? $path : 'db/migrations';

        $absolute = Str::startsWith($path, ['/', '\\']) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;

        return $absolute ? $path : base_path($path);
    }

    private function runDbmate(): void
    {
        $binary = (new ExecutableFinder)->find('dbmate');

        if ($binary === null) {
            $this->components->warn('The [dbmate] binary was not found on PATH; run `dbmate up` manually.');

            return;
        }

        $process = new Process([$binary, 'up'], base_path());
        $process->setTimeout(null);

        if ($process->run() !== 0) {
            $this->components->error('dbmate up failed: '.trim($process->getErrorOutput()));

            return;
        }

        $this->components->info('dbmate migrations applied.');
    }
}
