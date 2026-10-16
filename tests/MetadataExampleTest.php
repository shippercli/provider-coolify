<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyProvider;
use ShipperCli\ProviderCoolify\CoolifyApiClient;

final class MetadataExampleTest extends TestCase
{
    public function testMetadataContainsValidJsonAndAnUsableExistingServerExample(): void
    {
        $metadata = json_decode(file_get_contents(__DIR__.'/../meta.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('coolify', $metadata['slug']);
        $values = ['${COOLIFY_API_TOKEN}' => 'synthetic-token', '${COOLIFY_SERVER_UUID}' => 'server-1', '${COOLIFY_PROJECT_UUID}' => 'project-1'];
        $options = array_map(static fn ($value) => is_string($value) ? strtr($value, $values) : $value, $metadata['config']);
        $project = new class {
            public function name(): string { return 'fixture'; }
            public function repository(): array { return ['url' => 'https://github.com/example/app']; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array { return []; }
        };
        $calls = 0;
        $client = new CoolifyApiClient('https://coolify.example.test', 'synthetic-token', transport: static function () use (&$calls): never {
            $calls++;
            throw new \RuntimeException('Metadata validation must not access the API');
        });
        self::assertSame([], (new CoolifyProvider($options, $client))->validate($project, $profile));
        self::assertSame(0, $calls);
        foreach ($options as $value) {
            if (is_string($value)) self::assertStringNotContainsString('\\$', $value);
        }
    }
}
