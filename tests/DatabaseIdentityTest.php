<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseIdentityTest extends TestCase
{
    public function testCollidingNamesBlockValidationPlanningAndApplyBeforeApiCalls(): void
    {
        foreach ([
            ['app-db', 'app_db', ''],
            [str_repeat('a', 50).'x', str_repeat('a', 50).'y', ''],
            [str_repeat('a', 44).'x', str_repeat('a', 44).'y', '42'],
            ['app_${PROJECT_NAME}', 'app_storefront', ''],
        ] as [$first, $second, $pr]) {
            $calls = [];
            $client = new CoolifyApiClient('https://coolify.example.test/api/v1', 'token', transport: static function ($method, $path, $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];
                return [];
            });
            $project = new class {
                public function name(): string { return 'storefront'; }
                public function repository(): array { return ['url' => 'https://github.com/example/storefront']; }
            };
            $profile = new class($first, $second, $pr) {
                public function __construct(private string $first, private string $second, private string $pr) {}
                public function name(): string { return 'production'; }
                public function config(): array
                {
                    return ['coolify' => [...($this->pr === '' ? [] : ['pull_request_id' => $this->pr]), 'databases' => [
                        ['name' => $this->first], ['name' => $this->second],
                    ]]];
                }
            };
            $provider = new CoolifyProvider(['api_token' => 'token', 'url' => 'https://coolify.example.test', 'server_uuid' => 'server-123'], $client);
            $errors = $provider->validate($project, $profile);
            self::assertCount(1, $errors);
            self::assertStringContainsString('must remain unique', $errors[0]);
            self::assertTrue($provider->plan($project, $profile)['blocked']);
            self::assertFalse($provider->apply($project, $profile));
            self::assertSame([], $calls);
        }
    }

    public function testLaterDatabaseCannotFillMissingDefaultConnectionFields(): void
    {
        $provider = new CoolifyProvider;
        $merge = new \ReflectionMethod($provider, 'mergeDatabaseEnvironment');
        $environment = [];
        $first = [&$environment, 'mysql', 'main', ['mysql_database' => 'main', 'mysql_user' => 'main'], 'first-secret', 'main'];
        $merge->invokeArgs($provider, $first);
        $second = [&$environment, 'postgres', 'reports', [
            'internal_db_host' => 'reports.internal', 'internal_db_port' => 5432,
            'postgres_db' => 'reports', 'postgres_user' => 'reporter',
        ], 'second-secret', 'reporter'];
        $merge->invokeArgs($provider, $second);
        self::assertSame('mysql', $environment['DB_CONNECTION']);
        self::assertSame('main', $environment['DB_DATABASE']);
        self::assertSame('first-secret', $environment['DB_PASSWORD']);
        self::assertArrayNotHasKey('DB_HOST', $environment);
        self::assertArrayNotHasKey('DB_PORT', $environment);
        self::assertSame('reports.internal', $environment['SHIPPER_DB_REPORTS_HOST']);
        self::assertSame('second-secret', $environment['SHIPPER_DB_REPORTS_PASSWORD']);
    }
}
