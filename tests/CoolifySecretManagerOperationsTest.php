<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class CoolifySecretManagerOperationsTest extends TestCase
{
    public function testItCreatesValidatedSecretManagerTokens(): void
    {
        $requests = [];
        $provider = new CoolifyProvider(
            ['url' => 'https://coolify.example.test', 'api_token' => 'api-token'],
            new CoolifyApiClient(
                'https://coolify.example.test',
                'api-token',
                transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                    $requests[] = [$method, $path, $body];
                    return ['uuid' => 'integration-uuid'];
                },
            ),
        );

        self::assertSame(
            ['uuid' => 'integration-uuid'],
            $provider->createSecretManagerIntegrationToken('infisical', 'production', 'secret-token', ['site_url' => 'https://secrets.example.test']),
        );
        self::assertSame('POST', $requests[0][0]);
        self::assertSame('/security/integration-tokens', $requests[0][1]);
        self::assertSame([
            'provider' => 'infisical',
            'name' => 'production',
            'token' => 'secret-token',
            'metadata' => ['site_url' => 'https://secrets.example.test'],
        ], $requests[0][2]);
    }

    public function testItAttachesAnIntegrationToAnApplication(): void
    {
        $requests = [];
        $provider = new CoolifyProvider(
            ['url' => 'https://coolify.example.test', 'api_token' => 'api-token'],
            new CoolifyApiClient(
                'https://coolify.example.test',
                'api-token',
                transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                    $requests[] = [$method, $path, $body];
                    return ['configured' => true];
                },
            ),
        );

        $provider->configureApplicationSecretManager('app-uuid', 'integration-uuid', ['project' => 'storefront']);

        self::assertSame('PATCH', $requests[0][0]);
        self::assertSame('/applications/app-uuid/secret-manager', $requests[0][1]);
        self::assertSame([
            'integration_token_uuid' => 'integration-uuid',
            'settings' => ['project' => 'storefront'],
        ], $requests[0][2]);
    }

    public function testItRejectsInvalidSecretManagerRequestsBeforeCallingCoolify(): void
    {
        $provider = new CoolifyProvider;

        try {
            $provider->createSecretManagerIntegrationToken('aws', 'production', 'secret-token');
            self::fail('Expected unsupported secret manager provider to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Coolify secret manager provider must be doppler, infisical, or vault.', $exception->getMessage());
        }

        try {
            $provider->configureApplicationSecretManager('app-uuid', 'integration-uuid', ['not-an-object-list']);
            self::fail('Expected list-shaped secret manager settings to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Coolify secret manager settings must be an object-like map.', $exception->getMessage());
        }
    }
}
