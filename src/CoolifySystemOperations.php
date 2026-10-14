<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifySystemOperations
{
    /** Enable the instance API. Requires a root-permission token. */
    public function enableCoolifyApiAccess(): array
    {
        return $this->getClient()->request('POST', '/enable');
    }

    /**
     * Disable the instance API. Requires a root-permission token and can make
     * subsequent API calls unavailable until access is re-enabled in Coolify.
     */
    public function disableCoolifyApiAccess(): array
    {
        return $this->getClient()->request('POST', '/disable');
    }
}
