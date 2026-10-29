<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseLiteralPasswordTest extends TestCase
{
    public function testPasswordOptionsRemainLiteralWhileNamesStillInterpolate(): void
    {
        $secret = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
        $fields = ['mysql_root_password', 'mysql_password', 'mariadb_root_password', 'mariadb_password',
            'postgres_password', 'redis_password', 'mongo_initdb_root_password', 'clickhouse_admin_password',
            'dragonfly_password', 'keydb_password'];
        $options = array_fill_keys($fields, $secret);
        $options['description'] = '${PROJECT_NAME}-${PROFILE}';
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class($options) {
            public function __construct(private array $options) {}
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['database_options' => ['main' => $this->options]]]; }
        };
        $provider = new CoolifyProvider;
        $result = (new \ReflectionMethod($provider, 'databaseProviderOptions'))->invoke($provider, $project, $profile, ['name' => 'main']);
        foreach ($fields as $field) self::assertSame($secret, $result[$field]);
        self::assertSame('storefront-production', $result['description']);
    }

    public function testDeclaredCreationPasswordReachesApiAndEnvironmentUnchanged(): void
    {
        $secret = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class($secret) {
            public function __construct(private string $secret) {}
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['databases' => [['name' => 'main', 'type' => 'postgresql', 'user' => 'user', 'password' => $this->secret]]]]; }
        };
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport: function ($method, $path, $body) use (&$calls, $secret): array {
            $calls[] = [$method, $path, $body];
            if ($method === 'POST' && $path === '/databases/postgresql') return ['uuid' => 'created'];
            if ($method === 'GET' && $path === '/databases/created') return ['uuid' => 'created', 'internal_db_host' => 'db.internal',
                'postgres_db' => 'main', 'postgres_user' => 'user', 'postgres_password' => $secret,
                'server_uuid' => 'server', 'environment_uuid' => 'environment'];
            return [];
        });
        $provider = new CoolifyProvider;
        $environment = (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, $profile,
            'project', 'environment', 'production', 'server');
        $creates = array_values(array_filter($calls, fn ($call) => $call[0] === 'POST' && $call[1] === '/databases/postgresql'));
        self::assertCount(1, $creates);
        self::assertSame($secret, $creates[0][2]['postgres_password']);
        self::assertSame($secret, $environment['DB_PASSWORD']);
        self::assertSame($secret, $environment['SHIPPER_DB_MAIN_PASSWORD']);
    }
}
