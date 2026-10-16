<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class StatusSafetyTest extends TestCase
{
    public function testPublicStatusDoesNotEchoDeploymentLogsOrExtraSecretFields(): void
    {
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['fixture', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $client = new CoolifyApiClient('https://coolify.example.test', 'synthetic-token', transport: static function (string $method, string $path) use ($scope): array {
            self::assertSame('GET', $method);
            if ($path === '/applications/app-1') return ['uuid' => 'app-1', 'id' => 1, 'name' => 'fixture', 'status' => 'running', 'tags' => ['shippercli-managed', $scope]];
            if ($path === '/deployments' || str_starts_with($path, '/deployments/applications/app-1?')) return [[
                'deployment_uuid' => 'deployment-1', 'application_id' => 1, 'status' => 'finished',
                'created_at' => '2026-10-16T12:00:00Z', 'logs' => 'DB_PASSWORD=synthetic-status-secret',
                'env' => ['TOKEN' => 'synthetic-status-secret'], 'extra' => ['token' => 'synthetic-status-secret'],
            ]];
            return [];
        });
        $project = new class { public function name(): string { return 'fixture'; } };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };
        $status = (new CoolifyProvider(['application_uuid' => 'app-1'], $client))->status($project, $profile);
        self::assertStringNotContainsString('synthetic-status-secret', json_encode($status, JSON_THROW_ON_ERROR));
        self::assertSame('deployment-1', $status['latest_deployment']['deployment_uuid']);
        self::assertSame('finished', $status['deployment_history'][0]['status']);
        self::assertSame('deployment-1', $status['running_deployments'][0]['deployment_uuid']);
    }
    public function testDeploymentSummaryDropsUnknownFieldsAndNestedValues(): void
    {
        $provider = new CoolifyProvider;
        $method = new \ReflectionMethod($provider, 'deploymentStatusSummary');
        self::assertNull($method->invoke($provider, null));
        self::assertSame(['deployment_uuid' => 'deployment-1', 'created_at' => null], $method->invoke($provider, [
            'deployment_uuid' => 'deployment-1', 'created_at' => null,
            'status' => ['token' => 'synthetic-secret'], 'logs' => 'synthetic-secret',
            'unknown' => 'synthetic-secret',
        ]));
    }

}
