<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

use ShipperCli\Contracts\ShipperPluginInterface;

final class CoolifyPlugin implements ShipperPluginInterface
{
    public function providers(): array
    {
        return ['coolify' => CoolifyProvider::class];
    }
}
