<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseDeclarationMergeTest extends TestCase
{
    private function project(string $type): object
    {
        return new class($type) {
            public function __construct(private string $type) {}
            public function name(): string { return 'storefront'; }
            public function databases(): array {
                return [new class($this->type) {
                    public function __construct(private string $type) {}
                    public function name(): string { return 'main'; }
                    public function type(): string { return $this->type; }
                    public function user(): string { return 'reporter'; }
                }];
            }
        };
    }

    private function profile(array $fields): object
    {
        return new class($fields) {
            public function __construct(private array $fields) {}
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['databases' => ['main' => $this->fields]]]; }
        };
    }

    public function testPasswordOnlyProfileOverridePreservesProjectTypeAndUser(): void
    {
        $provider = new CoolifyProvider;
        foreach (['mysql', 'mariadb', 'postgresql'] as $type) {
            $result = (new \ReflectionMethod($provider, 'databaseDeclarations'))->invoke($provider,
                $this->project($type), $this->profile(['password' => 'creation-secret']));
            self::assertSame([['name' => 'main', 'type' => $type, 'user' => 'reporter', 'password' => 'creation-secret']], $result);
        }
    }

    public function testExplicitProfileFieldsStillOverrideProjectValues(): void
    {
        $provider = new CoolifyProvider;
        $result = (new \ReflectionMethod($provider, 'databaseDeclarations'))->invoke($provider,
            $this->project('postgresql'), $this->profile(['type' => 'mariadb', 'user' => 'explicit', 'password' => 'creation-secret']));
        self::assertSame([['name' => 'main', 'type' => 'mariadb', 'user' => 'explicit', 'password' => 'creation-secret']], $result);
    }

    public function testExplicitNullDeclarationFieldsAreValidationErrors(): void
    {
        $provider = new CoolifyProvider;
        foreach (['type', 'user', 'password'] as $field) {
            $errors = $provider->validate($this->project('postgresql'), $this->profile([$field => null]));
            self::assertContains("Coolify database declaration 'main' field '{$field}' must be a string.", $errors);
        }
    }
}
