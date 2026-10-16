<?php

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class ApplicationOwnershipTest extends TestCase
{
    private function named(string $name): object
    {
        return new class($name) {
            public function __construct(private string $value) {}
            public function name(): string { return $this->value; }
        };
    }

    public function testScopeEncodingPreservesBoundariesAndNullProfiles(): void
    {
        $provider = new CoolifyProvider();
        foreach (['resourceTag', 'projectProfileHash'] as $method) {
            $hash = new \ReflectionMethod($provider, $method);
            self::assertNotSame(
                $hash->invoke($provider, $this->named('api|preview'), $this->named('test')),
                $hash->invoke($provider, $this->named('api'), $this->named('preview|test')),
            );
            self::assertNotSame(
                $hash->invoke($provider, $this->named('api'), null),
                $hash->invoke($provider, $this->named('api'), $this->named('*')),
            );
        }
    }

    public function testSameNameUnownedApplicationBlocksCreationBeforeWrites(): void
    {
        $calls = [];
        $client = new CoolifyApiClient('https://coolify.example.com', 'token', transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
            $calls[] = [$method, $path];
            return $path === '/applications' ? [['uuid' => 'legacy', 'name' => 'api-preview', 'tags' => ['shippercli-managed', 'legacy-tag']]] : [];
        });
        $provider = new CoolifyProvider([], $client);
        try {
            (new \ReflectionMethod($provider, 'ensureApplication'))->invoke($provider, $client, $this->named('api'), $this->named('preview'), 'project', 'environment', 'production', 'server', 'api-preview', null);
            self::fail('A same-name application must block creation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('duplicate', $exception->getMessage());
        }
        self::assertCount(2, $calls);
        foreach ($calls as [$method]) self::assertSame('GET', $method);
    }

    public function testDatabaseCleanupRejectsMalformedPreviewTagSuffixes(): void
    {
        $client = new CoolifyApiClient('https://coolify.example.com', 'token', transport: static function (): array {
            return array_map(static fn (string $suffix): array => ['uuid' => $suffix, 'tags' => ['shippercli-managed', 'scope'.$suffix]], ['', '-pr-42', '-pr-', '-pr-0', '-pr-no', '-pr-42-other']);
        });
        $provider = new CoolifyProvider([], $client);
        $databases = (new \ReflectionMethod($provider, 'ownedDatabasesForProfile'))->invoke($provider, $client, 'scope');
        self::assertSame(['', '-pr-42'], array_column($databases, 'uuid'));
    }

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
