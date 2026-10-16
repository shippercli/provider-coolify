<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseEnvironmentLiteralTest extends TestCase
{
    public function testSecretEnvironmentValuesAreLiteralOnCreateAndUpdate(): void
    {
        $secret = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class($secret) {
            public function __construct(private string $secret) {}
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['database_environment_variables' => ['main' => [
                'PASSWORD' => $this->secret, 'TOKEN' => $this->secret, 'EMPTY' => '',
            ]]]]; }
        };
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport: function ($method, $path, $body) use (&$calls): array {
            $calls[] = [$method, $path, $body];
            return $method === 'GET' ? [
                ['uuid' => 'existing', 'key' => 'PASSWORD', 'comment' => 'Managed by ShipperCLI'],
                ['uuid' => 'stale', 'key' => 'OLD', 'comment' => 'Managed by ShipperCLI'],
                ['uuid' => 'unmanaged', 'key' => 'OTHER', 'comment' => 'Unmanaged'],
            ] : [];
        });
        $provider = new CoolifyProvider;
        (new \ReflectionMethod($provider, 'applyDatabaseEnvironmentVariables'))->invoke($provider, $client, 'database', $project, $profile, 'main');
        $writes = array_values(array_filter($calls, fn ($call) => in_array($call[0], ['POST', 'PATCH'], true)));
        self::assertCount(3, $writes);
        self::assertSame('PATCH', $writes[0][0]);
        self::assertSame($secret, $writes[0][2]['value']);
        self::assertSame('POST', $writes[1][0]);
        self::assertSame($secret, $writes[1][2]['value']);
        self::assertSame('', $writes[2][2]['value']);
        $deletes = array_values(array_filter($calls, fn ($call) => $call[0] === 'DELETE'));
        self::assertSame([['DELETE', '/databases/database/envs/stale', null]], $deletes);
    }
}
