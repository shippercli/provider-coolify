<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class MutationIdentityTest extends TestCase
{
    private function update(string $kind, array $response, array &$calls): mixed
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repository(): array { return ['url' => 'https://github.com/example/storefront']; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['service' => ['type' => 'actualbudget', 'tags' => ['extra']], 'application' => ['tags' => ['extra']]]]; }
        };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $resource = ['uuid' => 'owned', 'name' => 'storefront-production', 'tags' => ['shippercli-managed', $scope],
            'service_type' => 'actualbudget', 'server_uuid' => 'server', 'environment_uuid' => 'environment'];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $response, $resource, $kind): array {
                $calls[] = [$method, $path];
                if ($method === 'GET' && str_starts_with($path, '/'.$kind.'?tag=')) return [$resource];
                if ($method === 'PATCH' && $path === '/'.$kind.'/owned') return $response;
                return [];
            });
        $provider = new CoolifyProvider;
        $method = new \ReflectionMethod($provider, $kind === 'applications' ? 'ensureApplication' : 'applyCustomService');
        $arguments = [$provider, $client, $project, $profile, 'project', 'environment', 'production', 'server'];
        if ($kind === 'applications') $arguments = [...$arguments, 'storefront-production', null];
        return $method->invoke(...$arguments);
    }

    public function testConflictingMutationIdentitiesStopBeforeFollowUpOperations(): void
    {
        foreach (['applications', 'services'] as $kind) {
            foreach ([['uuid' => 'foreign'], ['uuid' => ''], ['uuid' => null], ['data' => ['uuid' => 'foreign']], ['uuid' => 'owned', 'data' => ['uuid' => 'foreign']]] as $response) {
                $calls = [];
                try {
                    $this->update($kind, $response, $calls);
                    self::fail('Conflicting mutation identity must be refused');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('identity', $error->getMessage());
                }
                self::assertSame(['PATCH', '/'.$kind.'/owned'], end($calls));
            }
        }
    }

    public function testLostOwnershipOrUnreadableNameStopsFollowUpOperations(): void
    {
        foreach (['applications', 'services'] as $kind) {
            foreach ([['tags' => []], ['tags' => ['shippercli-managed', 'shippercli-0123456789abcdef']], ['name' => null]] as $response) {
                $calls = [];
                try {
                    $this->update($kind, $response, $calls);
                    self::fail('Invalid update metadata must be refused');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Coolify', $error->getMessage());
                }
                self::assertSame(['PATCH', '/'.$kind.'/owned'], end($calls));
            }
        }
    }

    public function testAcknowledgementOnlyResponsesPreserveTheKnownResource(): void
    {
        foreach (['applications', 'services'] as $kind) {
            foreach ([['message' => 'Updated'], ['uuid' => 'owned'], ['data' => ['uuid' => 'owned']]] as $response) {
                $calls = [];
                $result = $this->update($kind, $response, $calls);
                self::assertSame('owned', is_array($result) ? $result['uuid'] : $result);
            }
        }
    }
}
