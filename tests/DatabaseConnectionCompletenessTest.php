<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseConnectionCompletenessTest extends TestCase
{
    private function profile(array $explicit = [], array $databases = [['name' => 'main', 'type' => 'mysql']], bool $preview = false): object
    {
        return new class($explicit, $databases, $preview) {
            public function __construct(private array $explicit, private array $databases, private bool $preview) {}
            public function name(): string { return 'production'; }
            public function config(): array {
                return ['coolify' => ['databases' => $this->databases,
                    ...($this->preview ? ['pull_request_id' => '42', 'preview_environment_variables' => $this->explicit] : [])]];
            }
            public function environment(): object {
                return new class($this->preview ? [] : $this->explicit) {
                    public function __construct(private array $values) {}
                    public function variables(): array { return $this->values; }
                };
            }
        };
    }

    public function testMissingPrimarySqlFieldsStopBeforeApplicationApiCalls(): void
    {
        $complete = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.internal', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'user', 'DB_PASSWORD' => 'synthetic'];
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $missing) {
            $calls = [];
            $client = new CoolifyApiClient('https://example.test', 'synthetic', transport: static function ($method, $path, $body) use (&$calls): array {
                $calls[] = [$method, $path, $body]; return [];
            });
            $partial = $complete;
            unset($partial[$missing]);
            $provider = new CoolifyProvider;
            try {
                (new \ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $client, 'app', new \stdClass, $this->profile(), $partial);
                self::fail('Incomplete SQL connection was accepted: '.$missing);
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($missing, $error->getMessage());
                self::assertStringNotContainsString('synthetic', $error->getMessage());
            }
            self::assertSame([], $calls);
        }
    }

    public function testExplicitValuesCompletePrimaryConnectionAndNamedVariables(): void
    {
        $provider = new CoolifyProvider;
        $result = (new \ReflectionMethod($provider, 'completeDatabaseEnvironment'))->invoke($provider, new \stdClass,
            $this->profile(['DB_HOST' => 'explicit.internal', 'DB_PASSWORD' => 'explicit-secret']),
            ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'user']);
        self::assertSame('explicit.internal', $result['SHIPPER_DB_MAIN_HOST']);
        self::assertSame('explicit-secret', $result['SHIPPER_DB_MAIN_PASSWORD']);
    }

    public function testAdditionalSqlConnectionCannotBorrowDefaultPassword(): void
    {
        $provider = new CoolifyProvider;
        $profile = $this->profile([], [['name' => 'main', 'type' => 'mysql'], ['name' => 'reports', 'type' => 'postgres']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHIPPER_DB_REPORTS_PASSWORD');
        (new \ReflectionMethod($provider, 'completeDatabaseEnvironment'))->invoke($provider, new \stdClass, $profile,
            ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'main.internal', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'main', 'DB_PASSWORD' => 'main-secret',
                'SHIPPER_DB_REPORTS_HOST' => 'reports.internal', 'SHIPPER_DB_REPORTS_DATABASE' => 'reports', 'SHIPPER_DB_REPORTS_USERNAME' => 'reporter']);
    }

    public function testCompleteAdditionalSqlConnectionKeepsItsOwnPassword(): void
    {
        $provider = new CoolifyProvider;
        $generated = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'main.internal', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'main', 'DB_PASSWORD' => 'main-secret',
            'SHIPPER_DB_REPORTS_HOST' => 'reports.internal', 'SHIPPER_DB_REPORTS_DATABASE' => 'reports',
            'SHIPPER_DB_REPORTS_USERNAME' => 'reporter', 'SHIPPER_DB_REPORTS_PASSWORD' => 'reports-secret'];
        $result = (new \ReflectionMethod($provider, 'completeDatabaseEnvironment'))->invoke($provider, new \stdClass,
            $this->profile([], [['name' => 'main', 'type' => 'mysql'], ['name' => 'reports', 'type' => 'postgres']]), $generated);
        self::assertSame('main-secret', $result['DB_PASSWORD']);
        self::assertSame('reports-secret', $result['SHIPPER_DB_REPORTS_PASSWORD']);
    }

    public function testProductionEnvironmentCannotCompleteMissingPreviewSecrets(): void
    {
        $provider = new CoolifyProvider;
        $project = new class {
            public function environment(): object {
                return new class {
                    public function variables(): array { return ['DB_PASSWORD' => 'production-secret']; }
                };
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_PASSWORD');
        (new \ReflectionMethod($provider, 'completeDatabaseEnvironment'))->invoke($provider, $project,
            $this->profile(['DB_HOST' => 'preview.internal'], preview: true),
            ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'preview']);
    }

    public function testPreviewConnectionUsesOnlyPreviewExplicitValues(): void
    {
        $provider = new CoolifyProvider;
        $result = (new \ReflectionMethod($provider, 'completeDatabaseEnvironment'))->invoke($provider, new \stdClass,
            $this->profile(['DB_HOST' => 'preview.internal', 'DB_PASSWORD' => 'preview-secret'], preview: true),
            ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'preview']);
        self::assertSame('preview-secret', $result['SHIPPER_DB_MAIN_PR_42_PASSWORD']);
    }
}
