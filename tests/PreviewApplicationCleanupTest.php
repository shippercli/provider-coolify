<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class PreviewApplicationCleanupTest extends TestCase
{
    private function cleanup(string $mode): array
    {
        $project = new class { public function name(): string { return 'storefront'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        $scope = 'shippercli-'.substr(hash('sha256', json_encode(['storefront', 'production'], JSON_THROW_ON_ERROR)), 0, 16);
        $preview = $scope.'-pr-42';
        $calls = [];
        $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
            static function ($method, $path, $body) use (&$calls, $scope, $preview, $mode): array {
                $calls[] = [$method, $path];
                if ($path === '/databases' || $method === 'DELETE') return [];
                if ($path === '/applications/parent') return ['uuid' => $mode === 'wrong-parent' ? 'wrong' : ($mode === 'missing-parent-id' ? '' : 'parent'),
                    'name' => 'storefront-production', 'tags' => ['shippercli-managed', $scope]];
                if ($path === '/applications?tag='.rawurlencode($preview)) {
                    if ($mode === 'unavailable') throw new \RuntimeException('Unreadable workers');
                    $tags = ['shippercli-managed', $preview];
                    $workers = [
                        ['uuid' => $mode === 'missing-worker-id' ? '' : 'worker', 'name' => 'storefront-production-pr-42-queue-default-1', 'tags' => $tags],
                        ['uuid' => 'preview-app', 'name' => 'storefront-production-pr-42', 'tags' => $tags],
                        ['uuid' => 'other-kind', 'name' => 'storefront-production-pr-42-web', 'tags' => $tags],
                    ];
                    if ($mode === 'duplicate-worker') $workers[] = ['uuid' => 'copy', 'name' => $workers[0]['name'], 'tags' => $tags];
                    $workers[] = ['uuid' => 'foreign', 'name' => 'storefront-production-pr-42-queue-foreign',
                        'tags' => ['shippercli-managed', 'shippercli-0123456789abcdef-pr-42']];
                    return $workers;
                }
                return [];
            });
        $provider = new CoolifyProvider(['application_uuid' => 'parent'], $client);
        return [$provider->deletePreviewByPullRequest($project, $profile, 42), $calls, $provider->getLastError()];
    }

    public function testWorkerPreflightAndParentIdentityStopBeforePreviewDeletion(): void
    {
        foreach (['unavailable', 'missing-worker-id', 'duplicate-worker', 'wrong-parent', 'missing-parent-id'] as $mode) {
            [$result, $calls] = $this->cleanup($mode);
            self::assertFalse($result, $mode);
            foreach ($calls as $call) self::assertSame('GET', $call[0], $mode);
        }
    }

    public function testOnlyPreviewEndpointAndRecognizedWorkersAreDeleted(): void
    {
        [$result, $calls, $error] = $this->cleanup('valid');
        self::assertTrue($result, $error);
        self::assertSame([['DELETE', '/applications/parent/previews/42'], ['DELETE', '/applications/worker']],
            array_values(array_filter($calls, static fn ($call): bool => $call[0] === 'DELETE')));
    }
}
