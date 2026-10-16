<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyMcpClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class CoolifyPreviewInventoryIntegrationTest extends TestCase
{
    public function testMcpPreviewInventoryMapsIntoShipperOrphanCleanupSiteShape(): void
    {
        $project = new class
        {
            public function name(): string
            {
                return 'storefront';
            }
        };
        $profile = new class
        {
            public function name(): string
            {
                return 'preview';
            }

            public function config(): array
            {
                return ['application_uuid' => 'app-uuid'];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->config()[$key] ?? $default;
            }
        };

        $api = new CoolifyApiClient('https://coolify.example.test', 'token', transport: static function (string $method, string $path): array {
            self::assertSame('GET', $method);
            if ($path === '/applications' || $path === '/databases') {
                return [];
            }
            self::assertSame('/applications/app-uuid', $path);

            return ['uuid' => 'app-uuid', 'name' => 'storefront', 'tags' => [
                'shippercli-managed',
                'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'preview'], JSON_THROW_ON_ERROR)), 0, 16),
            ]];
        });
        $mcp = new CoolifyMcpClient('https://coolify.example.test', 'token', transport: static function (array $message): array {
            if ($message['method'] === 'notifications/initialized') {
                return ['status' => 202, 'headers' => [], 'body' => ''];
            }
            $result = match ($message['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25'],
                'tools/list' => ['tools' => [['name' => 'list_application_previews']]],
                'tools/call' => ['structuredContent' => ['data' => ['previews' => [
                    ['pull_request_id' => 83, 'fqdn' => 'https://83.preview.example.test', 'status' => 'running'],
                ]]]],
                default => [],
            };

            return [
                'status' => 200,
                'headers' => [],
                'body' => \json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR),
            ];
        });

        $provider = new CoolifyProvider(['mcp_enabled' => true], $api, $mcp);

        self::assertSame([[
            'site_id' => -83,
            'domain' => 'https://83.preview.example.test',
        ]], $provider->listSites($project, $profile));
    }

    public function testSyntheticPreviewSiteIdRoutesCleanupToCoolifyPrDeletion(): void
    {
        $project = new class
        {
            public function name(): string
            {
                return 'storefront';
            }
        };
        $profile = new class
        {
            public function name(): string
            {
                return 'preview';
            }

            public function config(): array
            {
                return ['application_uuid' => 'app-uuid'];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->config()[$key] ?? $default;
            }
        };

        $expectedResourceTag = '';
        $apiCalls = [];
        $api = new CoolifyApiClient('https://coolify.example.test', 'token', transport: static function (string $method, string $path) use (&$expectedResourceTag, &$apiCalls): array {
            $apiCalls[] = [$method, $path];
            if ($method === 'GET' && $path === '/applications/app-uuid') {
                return [
                    'uuid' => 'app-uuid',
                    'name' => 'storefront',
                    'tags' => [
                        ['name' => 'shippercli-managed'],
                        ['name' => $expectedResourceTag],
                    ],
                ];
            }

            return [];
        });
        $provider = new CoolifyProvider(['mcp_enabled' => true], $api);
        $tagMethod = new \ReflectionMethod(CoolifyProvider::class, 'resourceTag');
        $expectedResourceTag = $tagMethod->invoke($provider, $project, $profile);

        self::assertTrue($provider->deleteSiteWithDatabases($project, $profile, -83));
        self::assertContains(['DELETE', '/applications/app-uuid/previews/83'], $apiCalls);
        self::assertNotContains(['DELETE', '/applications/app-uuid'], $apiCalls);
    }

    public function testOwnedPreviewDatabasesRemainDiscoverableAfterCoolifyDeletesThePreview(): void
    {
        $project = new class
        {
            public function name(): string
            {
                return 'storefront';
            }
        };
        $profile = new class
        {
            public function name(): string
            {
                return 'preview';
            }

            public function config(): array
            {
                return [
                    'application_uuid' => 'app-uuid',
                    'domain' => 'https://pr-${GITHUB_PR_NUMBER}.preview.example.test',
                ];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->config()[$key] ?? $default;
            }
        };
        $resourceTag = 'shippercli-'.\substr(\hash('sha256', json_encode(['storefront', 'preview'], JSON_THROW_ON_ERROR)), 0, 16);
        $previewTag = $resourceTag.'-pr-43';
        $apiCalls = [];
        $api = new CoolifyApiClient('https://coolify.example.test', 'token', transport: static function (string $method, string $path) use ($resourceTag, $previewTag, &$apiCalls): array {
            $apiCalls[] = [$method, $path];
            if ($method === 'GET' && $path === '/applications/app-uuid') {
                return ['uuid' => 'app-uuid', 'name' => 'storefront-preview', 'tags' => [$resourceTag, 'shippercli-managed']];
            }
            if ($method === 'GET' && $path === '/applications') {
                return [['uuid' => 'worker-43', 'name' => 'storefront-preview-pr-43-queue-worker-1', 'tags' => [$previewTag, 'shippercli-managed']]];
            }
            if ($method === 'GET' && $path === '/applications?tag='.\rawurlencode($previewTag)) {
                return [['uuid' => 'worker-43', 'name' => 'storefront-preview-pr-43-queue-worker-1', 'tags' => [$previewTag, 'shippercli-managed']]];
            }
            if ($method === 'GET' && $path === '/databases') {
                return [['uuid' => 'database-43', 'name' => 'storefront-preview-pr-43', 'tags' => [$previewTag.'-db-0123456789', 'shippercli-managed']]];
            }

            return [];
        });
        $mcp = new CoolifyMcpClient('https://coolify.example.test', 'token', transport: static function (array $message): array {
            if ($message['method'] === 'notifications/initialized') {
                return ['status' => 202, 'headers' => [], 'body' => ''];
            }
            $result = match ($message['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25'],
                'tools/list' => ['tools' => [['name' => 'list_application_previews']]],
                'tools/call' => ['structuredContent' => ['data' => ['previews' => []], '_pagination' => ['next' => null]]],
                default => [],
            };

            return [
                'status' => 200,
                'headers' => [],
                'body' => \json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR),
            ];
        });
        $provider = new CoolifyProvider(['mcp_enabled' => true], $api, $mcp);

        $sites = $provider->listSites($project, $profile);

        self::assertSame([[
            'site_id' => -43,
            'domain' => 'https://pr-43.preview.example.test',
        ]], $sites);
        self::assertTrue($provider->deleteSiteWithDatabases($project, $profile, $sites[0]['site_id']));
        self::assertContains(['DELETE', '/applications/app-uuid/previews/43'], $apiCalls);
        self::assertContains(['DELETE', '/applications/worker-43'], $apiCalls);
        self::assertContains(['DELETE', '/databases/database-43'], $apiCalls);
    }

    public function testOrphanedPreviewResourcesAreCleanedAfterTheParentApplicationIsGone(): void
    {
        $project = new class
        {
            public function name(): string
            {
                return 'storefront';
            }
        };
        $profile = new class
        {
            public function name(): string
            {
                return 'preview';
            }

            public function config(): array
            {
                return [
                    'application_uuid' => 'app-uuid',
                    'domain' => 'https://pr-${GITHUB_PR_NUMBER}.preview.example.test',
                ];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->config()[$key] ?? $default;
            }
        };
        $resourceTag = 'shippercli-'.\substr(\hash('sha256', json_encode(['storefront', 'preview'], JSON_THROW_ON_ERROR)), 0, 16);
        $previewTag = $resourceTag.'-pr-43';
        $apiCalls = [];
        $api = new CoolifyApiClient('https://coolify.example.test', 'token', transport: static function (string $method, string $path) use ($previewTag, &$apiCalls): array {
            $apiCalls[] = [$method, $path];
            if ($method === 'GET' && $path === '/applications/app-uuid') {
                throw new \RuntimeException('Coolify API request failed (HTTP 404): Resource not found.');
            }
            if ($method === 'GET' && ($path === '/applications' || $path === '/applications?tag='.\rawurlencode($previewTag))) {
                return [['uuid' => 'worker-43', 'name' => 'storefront-preview-pr-43-queue-worker-1', 'tags' => [$previewTag, 'shippercli-managed']]];
            }
            if ($method === 'GET' && $path === '/databases') {
                return [['uuid' => 'database-43', 'name' => 'storefront-preview-pr-43', 'tags' => [$previewTag.'-db-0123456789', 'shippercli-managed']]];
            }

            return [];
        });
        $provider = new CoolifyProvider(['mcp_enabled' => true], $api);

        $sites = $provider->listSites($project, $profile);

        self::assertSame([[
            'site_id' => -43,
            'domain' => 'https://pr-43.preview.example.test',
        ]], $sites);
        self::assertTrue($provider->deleteSiteWithDatabases($project, $profile, $sites[0]['site_id']));
        self::assertNotContains(['DELETE', '/applications/app-uuid/previews/43'], $apiCalls);
        self::assertContains(['DELETE', '/applications/worker-43'], $apiCalls);
        self::assertContains(['DELETE', '/databases/database-43'], $apiCalls);
    }
}
