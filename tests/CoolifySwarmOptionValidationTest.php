<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class CoolifySwarmOptionValidationTest extends TestCase
{
    public function testApplicationSwarmSettingsWithoutCoolifyApiWriteSupportAreRejected(): void
    {
        $provider = new CoolifyProvider();

        foreach (['swarm_replicas', 'swarm_placement_constraints'] as $option) {
            $value = $option === 'swarm_replicas' ? 2 : 'node.role == worker';
            $profile = new class($option, $value) {
                public function __construct(private string $option, private mixed $value)
                {
                }

                public function config(): array
                {
                    return ['coolify' => ['application' => [$this->option => $this->value]]];
                }

                public function get(string $key, mixed $default = null): mixed
                {
                    return $default;
                }
            };
            $errors = $provider->validate((object) [], $profile);

            self::assertContains(
                "Coolify's public API does not support writing application setting '{$option}'.",
                $errors,
            );
        }
    }
}
