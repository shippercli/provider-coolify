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
            return array_map(static fn (string $suffix): array => ['uuid' => $suffix, 'tags' => ['shippercli-managed', 'scope'.$suffix]], ['', '-pr-42', '-db-0123456789', '-pr-42-db-0123456789', '-pr-', '-pr-0', '-pr-no', '-pr-42-other', '-db-invalid']);
        });
        $provider = new CoolifyProvider([], $client);
        $databases = (new \ReflectionMethod($provider, 'ownedDatabasesForProfile'))->invoke($provider, $client, 'scope');
        self::assertSame(['', '-pr-42', '-db-0123456789', '-pr-42-db-0123456789'], array_column($databases, 'uuid'));
        $exact = (new \ReflectionMethod($provider, 'ownedDatabases'))->invoke($provider, $client, 'scope');
        self::assertSame(['', '-db-0123456789'], array_column($exact, 'uuid'));
    }

    public function testSameNameServicesAndDatabasesWithOtherOwnershipBlockCreation(): void
    {
        foreach (['applyCustomService', 'applyDatabases'] as $method) {
            $calls = [];
            $client = new CoolifyApiClient('https://coolify.example.com', 'token', transport: static function (string $verb, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$verb, $path];
                return in_array($path, ['/services', '/databases'], true)
                    ? [['uuid' => 'other', 'name' => 'primary', 'tags' => ['shippercli-managed', 'other-scope']]] : [];
            });
            $provider = new CoolifyProvider([
                'docker_compose' => "services:\n  web:\n    image: nginx",
                'service' => ['name' => 'primary'],
                'databases' => [['name' => 'primary', 'type' => 'mysql']],
            ], $client);
            try {
                $profile = new class {
                    public function name(): string { return 'preview'; }
                    public function config(): array { return ['coolify' => ['databases' => [['name' => 'primary', 'type' => 'mysql']]]]; }
                };
                (new \ReflectionMethod($provider, $method))->invoke($provider, $client, $this->named('api'), $profile, 'project', 'environment', 'production', 'server');
                self::fail('Other ownership must block duplicate creation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('ownership tags', $exception->getMessage());
            }
            self::assertNotEmpty($calls);
            foreach ($calls as [$verb]) self::assertSame('GET', $verb);
        }
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
