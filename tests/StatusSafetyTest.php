<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;
use ShipperCli\ProviderCoolify\CoolifyMcpClient;

final class StatusSafetyTest extends TestCase
{
    public function testPublicStatusDoesNotEchoDeploymentLogsOrExtraSecretFields(): void
    {
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['fixture', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $taskPrefix = 'shipper-'.substr(hash('sha256', json_encode(['fixture', 'production'], JSON_THROW_ON_ERROR)), 0, 10).'-';
        $client = new CoolifyApiClient('https://coolify.example.test', 'synthetic-token', transport: static function (string $method, string $path) use ($scope, $taskPrefix): array {
            self::assertSame('GET', $method);
            if ($path === '/applications/app-1') return ['uuid' => 'app-1', 'id' => 1, 'name' => 'fixture', 'status' => 'running', 'tags' => ['shippercli-managed', $scope]];
            if ($path === '/applications/app-1/rollback-images') return ['current' => 'current-image', 'images' => [['tag' => 'previous-image', 'created_at' => '2026-10-16T12:00:00Z', 'is_current' => false, 'credentials' => 'synthetic-status-secret']], 'extra' => 'synthetic-status-secret'];
            if ($path === '/applications/app-1/scheduled-tasks') return [['uuid' => 'task-1', 'name' => $taskPrefix.'nightly', 'command' => 'echo synthetic-status-secret', 'frequency' => 'daily', 'enabled' => true]];
            if ($path === '/applications/app-1/scheduled-tasks/task-1/executions') return [['uuid' => 'execution-1', 'status' => 'success', 'created_at' => '2026-10-16T12:00:00Z', 'output' => 'synthetic-status-secret', 'extra' => ['token' => 'synthetic-status-secret']]];
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
        $mcp = new CoolifyMcpClient('https://coolify.example.test', 'synthetic-token', transport: static function (array $message): array {
            if ($message['method'] === 'notifications/initialized') return ['status' => 202, 'headers' => [], 'body' => ''];
            $result = match ($message['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25'],
                'tools/list' => ['tools' => [['name' => 'list_application_previews']]],
                'tools/call' => ['structuredContent' => ['data' => ['previews' => [['uuid' => 'preview-1', 'pull_request_id' => 42, 'status' => 'running', 'env' => 'synthetic-status-secret']]]]],
                default => throw new \RuntimeException('Unexpected fixture RPC'),
            };
            return ['status' => 200, 'headers' => [], 'body' => json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR)];
        });
        $status = (new CoolifyProvider(['application_uuid' => 'app-1', 'mcp_enabled' => true], $client, $mcp))->status($project, $profile);
        self::assertStringNotContainsString('synthetic-status-secret', json_encode($status, JSON_THROW_ON_ERROR));
        self::assertSame('deployment-1', $status['latest_deployment']['deployment_uuid']);
        self::assertSame('finished', $status['deployment_history'][0]['status']);
        self::assertSame('deployment-1', $status['running_deployments'][0]['deployment_uuid']);
        self::assertSame('execution-1', $status['scheduled_tasks'][0]['latest_execution']['uuid']);
        self::assertArrayNotHasKey('command', $status['scheduled_tasks'][0]);
        self::assertSame('previous-image', $status['rollback_images']['images'][0]['tag']);
        self::assertFalse($status['rollback_images']['images'][0]['is_current']);
        self::assertSame(42, $status['previews'][0]['pull_request_id']);
    }
    public function testDeploymentSummaryDropsUnknownFieldsAndNestedValues(): void
    {
        $provider = new CoolifyProvider;
        $method = new \ReflectionMethod($provider, 'statusRecordSummary');
        self::assertNull($method->invoke($provider, null));
        self::assertSame(['deployment_uuid' => 'deployment-1', 'created_at' => null], $method->invoke($provider, [
            'deployment_uuid' => 'deployment-1', 'created_at' => null,
            'status' => ['token' => 'synthetic-secret'], 'logs' => 'synthetic-secret',
            'unknown' => 'synthetic-secret',
        ]));
    }

    public function testBackupExecutionStatusDoesNotEchoRawOutput(): void
    {
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['fixture', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        foreach ([false, true, 'null', 'assoc', 'scalar'] as $wrapped) {
            $client = new CoolifyApiClient('https://coolify.example.test', 'synthetic-token', transport: static function (string $method, string $path) use ($scope, $wrapped): array {
                self::assertSame('GET', $method);
                if ($path === '/databases') return [['uuid' => 'db-1', 'name' => 'main', 'type' => 'mysql', 'tags' => ['shippercli-managed', $scope.'-db-0123456789']]];
                if ($path === '/databases/db-1') return ['uuid' => 'db-1', 'name' => 'main', 'type' => 'mysql', 'status' => 'running'];
                if ($path === '/databases/db-1/backups/backup-1/executions') {
                    if ($wrapped === 'null') return ['executions' => null];
                    if ($wrapped === 'assoc') return ['executions' => ['token' => 'synthetic-backup-secret']];
                    if ($wrapped === 'scalar') return ['executions' => ['synthetic-backup-secret']];
                    $rows = [['uuid' => 'backup-execution-1', 'status' => 'success', 'output' => 'synthetic-backup-secret', 'credentials' => ['token' => 'synthetic-backup-secret']]];
                    return $wrapped ? ['executions' => $rows] : $rows;
                }
                throw new \RuntimeException('Unexpected backup status request');
            });
            $project = new class {
                public function name(): string { return 'fixture'; }
                public function databases(): array { return [new class { public function name(): string { return 'main'; } }]; }
            };
            $profile = new class {
                public function name(): string { return 'production'; }
                public function config(): array { return []; }
            };
            $provider = new CoolifyProvider(['database_backups' => ['main' => ['uuid' => 'backup-1']]], $client);
            $failure = null;
            $result = null;
            try { $result = (new \ReflectionMethod($provider, 'standaloneDatabaseStatuses'))->invoke($provider, $project, $profile, ''); }
            catch (\RuntimeException $error) { $failure = $error; }
            if (is_string($wrapped)) {
                self::assertInstanceOf(\RuntimeException::class, $failure);
                self::assertSame('Coolify backup execution status is unreadable.', $failure->getMessage());
                continue;
            }
            self::assertNull($failure);
            self::assertStringNotContainsString('synthetic-backup-secret', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertSame(['uuid' => 'backup-execution-1', 'status' => 'success'], $result[0]['backup_executions'][0]);
        }
    }

    public function testMalformedRollbackImageStatusIsRefused(): void
    {
        $provider = new CoolifyProvider;
        $method = new \ReflectionMethod($provider, 'rollbackImageStatusSummary');
        foreach ([['images' => null], ['images' => ['token' => 'synthetic-secret']], ['images' => ['synthetic-secret']], ['images' => [['tag' => null]]]] as $payload) {
            $failure = null;
            try { $method->invoke($provider, $payload); }
            catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame('Coolify rollback image status is unreadable.', $failure->getMessage());
        }
    }

}
