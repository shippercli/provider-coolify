<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifyAdditionalResourceOperations
{
    /** @return array<int, array<string, mixed>> */
    public function coolifyCloudInitScripts(): array
    {
        return $this->list($this->getClient()->request('GET', '/cloud-init-scripts'));
    }

    /** @return array<string, mixed> */
    public function coolifyCloudInitScript(string $uuid): array
    {
        return $this->getClient()->request('GET', '/cloud-init-scripts/'.$this->coolifyResourceUuid($uuid));
    }

    /** @param array<string, mixed> $script @return array<string, mixed> */
    public function createCoolifyCloudInitScript(array $script): array
    {
        return $this->getClient()->request('POST', '/cloud-init-scripts', $this->coolifyCloudInitPayload($script, true));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateCoolifyCloudInitScript(string $uuid, array $changes): array
    {
        return $this->getClient()->request(
            'PATCH',
            '/cloud-init-scripts/'.$this->coolifyResourceUuid($uuid),
            $this->coolifyCloudInitPayload($changes, false),
        );
    }

    /** @return array<string, mixed> */
    public function deleteCoolifyCloudInitScript(string $uuid): array
    {
        return $this->getClient()->request('DELETE', '/cloud-init-scripts/'.$this->coolifyResourceUuid($uuid));
    }

    /** @return array<int, array<string, mixed>> */
    public function coolifyS3Storages(): array
    {
        return $this->list($this->getClient()->request('GET', '/s3-storages'));
    }

    /** @return array<string, mixed> */
    public function coolifyS3Storage(string $uuid): array
    {
        return $this->getClient()->request('GET', '/s3-storages/'.$this->coolifyResourceUuid($uuid));
    }

    /** @param array<string, mixed> $storage @return array<string, mixed> */
    public function createCoolifyS3Storage(array $storage): array
    {
        return $this->getClient()->request('POST', '/s3-storages', $this->coolifyS3StoragePayload($storage, true));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateCoolifyS3Storage(string $uuid, array $changes): array
    {
        return $this->getClient()->request(
            'PATCH',
            '/s3-storages/'.$this->coolifyResourceUuid($uuid),
            $this->coolifyS3StoragePayload($changes, false),
        );
    }

    /** @return array<string, mixed> */
    public function validateCoolifyS3Storage(string $uuid): array
    {
        return $this->getClient()->request('POST', '/s3-storages/'.$this->coolifyResourceUuid($uuid).'/validate');
    }

    /** @return array<string, mixed> */
    public function deleteCoolifyS3Storage(string $uuid): array
    {
        return $this->getClient()->request('DELETE', '/s3-storages/'.$this->coolifyResourceUuid($uuid));
    }

    private function coolifyResourceUuid(string $uuid): string
    {
        if (\trim($uuid) === '') {
            throw new \InvalidArgumentException('Coolify resource UUID is required.');
        }

        return \rawurlencode($uuid);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function coolifyCloudInitPayload(array $payload, bool $creating): array
    {
        $unsupported = \array_diff(\array_keys($payload), ['name', 'script']);
        if ($unsupported !== []) {
            throw new \InvalidArgumentException('Coolify cloud-init payload contains unsupported fields.');
        }
        foreach (['name', 'script'] as $field) {
            if (($creating || \array_key_exists($field, $payload))
                && (! \is_string($payload[$field] ?? null) || \trim($payload[$field]) === '')) {
                throw new \InvalidArgumentException("Coolify cloud-init {$field} must be a non-empty string.");
            }
        }
        if (! $creating && $payload === []) {
            throw new \InvalidArgumentException('Coolify cloud-init update must change the name or script.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function coolifyS3StoragePayload(array $payload, bool $creating): array
    {
        $fields = ['name', 'description', 'endpoint', 'bucket', 'region', 'key', 'secret', 'is_usable'];
        if (\array_diff(\array_keys($payload), $fields) !== []) {
            throw new \InvalidArgumentException('Coolify S3 storage payload contains unsupported fields.');
        }
        $required = $creating ? ['name', 'endpoint', 'bucket', 'region', 'key', 'secret'] : [];
        foreach ($required as $field) {
            if (! \is_string($payload[$field] ?? null) || \trim($payload[$field]) === '') {
                throw new \InvalidArgumentException("Coolify S3 storage {$field} is required.");
            }
        }
        foreach (['name', 'description', 'endpoint', 'bucket', 'region', 'key', 'secret'] as $field) {
            if (\array_key_exists($field, $payload) && (! \is_string($payload[$field]) || \trim($payload[$field]) === '')) {
                throw new \InvalidArgumentException("Coolify S3 storage {$field} must be a non-empty string.");
            }
        }
        if (\array_key_exists('is_usable', $payload) && ! \is_bool($payload['is_usable'])) {
            throw new \InvalidArgumentException('Coolify S3 storage is_usable must be a boolean.');
        }
        if (! $creating && $payload === []) {
            throw new \InvalidArgumentException('Coolify S3 storage update must contain at least one field.');
        }

        return $payload;
    }
}
