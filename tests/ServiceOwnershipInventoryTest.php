<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class ServiceOwnershipInventoryTest extends TestCase
{
    public function testAmbiguousOrMalformedServicesStopApplyAndRemovalBeforeMutation(): void
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $owned = ['uuid' => 'one', 'name' => 'stack', 'service_type' => 'actualbudget', 'environment_uuid' => 'environment', 'server_uuid' => 'server', 'tags' => ['shippercli-managed', $scope]];
        foreach ([[$owned, [...$owned, 'uuid' => 'two', 'name' => 'other-stack']],
                  [[...$owned, 'uuid' => '']], [[...$owned, 'name' => null]],
                  [[...$owned, 'tags' => 'unreadable']], [[...$owned, 'tags' => ['shippercli-managed', $scope, 'shippercli-0123456789abcdef']]]] as $rows) {
            foreach ([true, false] as $configured) {
                $profile = new class($configured) {
                    public function __construct(private bool $configured) {}
                    public function name(): string { return 'production'; }
                    public function config(): array { return $this->configured ? ['coolify' => ['service' => ['type' => 'actualbudget']]] : []; }
                };
                $calls = [];
                $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
                    static function ($method, $path, $body) use (&$calls, $rows): array {
                        $calls[] = [$method, $path];
                        return $method === 'GET' ? $rows : ['uuid' => 'one'];
                    });
                $provider = new CoolifyProvider;
                try {
                    (new \ReflectionMethod($provider, 'applyCustomService'))->invoke($provider, $client, $project, $profile,
                        'project', 'environment', 'production', 'server');
                    self::fail('Unsafe service inventory must not authorize mutation');
                } catch (\RuntimeException $error) {
                    self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
                    self::assertStringContainsString('inventory', $error->getMessage());
                }
                foreach ($calls as $call) self::assertSame('GET', $call[0]);
            }
        }
    }
    public function testOneOwnedServiceRemainsUpdatable(): void
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['service' => ['type' => 'actualbudget']]]; }
        };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $scope): array {
                $calls[] = [$method, $path];
                if ($path === '/services?tag='.rawurlencode($scope)) return [['uuid' => 'one', 'name' => 'stack',
                    'service_type' => 'actualbudget', 'server_uuid' => 'server', 'environment_uuid' => 'environment',
                    'tags' => ['shippercli-managed', $scope]]];
                return $method === 'PATCH' ? ['uuid' => 'one'] : [];
            });
        $result = (new \ReflectionMethod(new CoolifyProvider, 'applyCustomService'))->invoke(new CoolifyProvider,
            $client, $project, $profile, 'project', 'environment', 'production', 'server');
        self::assertSame('one', $result);
        self::assertContains(['PATCH', '/services/one'], $calls);
    }

    public function testAmbiguousServicePlanIsReadOnlyAndBlocked(): void
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $scope): array {
                $calls[] = [$method, $path];
                if (str_starts_with($path, '/services')) return [
                    ['uuid' => 'one', 'name' => 'one', 'tags' => ['shippercli-managed', $scope]],
                    ['uuid' => 'two', 'name' => 'two', 'tags' => ['shippercli-managed', $scope]],
                ];
                return [];
            });
        $plan = (new CoolifyProvider([], $client))->plan($project, $profile);
        self::assertTrue($plan['blocked']);
        self::assertCount(1, $plan['actions']);
        self::assertStringContainsString('service inventory', $plan['actions'][0]);
        foreach ($calls as $call) self::assertSame('GET', $call[0]);
    }

}
