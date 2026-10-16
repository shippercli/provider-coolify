<?php

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class ApplicationOwnershipTest extends TestCase
{
    public function testExplicitUuidCannotAdoptAnUnownedApplication(): void
    {
        foreach ([[], ['shippercli-managed'], ['shippercli-managed', 'another-scope']] as $tags) {
            $calls = [];
            $client = new CoolifyApiClient('https://coolify.example.com', 'token', transport: static function (string $method, string $path, ?array $body) use (&$calls, $tags): array {
                $calls[] = [$method, $path];
                return ['uuid' => 'app-existing', 'tags' => $tags];
            });
            $provider = new CoolifyProvider(['application_uuid' => 'app-existing'], $client);
            foreach (['findApplication', 'ensureApplication'] as $method) {
                try {
                    $args = [$client, new \stdClass, new \stdClass];
                    if ($method === 'ensureApplication') $args = [...$args, 'project', 'environment', 'production', 'server', 'app', null];
                    (new \ReflectionMethod($provider, $method))->invokeArgs($provider, $args);
                    self::fail('An explicit UUID must not bypass ownership checks.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('ownership tags', $exception->getMessage());
                }
            }
            self::assertSame([['GET', '/applications/app-existing'], ['GET', '/applications/app-existing']], $calls);
        }
    }
}
