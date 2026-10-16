<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

$project = new class { public function name(): string { return 'fixture'; } };
$profile = new class {
    public function name(): string { return 'production'; }
    public function config(): array { return []; }
};
$provider = new CoolifyProvider(['application_uuid' => 'app-1'], new CoolifyApiClient($argv[1], 'synthetic-status-token'));
$status = $provider->status($project, $profile);
if (str_contains(json_encode($status, JSON_THROW_ON_ERROR), 'synthetic-status-secret')
    || ($status['latest_deployment']['deployment_uuid'] ?? null) !== 'deployment-1'
    || ($status['deployment_history'][0]['status'] ?? null) !== 'finished'
    || ($status['running_deployments'][0]['deployment_uuid'] ?? null) !== 'deployment-1') {
    throw new RuntimeException('Deployment status summary leaked raw fields or lost its identity/state');
}
echo "Public status deployment records exclude raw secret-bearing fields\n";
