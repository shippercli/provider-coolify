<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class ApplicationCleanupInventoryTest extends TestCase
{
    private function runDestroy(string $mode): array
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $calls = [];
        $deleted = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, &$deleted, $scope, $mode): array {
                $calls[] = [$method, $path];
                if ($method === 'DELETE') {
                    if (isset($deleted[$path])) throw new \RuntimeException('Already deleted (HTTP 404)');
                    $deleted[$path] = true;
                    return [];
                }
                if ($path === '/databases') return [];
                if (str_starts_with($path, '/services')) {
                    if ($mode === 'unreadable-services') throw new \RuntimeException('Unavailable inventory');
                    return [];
                }
                if (str_starts_with($path, '/applications')) {
                    $apps = [['uuid' => $mode === 'numeric-id' ? '123' : 'app', 'name' => 'storefront-production', 'tags' => ['shippercli-managed', $scope]]];
                    if ($mode === 'missing-identity') $apps[] = ['uuid' => '', 'name' => 'worker', 'tags' => ['shippercli-managed', $scope]];
                    if ($mode === 'duplicate-name') $apps[] = ['uuid' => 'other', 'name' => 'storefront-production', 'tags' => ['shippercli-managed', $scope]];
                    if ($mode === 'duplicate-uuid') $apps[] = ['uuid' => 'app', 'name' => 'copy', 'tags' => ['shippercli-managed', $scope]];
                    if ($mode === 'cross-owner') $apps[0]['tags'][] = 'shippercli-0123456789abcdef';
                    if ($mode === 'unreadable-tags') $apps[0]['tags'] = 'bad';
                    return $apps;
                }
                return [];
            });
        $provider = new CoolifyProvider([], $client);
        return [$provider->destroy($project, $profile), $calls, $provider->getLastError()];
    }

    public function testEachApplicationIsDeletedOnlyOnce(): void
    {
        [$result, $calls, $error] = $this->runDestroy('valid');
        self::assertTrue($result, $error);
        self::assertSame([['DELETE', '/applications/app']], array_values(array_filter($calls, static fn ($call): bool => $call[0] === 'DELETE')));
    }

    public function testNumericStringIdentityRemainsAStringInTheDeleteRequest(): void
    {
        [$result, $calls, $error] = $this->runDestroy('numeric-id');
        self::assertTrue($result, $error);
        self::assertContains(['DELETE', '/applications/123'], $calls);
    }

    public function testInvalidLaterInventoryStopsBeforeAnyDeletion(): void
    {
        foreach (['missing-identity', 'duplicate-name', 'duplicate-uuid', 'cross-owner', 'unreadable-tags', 'unreadable-services'] as $mode) {
            [$result, $calls] = $this->runDestroy($mode);
            self::assertFalse($result);
            foreach ($calls as $call) self::assertSame('GET', $call[0], $mode);
        }
    }
}
