<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifyServiceComponentOperations
{
    /** Queue an immediate run of an existing service-storage backup schedule.
     *
     * @return array<string, mixed>
     */
    public function runServiceStorageBackup(string $serviceUuid, string $storageUuid): array
    {
        if (\trim($serviceUuid) === '' || \trim($storageUuid) === '') {
            throw new \InvalidArgumentException('Coolify service and storage UUIDs are required to run a service storage backup.');
        }

        return $this->getClient()->request(
            'POST',
            '/services/'.\rawurlencode($serviceUuid).'/storages/'.\rawurlencode($storageUuid).'/backups/run',
        );
    }

    /** @return array<string, mixed> */
    public function startServiceComponent(
        string $serviceUuid,
        string $componentType,
        string $componentUuid,
        bool $force = false,
        bool $latest = false,
    ): array {
        return $this->serviceComponentLifecycleRequest($serviceUuid, $componentType, $componentUuid, 'start', $force, $latest);
    }

    /** @return array<string, mixed> */
    public function stopServiceComponent(string $serviceUuid, string $componentType, string $componentUuid): array
    {
        return $this->serviceComponentLifecycleRequest($serviceUuid, $componentType, $componentUuid, 'stop');
    }

    /** @return array<string, mixed> */
    public function restartServiceComponent(string $serviceUuid, string $componentType, string $componentUuid): array
    {
        return $this->serviceComponentLifecycleRequest($serviceUuid, $componentType, $componentUuid, 'restart');
    }

    /** @return array<string, mixed> */
    private function serviceComponentLifecycleRequest(
        string $serviceUuid,
        string $componentType,
        string $componentUuid,
        string $operation,
        bool $force = false,
        bool $latest = false,
    ): array {
        if (\trim($serviceUuid) === '' || \trim($componentUuid) === '') {
            throw new \InvalidArgumentException('Coolify service and component UUIDs are required for component lifecycle operations.');
        }
        if (! \in_array($componentType, ['application', 'database'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify service component type '{$componentType}'.");
        }
        if (! \in_array($operation, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify service component operation '{$operation}'.");
        }
        if ($operation !== 'start' && ($force || $latest)) {
            throw new \InvalidArgumentException('Coolify force and latest options are only supported when starting a service component.');
        }

        $path = '/services/'.\rawurlencode($serviceUuid)
            .'/'.($componentType === 'application' ? 'applications' : 'databases')
            .'/'.\rawurlencode($componentUuid).'/'.$operation;
        $query = [];
        if ($force) {
            $query['force'] = true;
        }
        if ($latest) {
            $query['latest'] = true;
        }
        if ($query !== []) {
            $path .= '?'.\http_build_query($query);
        }

        return $this->getClient()->request('POST', $path);
    }
}
