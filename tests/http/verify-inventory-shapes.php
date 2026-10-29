<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

$client = new CoolifyApiClient($argv[1], 'synthetic-local-only');
foreach (['empty-object', 'numeric-object', 'wrapped-object', 'wrapped-items-object', 'blank', 'both'] as $path) {
    $failure = null;
    try { $client->requestInventory('/'.$path); } catch (RuntimeException $error) { $failure = $error; }
    if ($failure === null) throw new RuntimeException('Invalid inventory JSON shape was accepted: '.$path);
}
$provider = new CoolifyProvider([], $client);
$parse = new ReflectionMethod($provider, 'list');
foreach (['empty-array', 'wrapped-array', 'items-array'] as $path) {
    if ($parse->invoke($provider, $client->requestInventory('/'.$path)) !== []) {
        throw new RuntimeException('Valid empty inventory was rejected');
    }
}
if ($client->request('PATCH', '/ack') !== []) throw new RuntimeException('Empty-object mutation acknowledgement changed');
$project = new class { public function name(): string { return 'fixture'; } };
$profile = new class { public function name(): string { return 'production'; } };
if (! $provider->plan($project, $profile)['blocked']) throw new RuntimeException('Empty-object database response did not block plan');
if ($provider->destroy($project, $profile)) throw new RuntimeException('Empty-object database response did not fail destroy');
echo "HTTP inventory shape and blocked-flow checks passed\n";

$duplicateProject = new class {
    public function databases(): array {
        return array_map(static fn ($type) => new class($type) {
            public function __construct(private string $type) {}
            public function name(): string { return 'main'; }
            public function type(): string { return $this->type; }
        }, ['mysql', 'postgresql']);
    }
};
$overrideProfile = new class {
    public function name(): string { return 'production'; }
    public function config(): array { return ['coolify' => ['databases' => ['main' => ['password' => 'synthetic-override']]]]; }
};
if (!$provider->plan($duplicateProject, $overrideProfile)['blocked']) throw new RuntimeException('Duplicate project declarations did not block planning');
$failure = null;
try {
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $duplicateProject, $overrideProfile,
        'project', 'environment', 'production', 'server');
} catch (RuntimeException $exception) { $failure = $exception; }
if ($failure === null || $failure->getMessage() !== 'Coolify project database declarations must have unique names before merging with profile declarations.') {
    throw new RuntimeException('Duplicate project database merge was accepted');
}
echo "Duplicate project declarations rejected before provider HTTP operations\n";
