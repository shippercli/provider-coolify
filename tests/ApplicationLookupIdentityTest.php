<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class ApplicationLookupIdentityTest extends TestCase
{
    public function testUnsafeUpdateLookupsStopBeforeMutation(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repository(): array { return ['url' => 'https://github.com/example/storefront']; }
        };
        $profile = new class { public function name(): string { return 'production'; } };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        foreach (['wrong-explicit-id', 'missing-name', 'duplicate-match', 'malformed-fallback'] as $mode) {
            $calls = [];
            $app = ['uuid' => 'app', 'name' => 'storefront-production', 'tags' => ['shippercli-managed', $scope]];
            $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
                static function ($method, $path, $body) use (&$calls, $app, $mode): array {
                    $calls[] = [$method, $path];
                    if ($path === '/applications/requested') return $mode === 'missing-name' ? array_diff_key($app, ['name' => true]) : $app;
                    if (str_starts_with($path, '/applications?tag=')) return $mode === 'duplicate-match' ? [$app, [...$app, 'uuid' => 'copy']] : [];
                    if ($path === '/applications' && $method === 'GET') return $mode === 'malformed-fallback' ? [['metadata' => 'unknown']] : [];
                    return ['uuid' => 'app'];
                });
            $provider = new CoolifyProvider(in_array($mode, ['wrong-explicit-id', 'missing-name'], true) ? ['application_uuid' => 'requested'] : [], $client);
            try {
                (new \ReflectionMethod($provider, 'ensureApplication'))->invoke($provider, $client, $project, $profile,
                    'project', 'environment', 'production', 'server', 'storefront-production', null);
                self::fail('Unsafe application lookup was accepted');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('application', $error->getMessage());
            }
            foreach ($calls as $call) self::assertSame('GET', $call[0], $mode);
        }
    }
    public function testMatchingExplicitIdentityRemainsReadable(): void
    {
        $scope = 'shippercli-0123456789abcdef';
        $app = ['uuid' => 'requested', 'name' => 'custom-owned-name', 'tags' => ['shippercli-managed', $scope]];
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $app): array {
                $calls[] = [$method, $path]; return $app;
            });
        $result = (new \ReflectionMethod(new CoolifyProvider, 'readOwnedApplication'))->invoke(new CoolifyProvider,
            $client, 'requested', $scope);
        self::assertSame($app, $result);
        self::assertSame([['GET', '/applications/requested']], $calls);
    }

}
