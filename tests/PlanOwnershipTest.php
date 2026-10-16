<?php

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class PlanOwnershipTest extends TestCase
{
    public function testPlanVerifiesApplicationScopeWithoutWrites(): void
    {
        $tag = 'shippercli-'.substr(hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), 0, 16);
        foreach (['new', 'owned', 'unowned', 'explicit-unowned', 'error', 'malformed', 'invalid-identity'] as $mode) {
            $calls = [];
            $client = new CoolifyApiClient('https://coolify.example.com', 'token', transport: static function (string $method, string $path, ?array $body) use (&$calls, $mode, $tag): array {
                $calls[] = [$method, $path];
                if ($mode === 'error') throw new \RuntimeException('sensitive API detail');
                if ($mode === 'malformed') return ['message' => 'unexpected'];
                $app = ['uuid' => $mode === 'invalid-identity' ? '' : 'existing', 'name' => 'api-preview', 'tags' => $mode === 'owned' ? ['shippercli-managed', $tag] : ['shippercli-managed', 'other-scope']];
                if ($path === '/applications/existing') return $app;
                if ($mode === 'new') return [];
                return $mode === 'owned' || $path === '/applications' ? [$app] : [];
            });
            $provider = new CoolifyProvider($mode === 'explicit-unowned' ? ['application_uuid' => 'existing'] : [], $client);
            $project = new class { public function name(): string { return 'api'; } };
            $profile = new class { public function name(): string { return 'preview'; } };
            $plan = $provider->plan($project, $profile);
            self::assertSame(! in_array($mode, ['new', 'owned'], true), $plan['blocked']);
            foreach ($calls as [$method]) self::assertSame('GET', $method);
            if ($plan['blocked']) {
                self::assertCount(1, $plan['actions']);
                self::assertStringStartsWith('BLOCKED:', $plan['actions'][0]);
                self::assertStringNotContainsString('sensitive API detail', $plan['actions'][0]);
            }
        }
    }

    public function testInventoryParserRejectsMalformedListsAndEntries(): void
    {
        $provider = new CoolifyProvider();
        $method = new \ReflectionMethod($provider, 'list');
        foreach ([['data' => null], ['items' => 'bad'], ['named' => []], [null], ['data' => ['unexpected']]] as $payload) {
            try {
                $method->invoke($provider, $payload);
                self::fail('Malformed inventory must not be accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('invalid resource inventory', $exception->getMessage());
            }
        }
        self::assertSame([], $method->invoke($provider, ['data' => []]));
        self::assertSame([['uuid' => 'valid']], $method->invoke($provider, ['items' => [['uuid' => 'valid']]]));
    }
}
