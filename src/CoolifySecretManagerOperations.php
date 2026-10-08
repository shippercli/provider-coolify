<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifySecretManagerOperations
{
    /**
     * Create and validate a Coolify Doppler, Infisical, or Vault integration token.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function createSecretManagerIntegrationToken(string $provider, string $name, string $token, array $metadata = []): array
    {
        if (!\in_array($provider, ['doppler', 'infisical', 'vault'], true)) {
            throw new \InvalidArgumentException('Coolify secret manager provider must be doppler, infisical, or vault.');
        }
        if (\trim($name) === '' || \trim($token) === '') {
            throw new \InvalidArgumentException('Coolify secret manager integration name and token are required.');
        }
        if ($metadata !== [] && (\array_is_list($metadata) || \array_filter(\array_keys($metadata), static fn (mixed $key): bool => !\is_string($key)) !== [])) {
            throw new \InvalidArgumentException('Coolify secret manager metadata must be an object-like map.');
        }

        $payload = ['provider' => $provider, 'name' => $name, 'token' => $token];
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $this->getClient()->request('POST', '/security/integration-tokens', $payload);
    }

    /**
     * Attach a previously-created Coolify secret manager integration to an application.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function configureApplicationSecretManager(string $applicationUuid, string $integrationTokenUuid, array $settings = []): array
    {
        if (\trim($applicationUuid) === '' || \trim($integrationTokenUuid) === '') {
            throw new \InvalidArgumentException('Coolify application UUID and secret manager integration token UUID are required.');
        }
        if ($settings !== [] && (\array_is_list($settings) || \array_filter(\array_keys($settings), static fn (mixed $key): bool => !\is_string($key)) !== [])) {
            throw new \InvalidArgumentException('Coolify secret manager settings must be an object-like map.');
        }

        $payload = ['integration_token_uuid' => $integrationTokenUuid];
        if ($settings !== []) {
            $payload['settings'] = $settings;
        }

        return $this->getClient()->request('PATCH', '/applications/'.\rawurlencode($applicationUuid).'/secret-manager', $payload);
    }
}
