<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class CreationIdentityTest extends TestCase
{
    private function create(string $kind, array $response, array &$calls): mixed
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repository(): array { return ['url' => 'https://github.com/example/storefront']; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['service' => ['type' => 'actualbudget']]]; }
        };
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $kind, $response): array {
                $calls[] = [$method, $path];
                if ($method === 'GET' && $path === '/'.$kind) return [['uuid' => 'existing', 'name' => 'unrelated', 'tags' => []]];
                if ($method === 'POST' && ($path === '/applications/public' || $path === '/services')) return $response;
                return [];
            });
        $provider = new CoolifyProvider;
        $method = new \ReflectionMethod($provider, $kind === 'applications' ? 'ensureApplication' : 'applyCustomService');
        $arguments = [$provider, $client, $project, $profile, 'project', 'environment', 'production', 'server'];
        if ($kind === 'applications') $arguments = [...$arguments, 'storefront-production', null];
        return $method->invoke(...$arguments);
    }

    public function testInvalidCreateIdentitiesStopBeforeFollowUpOperations(): void
    {
        foreach (['applications', 'services'] as $kind) {
            foreach ([['uuid' => 'existing'], [], ['uuid' => ''], ['uuid' => '  '], ['uuid' => 12],
                      ['uuid' => 'new', 'data' => ['uuid' => 'foreign']], ['uuid' => 'new', 'tags' => []],
                      ['uuid' => null, 'data' => ['uuid' => 'new']], ['uuid' => 'new', 'name' => null],
                      ['uuid' => 'new', 'tags' => ['shippercli-managed', 'shippercli-0123456789abcdef']]] as $response) {
                $calls = [];
                $failure = null;
                try { $this->create($kind, $response, $calls); } catch (\RuntimeException $error) { $failure = $error; }
                self::assertInstanceOf(\RuntimeException::class, $failure);
                self::assertSame(['POST', $kind === 'applications' ? '/applications/public' : '/services'], end($calls));
            }
        }
    }

    public function testThinValidCreationResponsesRemainSupported(): void
    {
        foreach (['applications', 'services'] as $kind) {
            foreach ([['uuid' => 'new'], ['data' => ['uuid' => 'new']]] as $response) {
                $calls = [];
                $result = $this->create($kind, $response, $calls);
                self::assertSame('new', is_array($result) ? $result['uuid'] : $result);
            }
        }
    }
}
