<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifyPrivateKeyOperations
{
    /** @return array<int, array<string, mixed>> */
    public function privateKeys(): array
    {
        return $this->list($this->getClient()->request('GET', '/security/keys'));
    }

    /** @return array<string, mixed> */
    public function privateKey(string $uuid): array
    {
        if (\trim($uuid) === '') {
            throw new \InvalidArgumentException('Coolify private key UUID is required.');
        }

        return $this->getClient()->request('GET', '/security/keys/'.\rawurlencode($uuid));
    }

    /** @param array<string, mixed> $key @return array<string, mixed> */
    public function createPrivateKey(array $key): array
    {
        return $this->getClient()->request('POST', '/security/keys', $this->privateKeyPayload($key));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updatePrivateKey(array $changes): array
    {
        return $this->getClient()->request('PATCH', '/security/keys', $this->privateKeyPayload($changes));
    }

    /** @return array<string, mixed> */
    public function deletePrivateKey(string $uuid): array
    {
        if (\trim($uuid) === '') {
            throw new \InvalidArgumentException('Coolify private key UUID is required.');
        }

        return $this->getClient()->request('DELETE', '/security/keys/'.\rawurlencode($uuid));
    }

    /** @param array<string, mixed> $key @return array<string, mixed> */
    private function privateKeyPayload(array $key): array
    {
        $unsupported = \array_diff(\array_keys($key), ['name', 'description', 'private_key']);
        if ($unsupported !== []) {
            throw new \InvalidArgumentException('Coolify private key payload contains unsupported fields.');
        }
        if (! \is_string($key['private_key'] ?? null) || \trim($key['private_key']) === '') {
            throw new \InvalidArgumentException('Coolify private key content is required.');
        }
        foreach (['name', 'description'] as $field) {
            if (\array_key_exists($field, $key) && ! \is_string($key[$field])) {
                throw new \InvalidArgumentException("Coolify private key {$field} must be a string.");
            }
        }

        return $key;
    }
}
