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

    public function testTransportDiagnosticDoesNotExposeUrlCredentials(): void
    {
        $client = new CoolifyApiClient('http://synthetic-user:synthetic-url-password@127.0.0.1:bad-port', 'synthetic-token');
        $failure = null;
        try { $client->request('GET', '/test'); } catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('Coolify API transport error:', $failure->getMessage());
        self::assertStringNotContainsString('synthetic-url-password', $failure->getMessage());
        self::assertStringNotContainsString('synthetic-token', $failure->getMessage());
    }

    public function testAutomaticRetryPolicyHasDelayMethodAndAttemptBounds(): void
    {
        $client = new CoolifyApiClient('https://example.test', 'synthetic');
        $retry = new \ReflectionMethod($client, 'shouldRetry');
        foreach ([['GET', 429, 0, 0], ['HEAD', 429, 5, 1], ['OPTIONS', 429, 1, 0]] as $arguments) {
            self::assertTrue($retry->invoke($client, ...$arguments));
        }
        foreach ([['GET', 429, 6, 0], ['GET', 429, 3600, 0], ['GET', 429, null, 0],
                  ['GET', 429, -1, 0], ['GET', 429, 0, 2], ['POST', 429, 0, 0],
                  ['PATCH', 429, 0, 0], ['DELETE', 429, 0, 0], ['GET', 503, 0, 0]] as $arguments) {
            self::assertFalse($retry->invoke($client, ...$arguments));
        }
    }

}
