<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseCredentialPreservationTest extends TestCase
{
    private function applyExisting(string $type, array $options = [], bool $redactPassword = false, array $extra = []): array
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
        $existing = array_merge($existing, $extra);
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
        return [$calls, $environment, $project, $profile];
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

    public function testUnknownExistingUserAndDatabaseAreNotFilledFromCreationDefaults(): void
    {
        [$calls, $environment] = $this->applyExisting('mysql', [], true,
            ['mysql_user' => null, 'mysql_database' => null]);
        self::assertArrayNotHasKey('DB_USERNAME', $environment);
        self::assertArrayNotHasKey('DB_DATABASE', $environment);
        self::assertArrayNotHasKey('DB_PASSWORD', $environment);
    }

    public function testExplicitUpdatesTakePrecedenceOverStaleConnectionUrls(): void
    {
        foreach (['mysql', 'mariadb', 'postgres'] as $type) {
            $keys = match ($type) {
                'mysql' => ['mysql_user', 'mysql_password', 'mysql_database'],
                'mariadb' => ['mariadb_user', 'mariadb_password', 'mariadb_database'],
                'postgres' => ['postgres_user', 'postgres_password', 'postgres_db'],
            };
            $options = [$keys[0] => 'updated-user', $keys[1] => 'updated:@secret', $keys[2] => 'updated_database'];
            [$calls, $environment] = $this->applyExisting($type, $options, false,
                ['internal_db_url' => 'mysql://old-user:old-secret@database.internal:3306/old_database']);
            self::assertSame('updated-user', $environment['DB_USERNAME'], $type);
            self::assertSame('updated:@secret', $environment['DB_PASSWORD'], $type);
            self::assertSame('updated_database', $environment['DB_DATABASE'], $type);
            self::assertSame('database.internal', $environment['DB_HOST'], $type);
            self::assertSame('updated:@secret', $environment['SHIPPER_DB_MAIN_PASSWORD'], $type);
        }
    }

    public function testPasswordOnlyUpdatePreservesUrlPlacementAndUser(): void
    {
        [$calls, $environment] = $this->applyExisting('mysql', ['mysql_password' => 'explicit-secret'], false,
            ['internal_db_url' => 'mysql://known-user:old-secret@known.internal:3307/known_database']);
        self::assertSame('explicit-secret', $environment['DB_PASSWORD']);
        self::assertSame('known-user', $environment['DB_USERNAME']);
        self::assertSame('known_database', $environment['DB_DATABASE']);
        self::assertSame('known.internal', $environment['DB_HOST']);
        self::assertSame('3307', $environment['DB_PORT']);
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
    public function testExplicitEmptyPasswordUpdateIsDeliveredAsKnownEmpty(): void
    {
        [$calls, $environment, $project, $profile] = $this->applyExisting('mysql', ['mysql_password' => '']);
        $updates = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'PATCH'));
        self::assertSame([['PATCH', '/databases/database-1', ['mysql_password' => '']]], $updates);
        self::assertArrayHasKey('DB_PASSWORD', $environment);
        self::assertSame('', $environment['DB_PASSWORD']);
        self::assertArrayHasKey('SHIPPER_DB_MAIN_PASSWORD', $environment);
        self::assertSame('', $environment['SHIPPER_DB_MAIN_PASSWORD']);
        $applicationCalls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$applicationCalls): array {
                $applicationCalls[] = [$method, $path, $body];
                return [];
            });
        (new \ReflectionMethod(new CoolifyProvider, 'applyEnvironment'))->invoke(new CoolifyProvider,
            $client, 'app', $project, $profile, $environment);
        $passwordWrites = array_values(array_filter($applicationCalls, static fn (array $call): bool =>
            $call[0] === 'POST' && $call[1] === '/applications/app/envs'
            && in_array($call[2]['key'] ?? null, ['DB_PASSWORD', 'SHIPPER_DB_MAIN_PASSWORD'], true)));
        self::assertCount(2, $passwordWrites);
        foreach ($passwordWrites as $call) self::assertSame('', $call[2]['value']);

    }

}
