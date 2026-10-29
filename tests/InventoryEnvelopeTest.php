<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyProvider;
use ShipperCli\ProviderCoolify\CoolifyApiClient;

final class InventoryEnvelopeTest extends TestCase
{
    public function testAmbiguousFailedAndPartialEnvelopesAreRefused(): void
    {
        $provider = new CoolifyProvider;
        $parse = new \ReflectionMethod($provider, 'list');
        foreach ([
            ['data' => [], 'items' => [['uuid' => 'hidden']]],
            ['data' => null, 'items' => []],
            ['data' => [], 'success' => false],
            ['data' => [], 'errors' => ['Denied']],
            ['items' => [], 'error' => 'Unavailable'],
            ['data' => [], 'next_page_url' => 'https://example.test/page/2'],
            ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 2]],
            ['data' => [], 'meta' => ['current_page' => 2, 'last_page' => 2]],
            ['items' => [], 'links' => ['next' => 'page/2']],
            ['data' => [], '_pagination' => ['next' => ['page' => 2]]],
            ['data' => [], 'total' => 1], ['items' => [], 'has_more' => true],
        ] as $payload) {
            $failure = null;
            try { $parse->invoke($provider, $payload); } catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
        }
    }

    public function testCompleteFlatAndWrappedListsRemainSupported(): void
    {
        $provider = new CoolifyProvider;
        $parse = new \ReflectionMethod($provider, 'list');
        $rows = [['uuid' => 'one']];
        foreach ([$rows, ['data' => $rows], ['items' => $rows],
                  ['data' => $rows, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1], 'links' => ['next' => null]],
                  ['items' => $rows, 'success' => true, 'has_more' => false, 'total' => 1]] as $payload) {
            self::assertSame($rows, $parse->invoke($provider, $payload));
        }
    }
    public function testPartialDatabaseEnvelopeBlocksPlanAndDestroyWithoutWrites(): void
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        foreach (['plan', 'destroy'] as $operation) {
            $calls = [];
            $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
                static function ($method, $path, $body) use (&$calls): array {
                    $calls[] = [$method, $path];
                    return $path === '/databases' ? ['data' => [], 'next_page_url' => 'https://untrusted.test/?private=sentinel'] : [];
                });
            $provider = new CoolifyProvider([], $client);
            if ($operation === 'plan') {
                $result = $provider->plan($project, $profile);
                self::assertTrue($result['blocked']);
                self::assertCount(1, $result['actions']);
                self::assertStringNotContainsString('sentinel', $result['actions'][0]);
            } else {
                self::assertFalse($provider->destroy($project, $profile));
                self::assertStringNotContainsString('sentinel', $provider->getLastError());
                self::assertSame([['GET', '/databases']], $calls);
            }
            foreach ($calls as $call) self::assertSame('GET', $call[0]);
        }
    }

}
