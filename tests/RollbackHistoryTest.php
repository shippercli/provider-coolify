<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class RollbackHistoryTest extends TestCase
{
    public function testAutomaticRollbackRequiresARecordedProductionDeployment(): void
    {
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['fixture', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $deployments = [
            ['status' => 'finished', 'pull_request_id' => 42, 'commit' => 'shared-cache'],
            ['status' => 'failed', 'pull_request_id' => 0, 'commit' => 'shared-cache'],
            ['status' => 'finished', 'pull_request_id' => 0, 'commit' => 'HEAD', 'docker_registry_image_tag' => null],
        ];
        $queued = [];
        $client = new CoolifyApiClient('https://coolify.example.test', 'synthetic-token', transport: static function (
            string $method,
            string $path,
            ?array $body,
        ) use ($scope, &$deployments, &$queued): array {
            if ($method === 'POST' && $path === '/applications/app-1/rollback') {
                $queued[] = $body['commit'];

                return ['deployment_uuid' => 'rollback-1'];
            }

            return match ($path) {
                '/applications/app-1' => ['uuid' => 'app-1', 'name' => 'fixture-production', 'tags' => ['shippercli-managed', $scope]],
                '/applications/app-1/rollback-images' => ['current' => 'current', 'images' => [
                    ['tag' => 'shared-cache', 'created_at' => '2026-11-12T10:00:00Z'],
                    ['tag' => 'current', 'is_current' => true],
                    ['tag' => 'previous', 'created_at' => '2026-11-11T10:00:00Z'],
                ]],
                '/deployments/applications/app-1?skip=0&take=20' => ['deployments' => $deployments],
                '/applications?tag='.$scope => [],
                default => throw new \RuntimeException('Unexpected request: '.$method.' '.$path),
            };
        });
        $provider = new CoolifyProvider(['application_uuid' => 'app-1'], $client);
        $project = new class { public function name(): string { return 'fixture'; } };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };

        self::assertFalse($provider->rollback($project, $profile));
        self::assertStringContainsString('--release', $provider->getLastError());
        self::assertSame([], $queued);
        self::assertTrue($provider->rollback($project, $profile, 'previous'), $provider->getLastError());

        $deployments[] = [
            'status' => 'finished', 'pull_request_id' => 0, 'commit' => 'HEAD', 'docker_registry_image_tag' => 'previous',
        ];
        self::assertTrue($provider->rollback($project, $profile), $provider->getLastError());
        self::assertSame(['previous', 'previous'], $queued);
    }
}
