<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;

final class CoolifyApiClientTest extends TestCase
{
    public function testInjectedTransportReceivesMethodPathAndJsonBody(): void
    {
        $request = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'secret-token',
            transport: static function (string $method, string $path, ?array $body) use (&$request): array {
                $request = [$method, $path, $body];

                return ['uuid' => 'app-123'];
            },
        );

        self::assertSame(
            ['uuid' => 'app-123'],
            $client->request('patch', '/applications/app-123', ['name' => 'storefront']),
        );
        self::assertSame(['PATCH', '/applications/app-123', ['name' => 'storefront']], $request);
    }

    public function testApiPathsMustBeAbsoluteApiPaths(): void
    {
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'secret-token',
            transport: static fn (): array => [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $client->request('GET', 'applications');
    }
    public function testRawInventoryJsonPreservesObjectVersusListShape(): void
    {
        $client = new CoolifyApiClient('https://example.test', 'synthetic');
        $decode = new \ReflectionMethod($client, 'decodeInventory');
        foreach (['{}', '{"0":{"uuid":"one"}}', '{"data":{}}', '{"items":{}}', '', '{"data":[],"items":[]}'] as $body) {
            $failure = null;
            try { $decode->invoke($client, $body); } catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
        }
        foreach (['[]', '{"data":[]}', '{"items":[]}'] as $body) {
            self::assertSame(json_decode($body, true), $decode->invoke($client, $body));
        }
    }

    public function testInventoryTransportUsesGetAndRejectsObjectValuedRowsContainer(): void
    {
        $request = null;
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$request): array {
                $request = [$method, $path, $body]; return ['data' => new \stdClass];
            });
        $failure = null;
        try { $client->requestInventory('/databases'); } catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame(['GET', '/databases', null], $request);
    }

}
