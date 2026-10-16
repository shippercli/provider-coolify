<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseInventoryOwnershipTest extends TestCase
{
    public function testAmbiguousLaterDatabaseStopsBeforeEarlierDatabaseMutation(): void
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['databases' => [['name' => 'first'], ['name' => 'second']]]]; }
        };
        $provider = new CoolifyProvider;
        $scope = (new \ReflectionMethod($provider, 'resourceTag'))->invoke($provider, $project, $profile);
        $tag = $scope.'-db-'.substr(hash('sha256', 'second'), 0, 10);
        $cases = [
            [['uuid' => 'one', 'name' => 'second', 'tags' => ['shippercli-managed', $tag]],
             ['uuid' => 'two', 'name' => 'second-copy', 'tags' => ['shippercli-managed', $tag]]],
            [['uuid' => '', 'name' => 'second', 'tags' => ['shippercli-managed', $tag]]],
            [['uuid' => 'one', 'name' => 'second', 'tags' => ['shippercli-managed', $tag, $scope.'-db-deadbeef00']]],
            [['uuid' => 'one', 'name' => 'second', 'tags' => ['shippercli-managed', $scope]]],
        ];
        foreach ($cases as $inventory) {
            $calls = [];
            $client = new CoolifyApiClient('https://example.test', 'synthetic', transport: static function ($method, $path, $body) use (&$calls, $inventory): array {
                $calls[] = [$method, $path];
                return $method === 'GET' && $path === '/databases' ? $inventory : [];
            });
            try {
                (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, $profile,
                    'project', 'environment', 'production', 'server');
                self::fail('Ambiguous ownership must not authorize database changes');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('database inventory', $error->getMessage());
            }
            self::assertSame([['GET', '/databases']], $calls);
        }
    }
    public function testUnambiguousTagFormatsAndOtherScopesRemainSupported(): void
    {
        $provider = new CoolifyProvider;
        $scope = 'shippercli-0123456789abcdef';
        $tag = $scope.'-db-0123456789';
        $validate = new \ReflectionMethod($provider, 'validateDatabaseInventory');
        foreach ([['shippercli-managed', $tag], [['name' => 'shippercli-managed'], ['name' => $tag]],
                  [['tag' => 'shippercli-managed'], ['tag' => $tag]]] as $tags) {
            $rows = [['uuid' => 'owned', 'name' => 'main', 'tags' => $tags],
                ['uuid' => 'other', 'name' => 'other', 'tags' => ['shippercli-managed', 'shippercli-fedcba9876543210-db-0123456789']]];
            $result = $validate->invoke($provider, $rows, $scope);
            self::assertCount(2, $result);
            self::assertSame(['shippercli-managed', $tag], $result[0]['tags']);
        }
    }

    public function testDuplicateUuidAndUnreadableTagsAreRejected(): void
    {
        $provider = new CoolifyProvider;
        $scope = 'shippercli-0123456789abcdef';
        $validate = new \ReflectionMethod($provider, 'validateDatabaseInventory');
        foreach ([
            [['uuid' => 'one', 'name' => 'first'], ['uuid' => 'one', 'name' => 'second']],
            [['uuid' => 'one', 'name' => 'first', 'tags' => 'unreadable']],
            [['uuid' => 'one', 'name' => 'first', 'tags' => [['name' => 'one', 'tag' => 'two']]]],
        ] as $rows) {
            try {
                $validate->invoke($provider, $rows, $scope);
                self::fail('Invalid inventory must be refused');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('database inventory', $error->getMessage());
            }
        }
    }

    public function testPlanDestroyAndPreviewCleanupRefuseDuplicateOwnersWithoutWrites(): void
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        foreach (['plan', 'destroy', 'preview'] as $operation) {
            $tag = $scope.($operation === 'preview' ? '-pr-42' : '').'-db-0123456789';
            $calls = [];
            $app = ['uuid' => 'app', 'name' => 'storefront-production', 'tags' => ['shippercli-managed', $scope]];
            $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
                static function ($method, $path, $body) use (&$calls, $tag, $app): array {
                    $calls[] = [$method, $path];
                    if ($path === '/databases') return [
                        ['uuid' => 'one', 'name' => 'main', 'tags' => ['shippercli-managed', $tag]],
                        ['uuid' => 'two', 'name' => 'copy', 'tags' => ['shippercli-managed', $tag]],
                    ];
                    if ($path === '/applications/app') return $app;
                    if (str_starts_with($path, '/applications')) return [$app];
                    return [];
                });
            $provider = new CoolifyProvider([], $client);
            if ($operation === 'plan') {
                $plan = $provider->plan($project, $profile);
                self::assertTrue($plan['blocked']);
                self::assertCount(1, $plan['actions']);
                self::assertStringContainsString('database inventory', $plan['actions'][0]);
            } else {
                $result = $operation === 'destroy' ? $provider->destroy($project, $profile)
                    : $provider->deletePreviewByPullRequest($project, $profile, 42);
                self::assertFalse($result);
                self::assertStringContainsString('database inventory', $provider->getLastError());
                self::assertSame([['GET', '/databases']], $calls);
            }
            foreach ($calls as $call) self::assertSame('GET', $call[0]);
        }
    }

}
