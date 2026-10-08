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
}
