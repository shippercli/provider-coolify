<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifyServerLifecycleMetadata
{
    /** @param array<string, mixed> $server @return array<string, mixed> */
    private function serverWithLifecycleCreationTime(array $server): array
    {
        $description = $server['description'] ?? null;
        $uuid = $server['uuid'] ?? $server['server_uuid'] ?? null;
        $ownershipToken = $server['ownership_token'] ?? null;
        if (! \is_string($description) || ! \is_string($uuid) || ! \is_string($ownershipToken) || $ownershipToken === '') {
            return $server;
        }
        if (! \preg_match('/\[shippercli-created-at=([^;\]]+);signature=([a-f0-9]{64})\]/', $description, $matches)) {
            return $server;
        }
        $createdAt = $matches[1];
        $signature = \hash_hmac('sha256', $uuid.'|'.$createdAt, $ownershipToken);
        if (! \hash_equals($signature, $matches[2])) {
            return $server;
        }
        try {
            new \DateTimeImmutable($createdAt);
        } catch (\Throwable) {
            return $server;
        }

        $server['created_at'] = $createdAt;

        return $server;
    }
}
