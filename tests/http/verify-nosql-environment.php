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
