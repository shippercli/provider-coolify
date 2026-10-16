<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class ServiceEnvironmentLiteralTest extends TestCase
{
    public function testServiceSecretsStayLiteralAndOnlyStaleManagedVariablesAreDeleted(): void
    {
        $secret = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
        $environment = new class($secret) {
            public function __construct(private string $secret) {}
            public function variables(): array { return ['PASSWORD' => $this->secret, 'TOKEN' => $this->secret, 'EMPTY' => '']; }
        };
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class($environment) {
            public function __construct(private object $environment) {}
            public function name(): string { return 'production'; }
            public function environment(): object { return $this->environment; }
            public function config(): array { return ['coolify' => ['docker_compose' => 'services: {}']]; }
        };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $resource = ['uuid' => 'owned', 'name' => 'storefront-production-stack',
            'tags' => ['shippercli-managed', $scope], 'server_uuid' => 'server', 'environment_uuid' => 'environment'];
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $resource): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'GET' && str_starts_with($path, '/services?tag=')) return [$resource];
                if ($method === 'GET' && $path === '/services/owned/envs') return [
                    ['uuid' => 'existing', 'key' => 'PASSWORD', 'comment' => 'Managed by ShipperCLI'],
                    ['uuid' => 'stale', 'key' => 'OLD', 'comment' => 'Managed by ShipperCLI'],
                    ['uuid' => 'unmanaged', 'key' => 'OTHER', 'comment' => 'Unmanaged'],
                ];
                return [];
            });
        $provider = new CoolifyProvider([], $client);
        self::assertSame('owned', (new \ReflectionMethod($provider, 'applyCustomService'))->invoke(
            $provider, $client, $project, $profile, 'project', 'environment', 'production', 'server',
            ['PASSWORD' => 'database-default', 'DATABASE_TOKEN' => $secret],
        ));
        $writes = array_values(array_filter($calls, fn ($call) => in_array($call[0], ['POST', 'PATCH'], true) && $call[1] === '/services/owned/envs'));
        self::assertCount(4, $writes);
        self::assertSame(['PATCH', 'POST', 'POST', 'POST'], array_column($writes, 0));
        self::assertSame(['PASSWORD' => $secret, 'DATABASE_TOKEN' => $secret, 'TOKEN' => $secret, 'EMPTY' => ''],
            array_column(array_column($writes, 2), 'value', 'key'));
        foreach ($writes as $write) self::assertSame('Managed by ShipperCLI', $write[2]['comment']);
        self::assertSame([['DELETE', '/services/owned/envs/stale', null]],
            array_values(array_filter($calls, fn ($call) => $call[0] === 'DELETE')));
    }
}
