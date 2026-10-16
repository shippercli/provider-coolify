<?php

declare(strict_types=1);

$cli = $argv[1] ?? throw new RuntimeException('Path to installed CLI checkout is required');
$autoload = require dirname(__DIR__, 2).'/vendor/autoload.php';
$autoload->addPsr4('App\\', $cli.'/app');
$autoload->addPsr4('Symfony\\Component\\Yaml\\', $cli.'/vendor/symfony/yaml');

use App\Config\ConfigLoader;
use ShipperCli\ProviderCoolify\CoolifyProvider;
use ShipperCli\ProviderCoolify\CoolifyApiClient;

$path = tempnam(sys_get_temp_dir(), 'shipper-cli-coolify-');
file_put_contents($path, <<<'YAML'
projects:
  storefront:
    provider: coolify
    path: .
    databases:
      main:
        type: postgresql
        user: reporter
    environment:
      variables:
        DB_HOST: project.internal
    profiles:
      production:
        environment:
          variables:
            DB_PASSWORD: explicit-app-secret
        coolify:
          databases:
            main:
              password: creation-secret
YAML);
try {
    $project = (new ConfigLoader($path))->load()->getProject('storefront');
    $profile = $project->getProfile('production');
    $provider = new CoolifyProvider;
    $declarations = (new ReflectionMethod($provider, 'databaseDeclarations'))->invoke($provider, $project, $profile);
    if ($declarations !== [['name' => 'main', 'type' => 'postgresql', 'user' => 'reporter', 'password' => 'creation-secret']]) {
        throw new RuntimeException('Profile password override did not preserve the real CLI project database type/user');
    }
    $environment = (new ReflectionMethod($provider, 'completeDatabaseEnvironment'))->invoke($provider, $project, $profile,
        ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'main', 'DB_USERNAME' => 'reporter']);
    if (($environment['SHIPPER_DB_MAIN_HOST'] ?? null) !== 'project.internal'
        || ($environment['SHIPPER_DB_MAIN_PASSWORD'] ?? null) !== 'explicit-app-secret') {
        throw new RuntimeException('Real CLI environment objects did not complete the connection');
    }
    $calls = [];
    $client = new CoolifyApiClient('https://example.test', 'synthetic', transport:
        static function ($method, $endpoint, $body) use (&$calls): array {
            $calls[] = [$method, $endpoint, $body];
            if ($method === 'POST' && $endpoint === '/databases/postgresql') return ['uuid' => 'created'];
            if ($method === 'GET' && $endpoint === '/databases/created') return [
                'uuid' => 'created', 'internal_db_host' => 'db.internal', 'postgres_db' => 'main',
                'postgres_user' => 'reporter', 'postgres_password' => 'creation-secret',
                'server_uuid' => 'server', 'environment_uuid' => 'environment', 'destination_uuid' => 'destination',
            ];
            return [];
        });
    $databaseEnvironment = (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, $profile,
        'project', 'environment', 'production', 'server');
    $create = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'POST' && $call[1] === '/databases/postgresql'));
    if (count($create) !== 1 || $create[0][2]['postgres_user'] !== 'reporter' || $create[0][2]['postgres_password'] !== 'creation-secret') {
        throw new RuntimeException('Real CLI database objects produced incorrect create payload');
    }
    (new ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $client, 'app', $project, $profile, $databaseEnvironment);
    $delivered = [];
    foreach ($calls as [$method, $endpoint, $body]) {
        if ($endpoint === '/applications/app/envs' && $method === 'POST' && ($body['is_preview'] ?? null) === false) {
            $delivered[$body['key']] = $body['value'];
        }
    }
    if (($delivered['DB_CONNECTION'] ?? null) !== 'pgsql' || ($delivered['DB_USERNAME'] ?? null) !== 'reporter'
        || ($delivered['DB_HOST'] ?? null) !== 'project.internal' || ($delivered['DB_PASSWORD'] ?? null) !== 'explicit-app-secret') {
        throw new RuntimeException('Real CLI environment precedence did not reach application requests');
    }
} finally { unlink($path); }
echo "Real CLI database/profile/environment integration passed\n";
