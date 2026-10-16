<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseResponseIdentityTest extends TestCase
{
    private function apply(array $response, bool $create, array &$calls, bool $readFailure = false, string $readMessage = 'Coolify API request failed (HTTP 403): denied', bool $patch = false): array
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class($patch) {
            public function __construct(private bool $patch) {}
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['databases' => [['name' => 'main', 'type' => 'mysql']],
                ...($this->patch ? ['database_options' => ['main' => ['mysql_password' => 'explicit-secret']]] : [])]]; }
        };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $owned = ['uuid' => 'owned', 'name' => 'main', 'tags' => ['shippercli-managed', $scope.'-db-'.substr(hash('sha256', 'main'), 0, 10)],
            'environment_uuid' => 'environment', 'server_uuid' => 'server', 'destination_uuid' => 'destination', 'internal_db_host' => 'db.internal',
            'mysql_user' => 'known-user', 'mysql_database' => 'main', 'mysql_password' => 'known-secret'];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $response, $owned, $create, $readFailure, $readMessage): array {
                $calls[] = [$method, $path];
                if ($path === '/databases') return $create ? [['uuid' => 'other', 'name' => 'other', 'tags' => []]] : [$owned];
                if ($method === 'POST' && $path === '/databases/mysql') return $response;
                if ($method === 'PATCH' && $path === '/databases/owned') return $response;
                if ($method === 'GET' && preg_match('#^/databases/[^/]+$#', $path) === 1) {
                    if ($readFailure) throw new \RuntimeException($readMessage);
                    return $response;
                }
                return [];
            });
        $provider = new CoolifyProvider;
        return (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, $profile,
            'project', 'environment', 'production', 'server');
    }

    public function testWrongDatabaseDetailsCannotSupplyApplicationCredentials(): void
    {
        foreach ([['uuid' => 'foreign', 'mysql_password' => 'foreign-secret'], ['uuid' => ''], ['uuid' => null],
                  ['data' => ['uuid' => 'foreign']], ['tags' => []]] as $response) {
            $calls = [];
            $failure = null;
            try { $this->apply($response, false, $calls); } catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertStringContainsString('identity', $failure->getMessage());
            self::assertStringNotContainsString('foreign-secret', $failure->getMessage());
            self::assertSame(['GET', '/databases/owned'], end($calls));
        }
    }

    public function testMissingOrReusedCreateIdentityStopsAfterCreationRequest(): void
    {
        foreach ([[], ['uuid' => ''], ['uuid' => 'other'], ['uuid' => 'new', 'data' => ['uuid' => 'foreign']]] as $response) {
            $calls = [];
            $failure = null;
            try { $this->apply($response, true, $calls); } catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertStringContainsString('identity', $failure->getMessage());
            self::assertSame(['POST', '/databases/mysql'], end($calls));
        }
    }

    public function testDatabaseUpdateResponseIsCheckedBeforeDetailReads(): void
    {
        $calls = [];
        $failure = null;
        try { $this->apply(['uuid' => 'foreign'], false, $calls, patch: true); } catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('identity', $failure->getMessage());
        self::assertSame(['PATCH', '/databases/owned'], end($calls));
    }

    public function testMissingUnavailableAndTransportFailuresAreNotPermissionFallbacks(): void
    {
        foreach (['Coolify API request failed (HTTP 404): missing', 'Coolify API request failed (HTTP 503): unavailable',
                  'Transport failed', 'Coolify API request failed (HTTP 500): text mentions (HTTP 403): denied'] as $message) {
            $calls = [];
            $failure = null;
            try { $this->apply([], false, $calls, true, $message); } catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame($message, $failure->getMessage());
            self::assertSame(['GET', '/databases/owned'], end($calls));
        }
    }

    public function testSensitiveReadFailurePreservesKnownExistingCredentials(): void
    {
        $calls = [];
        $environment = $this->apply([], false, $calls, true);
        self::assertSame('known-secret', $environment['DB_PASSWORD']);
        self::assertSame('known-user', $environment['DB_USERNAME']);
    }
}
