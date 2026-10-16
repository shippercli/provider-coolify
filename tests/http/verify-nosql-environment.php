<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

$client = new CoolifyApiClient($argv[1], 'synthetic-nosql-token');
$provider = new CoolifyProvider([], $client);
$apply = new ReflectionMethod($provider, 'applyEnvironment');
foreach (['redis', 'dragonfly', 'keydb', 'mongodb', 'clickhouse'] as $type) {
    $values = ['SHIPPER_DB_MAIN_HOST' => 'db.internal', 'SHIPPER_DB_MAIN_PASSWORD' => ''];
    if (in_array($type, ['mongodb', 'clickhouse'], true)) $values['SHIPPER_DB_MAIN_USERNAME'] = '';
    $profile = static fn (array $environment) => new class($type, $environment) {
        public function __construct(private string $type, private array $environment) {}
        public function name(): string { return 'production'; }
        public function config(): array { return ['coolify' => ['databases' => [['name' => 'main', 'type' => $this->type]]]]; }
        public function environment(): object { return new class($this->environment) {
            public function __construct(private array $values) {}
            public function variables(): array { return $this->values; }
        }; }
    };
    foreach (array_keys($values) as $missing) {
        $partial = $values;
        unset($partial[$missing]);
        $failure = null;
        try { $apply->invoke($provider, $client, $type, new stdClass, $profile($partial), []); }
        catch (RuntimeException $exception) { $failure = $exception; }
        if ($failure === null || !str_contains($failure->getMessage(), $missing)) throw new RuntimeException('Incomplete non-SQL fields accepted');
    }
    $apply->invoke($provider, $client, $type, new stdClass, $profile($values), []);
}
echo "Missing fields blocked before HTTP and five explicit non-SQL environments delivered\n";

foreach (['redis', 'dragonfly', 'keydb', 'mongodb', 'clickhouse'] as $type) {
    $preview = ['SHIPPER_DB_MAIN_PR_42_HOST' => 'preview.internal', 'SHIPPER_DB_MAIN_PR_42_PASSWORD' => 'preview-only'];
    if (in_array($type, ['mongodb', 'clickhouse'], true)) $preview['SHIPPER_DB_MAIN_PR_42_USERNAME'] = 'preview-user';
    $production = array_map(static fn () => 'production-secret', $preview);
    $project = new class($production) {
        public function __construct(private array $values) {}
        public function environment(): object { return new class($this->values) {
            public function __construct(private array $values) {}
            public function variables(): array { return $this->values; }
        }; }
    };
    $makeProfile = static fn ($variables) => new class($type, $variables, $production) {
        public function __construct(private string $type, private array $variables, private array $production) {}
        public function name(): string { return 'preview'; }
        public function config(): array { return ['coolify' => ['pull_request_id' => '42', 'databases' => [['name' => 'main', 'type' => $this->type]], 'preview_environment_variables' => $this->variables]]; }
        public function environment(): object { return new class($this->production) {
            public function __construct(private array $values) {}
            public function variables(): array { return $this->values; }
        }; }
    };
    $missing = $preview;
    unset($missing['SHIPPER_DB_MAIN_PR_42_PASSWORD']);
    $failure = null;
    try { $apply->invoke($provider, $client, $type.'-preview', $project, $makeProfile($missing), []); }
    catch (RuntimeException $exception) { $failure = $exception; }
    if ($failure === null || !str_contains($failure->getMessage(), 'SHIPPER_DB_MAIN_PR_42_PASSWORD')) {
        throw new RuntimeException('Production credentials completed an incomplete preview');
    }
    $apply->invoke($provider, $client, $type.'-preview', $project, $makeProfile($preview), []);
}
echo "Five missing preview passwords blocked and five preview-only environments delivered\n";
