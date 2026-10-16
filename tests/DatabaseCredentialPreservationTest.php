<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseCredentialPreservationTest extends TestCase
{
    private function applyExisting(string $type, array $options = [], bool $redactPassword = false): array
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function databases(): array { return []; }
        };
        $profile = new class($type, $options) {
            public function __construct(private string $type, private array $options) {}
            public function name(): string { return 'production'; }
            public function config(): array {
                return ['coolify' => ['databases' => [['name' => 'main', 'type' => $this->type,
                    'user' => 'creation-user', 'password' => 'creation-secret']],
                    'database_options' => ['main' => $this->options]]];
            }
        };
        $provider = new CoolifyProvider;
        $scope = (new \ReflectionMethod($provider, 'resourceTag'))->invoke($provider, $project, $profile);
        $name = (new \ReflectionMethod($provider, 'databaseResourceName'))->invoke($provider, 'main', $project, $profile);
        $tag = $scope.'-db-'.substr(hash('sha256', $name), 0, 10);
        $keys = match ($type) {
            'mysql' => ['mysql_database', 'mysql_user', 'mysql_password'],
            'mariadb' => ['mariadb_database', 'mariadb_user', 'mariadb_password'],
            'postgres' => ['postgres_db', 'postgres_user', 'postgres_password'],
        };
        $existing = ['uuid' => 'database-1', 'name' => $name, 'tags' => ['shippercli-managed', $tag],
            'environment_uuid' => 'environment-1', 'server_uuid' => 'server-1',
            'internal_db_host' => 'database.internal', 'internal_db_port' => 3306,
            $keys[0] => 'existing_database', $keys[1] => 'existing_user', $keys[2] => 'existing-secret'];
        if ($redactPassword) unset($existing[$keys[2]]);
        $calls = [];
        $client = new CoolifyApiClient('https://coolify.example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $existing): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'GET' && $path === '/databases') return [$existing];
                if ($method === 'GET' && $path === '/databases/database-1') return $existing;
                return [];
            });
        $environment = (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider,
            $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1');
        return [$calls, $environment];
    }

    public function testOrdinaryDeclarationCredentialsDoNotUpdateExistingDatabases(): void
    {
        foreach (['mysql', 'mariadb', 'postgres'] as $type) {
            [$calls, $environment] = $this->applyExisting($type);
            $updates = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'PATCH' && $call[1] === '/databases/database-1'));
            self::assertSame([], $updates, $type);
            self::assertSame('existing_database', $environment['DB_DATABASE'], $type);
            self::assertSame('existing_user', $environment['DB_USERNAME'], $type);
            self::assertSame('existing-secret', $environment['DB_PASSWORD'], $type);
        }
    }

    public function testRedactedExistingPasswordDoesNotBecomeTheCreationPassword(): void
    {
        [$calls, $environment] = $this->applyExisting('mysql', [], true);
        self::assertArrayNotHasKey('DB_PASSWORD', $environment);
        self::assertSame('existing_user', $environment['DB_USERNAME']);
        foreach ($calls as $call) self::assertNotSame('PATCH', $call[0]);
        self::assertNotContains('creation-secret', $environment);
    }

    public function testExplicitProviderCredentialOptionsRemainUpdates(): void
    {
        [$calls, $environment] = $this->applyExisting('mysql', ['mysql_password' => 'explicit-secret']);
        $updates = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'PATCH' && $call[1] === '/databases/database-1'));
        self::assertSame([['PATCH', '/databases/database-1', ['mysql_password' => 'explicit-secret']]], $updates);
        self::assertSame('existing_user', $environment['DB_USERNAME']);
        self::assertSame('existing_database', $environment['DB_DATABASE']);
        self::assertSame('explicit-secret', $environment['DB_PASSWORD']);
    }
}
