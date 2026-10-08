<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\Contracts\CapabilityManifest;
use ShipperCli\ProviderCoolify\CoolifyApiClient;
use ShipperCli\ProviderCoolify\CoolifyMcpClient;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class CoolifyProviderTest extends TestCase
{
    public function testPluginCapabilitiesSatisfyTheSharedManifest(): void
    {
        $provider = new CoolifyProvider;

        self::assertSame('coolify', $provider->getName());
        self::assertSame($provider->capabilities(), CapabilityManifest::from($provider->capabilities())->toArray());
    }

    public function testSecretManagerProfileSettingsAreValidated(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array
            {
                return ['coolify' => ['secret_manager' => [
                    'integration_token_uuid' => 'integration-uuid',
                    'settings' => ['invalid-list-entry'],
                ]]];
            }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
        ]);

        self::assertContains('Coolify secret_manager settings must be an object-like map.', $provider->validate($project, $profile));
    }

    public function testPreviewInventoryIsExposedOnlyWhenMcpIsEnabled(): void
    {
        $mcpClient = new CoolifyMcpClient('https://coolify.example.test', 'api-token', transport: static function (array $message, array $headers): array {
            if ($message['method'] === 'notifications/initialized') {
                return ['status' => 202, 'headers' => [], 'body' => ''];
            }
            $result = match ($message['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25'],
                'tools/list' => ['tools' => [['name' => 'list_application_previews']]],
                'tools/call' => ['structuredContent' => ['data' => ['previews' => [[
                    'pull_request_id' => 42,
                    'fqdn' => 'https://42.preview.example.test',
                    'status' => 'running',
                ]]]]],
                default => throw new \\LogicException('Unexpected Coolify MCP request.'),
            };

            return [
                'status' => 200,
                'headers' => [],
                'body' => \\json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR),
            ];
        });

        $enabledProvider = new CoolifyProvider(['mcp_enabled' => true], mcpClient: $mcpClient);
        self::assertSame([[
            'pull_request_id' => 42,
            'fqdn' => 'https://42.preview.example.test',
            'status' => 'running',
        ]], $enabledProvider->applicationPreviews('application-uuid'));
        self::assertSame([], (new CoolifyProvider)->applicationPreviews('application-uuid'));
    }

    public function testApplyAttachesConfiguredSecretManagerToPrimaryApplication(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array { return ['coolify' => []]; }
        };
        $requests = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                $requests[] = [$method, $path, $body];
                if ($method === 'POST' && $path === '/applications/public') {
                    return ['uuid' => 'primary-app-uuid'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
            'project_uuid' => 'project-1',
            'environment_uuid' => 'environment-1',
            'secret_manager' => [
                'integration_token_uuid' => 'integration-uuid',
                'settings' => ['project' => 'storefront'],
            ],
        ], $client);

        self::assertTrue($provider->apply($project, $profile));
        self::assertContains(
            ['PATCH', '/applications/primary-app-uuid/secret-manager', [
                'integration_token_uuid' => 'integration-uuid',
                'settings' => ['project' => 'storefront'],
            ]],
            $requests,
        );
    }

    public function testCreateModeServerSpecIsValidatedBeforeProvisioning(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repository(): object { return new class { public function url(): string { return 'https://github.com/acme/storefront'; } }; }
        };
        $profile = static fn (array $spec): object => new class($spec) {
            public function __construct(private array $spec) {}
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function server(): object { return new class($this->spec) {
                public function __construct(private array $spec) {}
                public function isCreate(): bool { return true; }
                public function isExisting(): bool { return false; }
                public function spec(): array { return $this->spec; }
            }; }
            public function config(): array { return ['coolify' => ['url' => 'https://coolify.example.test', 'api_token' => 'api-token']]; }
        };
        $provider = new CoolifyProvider;

        $invalid = $provider->validate($project, $profile(['cloud_provider' => 'aws', 'name' => 'production']));
        self::assertContains("Coolify server provisioning does not support cloud_provider 'aws'.", $invalid);

        $valid = $provider->validate($project, $profile([
            'cloud_provider' => 'hetzner',
            'cloud_provider_token_uuid' => 'token-123',
            'private_key_uuid' => 'key-123',
            'name' => 'production',
            'location' => 'fsn1',
            'server_type' => 'cx23',
            'image' => 2404,
        ]));
        self::assertSame([], $valid);
    }

    public function testApiClientDebugOutputNeverIncludesCredentials(): void
    {
        $client = new CoolifyApiClient('https://coolify.example.test', 'sensitive-token');

        self::assertSame('[redacted]', $client->__debugInfo()['token']);
    }

    public function testLogsResolveComposeServiceByManagedIdentity(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['sub_service_name' => 'worker']]; }
        };
        $provider = new CoolifyProvider(['api_token' => 'api-token'], new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body): array {
                if ($path === '/services?tag='.rawurlencode('shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16))) {
                    return [[
                        'uuid' => 'managed-service-uuid',
                        'name' => 'storefront-production-stack',
                        'tags' => ['shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16), 'shippercli-managed'],
                    ]];
                }
                if ($path === '/services/managed-service-uuid/logs?sub_service_name=worker&lines=100&show_timestamps=0') {
                    return ['logs' => "started\nready\n"];
                }

                throw new \RuntimeException("Unexpected Coolify request: {$method} {$path}");
            },
        ));

        self::assertSame(['started', 'ready'], $provider->logs($project, $profile));
    }

    public function testDatabaseLogsResolveOnlyCurrentOwnedDatabase(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => [
                    'database_log_name' => 'primary',
                    'databases' => [['name' => 'primary', 'type' => 'mysql']],
                ]];
            }
        };
        $tag = 'shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16);
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, $tag): array {
                $calls[] = [$method, $path, $body];
                if ($path === '/databases') {
                    return [['uuid' => 'db-primary', 'name' => 'primary', 'tags' => [$tag, 'shippercli-managed']]];
                }
                if ($path === '/databases/db-primary/logs?lines=100&show_timestamps=0') {
                    return ['logs' => "db ready\nconnected\n"];
                }

                throw new \RuntimeException("Unexpected Coolify request: {$method} {$path}");
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame(['db ready', 'connected'], $provider->logs($project, $profile));
        self::assertSame([
            ['GET', '/databases', null],
            ['GET', '/databases/db-primary/logs?lines=100&show_timestamps=0', null],
        ], $calls);
    }

    public function testWorkloadLogsResolveOnlyOwnedWorkerInPreviewScope(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => [
                    'pull_request_id' => 42,
                    'workload_log_name' => 'storefront-production-pr-42-queue-worker-1',
                ]];
            }
        };
        $tag = 'shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16).'-pr-42';
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, $tag): array {
                $calls[] = [$method, $path, $body];
                if ($path === '/applications?tag='.rawurlencode($tag)) {
                    return [[
                        'uuid' => 'worker-pr-42',
                        'name' => 'storefront-production-pr-42-queue-worker-1',
                        'tags' => [$tag, 'shippercli-managed'],
                    ]];
                }
                if ($path === '/applications/worker-pr-42/logs?lines=100') {
                    return ['logs' => "worker ready\n"];
                }

                throw new \RuntimeException("Unexpected Coolify request: {$method} {$path}");
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame(['worker ready'], $provider->logs($project, $profile));
        self::assertSame([
            ['GET', '/applications?tag='.rawurlencode($tag), null],
            ['GET', '/applications/worker-pr-42/logs?lines=100', null],
        ], $calls);
    }

    public function testWorkerWithRestartDisabledDeploysInitiallyAndRetriesFailedDeployment(): void
    {
        $latestStatus = '';
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, &$latestStatus): array {
                $calls[] = [$method, $path, $body];

                return $latestStatus === '' ? [] : [['uuid' => 'deployment-1', 'status' => $latestStatus]];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $needsDeployment = new \ReflectionMethod($provider, 'workerNeedsDeployment');

        self::assertTrue($needsDeployment->invoke($provider, $client, 'worker-123', false));
        $latestStatus = 'finished';
        self::assertFalse($needsDeployment->invoke($provider, $client, 'worker-123', false));
        $latestStatus = 'failed';
        self::assertTrue($needsDeployment->invoke($provider, $client, 'worker-123', false));
        self::assertTrue($needsDeployment->invoke($provider, $client, 'worker-123', true));
        self::assertSame([
            ['GET', '/deployments/applications/worker-123?skip=0&take=1', null],
            ['GET', '/deployments/applications/worker-123?skip=0&take=1', null],
            ['GET', '/deployments/applications/worker-123?skip=0&take=1', null],
        ], $calls);
    }

    public function testPreviewWorkersUsePullRequestScopedNamesAndOwnershipTags(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };
        $previewProfile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['pull_request_id' => 42]]; }
        };
        $provider = new CoolifyProvider;
        $resourceName = new \ReflectionMethod($provider, 'workerResourceName');
        $resourceTag = new \ReflectionMethod($provider, 'workerResourceTag');

        self::assertSame('storefront-production', $resourceName->invoke($provider, $project, $profile));
        self::assertSame('storefront-production-pr-42', $resourceName->invoke($provider, $project, $previewProfile));
        self::assertSame('storefront-production-pr-42', $resourceName->invoke($provider, $project, $profile, '42'));
        self::assertSame(
            $resourceTag->invoke($provider, $project, $profile).'-pr-42',
            $resourceTag->invoke($provider, $project, $previewProfile),
        );
    }

    public function testDatabaseConnectionValuesRespectExplicitEnvironmentOverrides(): void
    {
        $provider = new CoolifyProvider;
        $method = new \ReflectionMethod($provider, 'mergeDatabaseEnvironment');
        $environment = ['DB_PASSWORD' => 'explicit-secret'];
        $arguments = [&$environment, 'mysql', 'storefront', [
            'internal_db_host' => 'database.internal',
            'internal_db_port' => 3306,
            'mysql_database' => 'storefront',
            'mysql_user' => 'shipper',
        ], 'generated-secret', 'shipper'];

        $method->invokeArgs($provider, $arguments);

        self::assertSame('explicit-secret', $environment['DB_PASSWORD']);
        self::assertSame('database.internal', $environment['DB_HOST']);
        self::assertSame('mysql', $environment['DB_CONNECTION']);
    }

    public function testSslSettingControlsCoolifyDomainCertificateScheme(): void
    {
        $profile = new class {
            public function domain(): string { return 'https://shop.example.test'; }
            public function aliases(): array { return ['https://www.shop.example.test']; }
            public function get(string $key, mixed $default = ''): mixed { return $key === 'domain' ? $this->domain() : $default; }
        };
        $project = new class {
            public function ssl(): object
            {
                return new class {
                    public function enabled(): bool { return false; }
                };
            }
            public function redirects(): array { return []; }
        };
        $provider = new CoolifyProvider;
        $desiredDomains = new \ReflectionMethod($provider, 'desiredDomains');

        self::assertSame(
            ['http://shop.example.test', 'http://www.shop.example.test'],
            $desiredDomains->invoke($provider, $project, $profile),
        );

        $enabledProject = new class {
            public function ssl(): object
            {
                return new class {
                    public function enabled(): bool { return true; }
                };
            }
            public function redirects(): array { return []; }
        };

        self::assertSame(
            ['https://shop.example.test', 'https://www.shop.example.test'],
            $desiredDomains->invoke($provider, $enabledProject, $profile),
        );
    }

    public function testDeployResourceFailsWhenCoolifyReportsMissingPullRequestPreview(): void
    {
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static fn (): array => [
                'deployments' => [[
                    'message' => 'Pull request 42 not found for this resource.',
                    'resource_uuid' => 'app-123',
                ]],
            ],
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $deployResource = new \ReflectionMethod($provider, 'deployResource');

        try {
            $deployResource->invoke($provider, $client, 'app-123', '42');
            self::fail('Expected a missing Coolify pull request preview to fail deployment.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Pull request 42 not found', $exception->getMessage());
        }
    }

    public function testTopLevelApplicationSettingsAreForwardedToCoolifyCreatePayload(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array
            {
                return ['coolify' => [
                    'health_check_path' => '/health',
                    'limits_memory' => '512m',
                ]];
            }
        };
        $requestBody = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requestBody): array {
                if ($method === 'POST' && $path === '/applications/public') {
                    $requestBody = $body;

                    return ['uuid' => 'app-123'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $ensureApplication = new \ReflectionMethod($provider, 'ensureApplication');

        $ensureApplication->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'storefront-production', null);

        self::assertSame('/health', $requestBody['health_check_path']);
        self::assertSame('512m', $requestBody['limits_memory']);
    }

    public function testWorkerApplicationsReceiveConfiguredRestartAndGraceSettings(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['application' => [
                    'max_restart_count' => 5,
                    'stop_grace_period' => 30,
                    'health_check_path' => '/health',
                    'limits_memory' => '512m',
                    'build_command' => 'npm run build',
                ]]];
            }
        };
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'POST' && $path === '/applications/public') {
                    return ['uuid' => 'worker-app'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $ensureWorkerReplicas = new \ReflectionMethod($provider, 'ensureWorkerReplicas');
        $desiredWorkerNames = [];

        $ensureWorkerReplicas->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'queue-default', 'php artisan queue:work', 1, $desiredWorkerNames);

        self::assertContains(['PATCH', '/applications/worker-app', [
            'max_restart_count' => 5,
            'stop_grace_period' => 30,
            'health_check_path' => '/health',
            'limits_memory' => '512m',
        ]], $calls);
        self::assertNotContains(['PATCH', '/applications/worker-app', ['build_command' => 'npm run build']], $calls);
    }

    public function testConfiguredApplicationTagsAreAddedAlongsideShipperOwnershipTags(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array { return ['coolify' => ['application' => ['tags' => ['team-web', 'customer-facing']]]]; }
        };
        $requestBody = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requestBody): array {
                if ($method === 'POST' && $path === '/applications/public') {
                    $requestBody = $body;

                    return ['uuid' => 'app-123'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $ensureApplication = new \ReflectionMethod($provider, 'ensureApplication');
        $ensureApplication->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'storefront-production', null);

        self::assertSame(
            ['team-web', 'customer-facing', 'shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16), 'shippercli-managed'],
            $requestBody['tags'],
        );
    }

    public function testApplicationTagConfigurationMustBeAListOfStrings(): void
    {
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['application' => ['tags' => ['valid', 42]]]]; }
        };
        $provider = new CoolifyProvider;
        $validateOptions = new \ReflectionMethod($provider, 'validateApplicationOptions');

        self::assertContains(
            "Coolify application setting 'tags' must be a list of strings at least two characters long.",
            $validateOptions->invoke($provider, ['tags' => ['valid', 42]], 'git', 'nixpacks'),
        );
        self::assertContains(
            "Coolify application setting 'tags' must be a list of strings at least two characters long.",
            $validateOptions->invoke($provider, ['tags' => ['x']], 'git', 'nixpacks'),
        );
    }

    public function testExistingApplicationTagsUseCoolifyTagEndpointWithoutPatchingTags(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array { return ['coolify' => ['application' => ['tags' => ['team-web', 'customer-facing']]]]; }
        };
        $tag = 'shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16);
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, $tag): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'GET' && $path === '/applications?tag='.rawurlencode($tag)) {
                    return [[
                        'uuid' => 'app-123',
                        'name' => 'storefront-production',
                        'git_repository' => 'https://github.com/example/storefront',
                        'tags' => [$tag, 'shippercli-managed', 'manual-tag'],
                    ]];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $ensureApplication = new \ReflectionMethod($provider, 'ensureApplication');
        $ensureApplication->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'storefront-production', null);

        $patch = null;
        foreach ($calls as $call) {
            if ($call[0] === 'PATCH' && $call[1] === '/applications/app-123') {
                $patch = $call;
            }
        }
        self::assertNotNull($patch);
        self::assertArrayNotHasKey('tags', $patch[2]);
        self::assertSame(
            ['POST', '/applications/app-123/tags', ['tag_names' => ['team-web', 'customer-facing']]],
            $calls[array_key_last($calls)],
        );
    }

    public function testShipperDeployScriptIsUsedOnlyAsFallbackPostDeploymentCommand(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
            public function deployScript(): string { return 'php artisan migrate --force'; }
        };
        $profile = new class {
            public array $settings = [];
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array { return ['coolify' => $this->settings]; }
            public function get(string $key, mixed $default = null): mixed { return $this->settings[$key] ?? $default; }
        };
        $payloads = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$payloads): array {
                if ($method === 'POST' && $path === '/applications/public') {
                    $payloads[] = $body;

                    return ['uuid' => 'app-123'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $ensureApplication = new \ReflectionMethod($provider, 'ensureApplication');
        $invoke = static fn () => $ensureApplication->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'storefront-production', null);

        $invoke();
        self::assertSame('php artisan migrate --force', $payloads[0]['post_deployment_command']);

        $profile->settings = ['deploy_script' => 'php artisan migrate --force --seed'];
        $invoke();
        self::assertSame('php artisan migrate --force --seed', $payloads[1]['post_deployment_command']);

        $profile->settings = ['post_deployment_command' => 'php artisan optimize'];
        $invoke();
        self::assertSame('php artisan optimize', $payloads[2]['post_deployment_command']);

        $profile->settings = ['pre_deployment_command' => 'php artisan down'];
        $invoke();
        self::assertSame('php artisan migrate --force', $payloads[3]['post_deployment_command']);
        self::assertSame('php artisan down', $payloads[3]['pre_deployment_command']);

        $profile->settings = ['application' => ['post_deployment_command' => '']];
        $invoke();
        self::assertSame('php artisan migrate --force', $payloads[4]['post_deployment_command']);
    }

    public function testNestedCustomLabelsPreserveGeneratedPathRedirectLabels(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
            public function redirects(): array
            {
                return ['legacy' => new class {
                    public function from(): string { return '/old'; }
                    public function to(): string { return '/new'; }
                    public function enabled(): bool { return true; }
                }];
            }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array
            {
                return ['coolify' => ['application' => [
                    'custom_labels' => 'example.user.label=preserved',
                ]]];
            }
        };
        $payload = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$payload): array {
                if ($method === 'GET' && $path === '/servers/server-1') {
                    return ['proxy_type' => 'traefik'];
                }
                if ($method === 'POST' && $path === '/applications/public') {
                    $payload = $body;

                    return ['uuid' => 'app-123'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $ensureApplication = new \ReflectionMethod($provider, 'ensureApplication');
        $ensureApplication->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'storefront-production', null);

        self::assertIsArray($payload);
        self::assertStringContainsString('example.user.label=preserved', $payload['custom_labels']);
        self::assertStringContainsString('traefik.http.middlewares.shipper-', $payload['custom_labels']);
        self::assertStringContainsString('coolify.traefik.middlewares=shipper-', $payload['custom_labels']);
    }

    public function testPlanReportsEffectiveShipperDeployScriptFallback(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function deployScript(): string { return 'php artisan migrate --force'; }
        };
        $profile = new class {
            public array $settings = [];
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => $this->settings]; }
            public function get(string $key, mixed $default = null): mixed { return $this->settings[$key] ?? $default; }
        };
        $provider = new CoolifyProvider;

        self::assertContains(
            'Configure the Shipper deployment script as a Coolify post-deployment command',
            $provider->plan($project, $profile)['actions'],
        );
        $profile->settings = ['post_deployment_command' => 'php artisan optimize'];
        self::assertNotContains(
            'Configure the Shipper deployment script as a Coolify post-deployment command',
            $provider->plan($project, $profile)['actions'],
        );
        $profile->settings = ['pre_deployment_command' => 'php artisan down'];
        self::assertContains(
            'Configure the Shipper deployment script as a Coolify post-deployment command',
            $provider->plan($project, $profile)['actions'],
        );
    }

    public function testPlanUsesNestedApplicationBuildPackOverrides(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function phpVersion(): string { return '8.3'; }
            public function nginxConfig(): string { return 'server_tokens off;'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return [
                    'coolify' => ['build_pack' => 'nixpacks'],
                    'application' => ['build_pack' => 'static'],
                ];
            }
        };
        $provider = new CoolifyProvider;
        $actions = $provider->plan($project, $profile)['actions'];

        self::assertNotContains('Set Nixpacks PHP version to 8.3', $actions);
        self::assertContains('Apply Shipper Nginx configuration as Coolify custom Nginx configuration', $actions);
    }

    public function testSqliteDatabaseOptionsAreSentOnCreateAndUpdate(): void
    {
        $requests = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                $requests[] = [$method, $path, $body];

                if ($method === 'GET' && $path === '/databases') {
                    return [];
                }

                if ($method === 'POST' && $path === '/databases/sqlite') {
                    return ['uuid' => 'database-1'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function databases(): array { return []; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => [
                    'databases' => [['name' => 'local', 'type' => 'sqlite']],
                    'database_options' => ['local' => ['sqlite_databases' => 'app.sqlite,cache.sqlite']],
                ]];
            }
        };

        (new \ReflectionMethod($provider, 'applyDatabases'))->invoke(
            $provider,
            $client,
            $project,
            $profile,
            'project-1',
            'environment-1',
            'production',
            'server-1',
        );

        $createRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request[0] === 'POST' && $request[1] === '/databases/sqlite',
        ));
        $updateRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request[0] === 'PATCH' && $request[1] === '/databases/database-1',
        ));

        self::assertSame('app.sqlite,cache.sqlite', $createRequests[0][2]['sqlite_databases']);
        self::assertSame('app.sqlite,cache.sqlite', $updateRequests[0][2]['sqlite_databases']);
    }

    public function testDaemonUserIsComposedWithConfiguredWorkerDockerOptions(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array
            {
                return ['coolify' => [
                    'application' => ['custom_docker_run_options' => '--init'],
                    'secret_manager' => ['integration_token_uuid' => 'integration-uuid', 'settings' => ['project' => 'storefront']],
                ]];
            }
        };
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return $method === 'POST' && $path === '/applications/public' ? ['uuid' => 'worker-1'] : [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $ensureWorkerReplicas = new \ReflectionMethod($provider, 'ensureWorkerReplicas');
        $desiredWorkerNames = [];

        $ensureWorkerReplicas->invoke(
            $provider,
            $client,
            $project,
            $profile,
            'project-1',
            'environment-1',
            'production',
            'server-1',
            'daemon-worker',
            'php artisan schedule:work',
            1,
            $desiredWorkerNames,
            [],
            true,
            ['custom_docker_run_options' => "--user '1000:1000'"],
        );

        self::assertContains(
            ['PATCH', '/applications/worker-1', ['custom_docker_run_options' => "--init --user '1000:1000'"]],
            $calls,
        );
        self::assertContains(
            ['PATCH', '/applications/worker-1/secret-manager', [
                'integration_token_uuid' => 'integration-uuid',
                'settings' => ['project' => 'storefront'],
            ]],
            $calls,
        );
    }

    public function testStaticBuildPackRejectsSeparateShipperWorkers(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function queues(): array
            {
                return ['default' => new class {
                    public function enabled(): bool { return true; }
                    public function processes(): int { return 1; }
                    public function user(): string { return ''; }
                }];
            }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['build_pack' => 'static']]; }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
        ]);

        self::assertContains(
            "Coolify build_pack 'static' cannot run Shipper queue workers or daemons as separate applications; use a Nixpacks, Railpack, or Dockerfile application build with a runnable start command.",
            $provider->validate($project, $profile),
        );
    }

    public function testWorkerStartCommandOverridesSharedApplicationCommandAndRouting(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function projectRoot(): string { return '/'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function branch(): string { return 'main'; }
            public function config(): array
            {
                return ['coolify' => ['application' => [
                    'start_command' => 'php artisan serve',
                    'domains' => 'https://shop.example.test',
                    'redirect' => 'www',
                    'preview_url_template' => '{{branch}}.example.test',
                ]]];
            }
        };
        $requestBody = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requestBody): array {
                if ($method === 'POST' && $path === '/applications/public') {
                    $requestBody = $body;

                    return ['uuid' => 'worker-123'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $ensureApplication = new \ReflectionMethod($provider, 'ensureApplication');
        $ensureApplication->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1', 'storefront-production-queue-default-1', 'php artisan queue:work database');

        self::assertSame('php artisan queue:work database', $requestBody['start_command']);
        self::assertFalse($requestBody['is_auto_deploy_enabled']);
        self::assertSame('', $requestBody['domains']);
        self::assertArrayNotHasKey('redirect', $requestBody);
        self::assertArrayNotHasKey('preview_url_template', $requestBody);
    }

    public function testComposeServiceComponentOverridesPatchDocumentedFields(): void
    {
        $requests = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                $requests[] = [$method, $path, $body];

                return match ($path) {
                    '/services/service-1/applications' => [['uuid' => 'application-1', 'name' => 'web']],
                    '/services/service-1/databases' => [['uuid' => 'database-1', 'name' => 'mysql']],
                    default => [],
                };
            },
        );
        $provider = new CoolifyProvider;
        $apply = new \ReflectionMethod($provider, 'applyServiceComponentOptions');

        $apply->invoke($provider, $client, 'service-1', [
            'applications' => ['web' => [
                'url' => 'https://shop.example.test',
                'is_gzip_enabled' => true,
                'force_domain_override' => true,
            ]],
            'databases' => ['mysql' => [
                'is_public' => true,
                'public_port' => 3307,
            ]],
        ]);

        self::assertContains([
            'PATCH',
            '/services/service-1/applications/application-1?force_domain_override=1',
            ['url' => 'https://shop.example.test', 'is_gzip_enabled' => true],
        ], $requests);
        self::assertContains([
            'PATCH',
            '/services/service-1/databases/database-1',
            ['is_public' => true, 'public_port' => 3307],
        ], $requests);
    }

    public function testServiceDescriptionAndTagsAreSentWithShipperOwnershipTags(): void
    {
        $requests = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                $requests[] = [$method, $path, $body];

                return $method === 'POST' && $path === '/services'
                    ? ['uuid' => 'service-1']
                    : [];
            },
        );
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['service' => [
                    'type' => 'actualbudget',
                    'name' => 'finance-stack',
                    'description' => 'Finance dashboard',
                    'tags' => ['team-finance'],
                ]]];
            }
        };

        (new \ReflectionMethod(new CoolifyProvider, 'applyCustomService'))->invoke(
            new CoolifyProvider,
            $client,
            $project,
            $profile,
            'project-1',
            'environment-1',
            'production',
            'server-1',
        );

        $createRequest = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request[0] === 'POST' && $request[1] === '/services',
        ))[0];
        self::assertSame('finance-stack', $createRequest[2]['name']);
        self::assertSame('Finance dashboard', $createRequest[2]['description']);
        self::assertContains('team-finance', $createRequest[2]['tags']);
        self::assertContains('shippercli-managed', $createRequest[2]['tags']);
    }

    public function testDatabaseOptionsRequireCoolifyDocumentedValueTypes(): void
    {
        $provider = new CoolifyProvider;
        $isValidType = new \ReflectionMethod($provider, 'validDatabaseOptionType');

        self::assertTrue($isValidType->invoke($provider, 'is_public', true));
        self::assertFalse($isValidType->invoke($provider, 'is_public', 'true'));
        self::assertTrue($isValidType->invoke($provider, 'public_port', 6379));
        self::assertFalse($isValidType->invoke($provider, 'public_port', '6379'));
        self::assertTrue($isValidType->invoke($provider, 'limits_memory', '512m'));
        self::assertFalse($isValidType->invoke($provider, 'limits_memory', 512));
    }

    public function testMongoRootPasswordUsesDocumentedPostCreateUpdateField(): void
    {
        $requests = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$requests): array {
                $requests[] = [$method, $path, $body];

                if ($method === 'GET' && $path === '/databases') {
                    return [];
                }
                if ($method === 'POST' && $path === '/databases/mongodb') {
                    return ['uuid' => 'mongodb-1'];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function databases(): array { return []; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['databases' => [[
                    'name' => 'mongo',
                    'type' => 'mongodb',
                    'user' => 'root',
                    'password' => 'test-secret',
                ]]]];
            }
        };

        (new \ReflectionMethod($provider, 'applyDatabases'))->invoke(
            $provider,
            $client,
            $project,
            $profile,
            'project-1',
            'environment-1',
            'production',
            'server-1',
        );

        $createRequest = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request[0] === 'POST' && $request[1] === '/databases/mongodb',
        ))[0];
        $updateRequest = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request[0] === 'PATCH' && $request[1] === '/databases/mongodb-1',
        ))[0];

        self::assertArrayNotHasKey('mongo_initdb_root_password', $createRequest[2]);
        self::assertSame('test-secret', $updateRequest[2]['mongo_initdb_root_password']);
    }

    public function testCustomDockerRunOptionsAreLimitedToCoolifysSupportedFlags(): void
    {
        $provider = new CoolifyProvider;
        $validate = new \ReflectionMethod($provider, 'validateApplicationOptions');

        self::assertSame([], $validate->invoke($provider, [
            'custom_docker_run_options' => '--shm-size 1g --init --entrypoint "sh -c \'php artisan queue:work\'"',
        ], 'docker_image', ''));
        self::assertSame([], $validate->invoke($provider, [
            'custom_docker_run_options' => '--user '.\escapeshellarg('1000:1000'),
        ], 'docker_image', ''));
        self::assertContains(
            'Coolify custom_docker_run_options contains an unsupported or malformed Docker option.',
            $validate->invoke($provider, ['custom_docker_run_options' => '--network host'], 'docker_image', ''),
        );
        self::assertContains(
            'Coolify custom_docker_run_options contains an unsupported or malformed Docker option.',
            $validate->invoke($provider, ['custom_docker_run_options' => '--entrypoint "unterminated'], 'docker_image', ''),
        );
    }

    public function testCoolifySwarmApplicationOptionsAreAccepted(): void
    {
        $provider = new CoolifyProvider;
        $validate = new \ReflectionMethod($provider, 'validateApplicationOptions');

        self::assertSame([], $validate->invoke($provider, [
            'swarm_replicas' => 3,
            'swarm_placement_constraints' => 'node.role == worker',
        ], 'git', 'nixpacks'));
        self::assertContains(
            "Coolify application setting 'swarm_replicas' must be an integer.",
            $validate->invoke($provider, ['swarm_replicas' => '3'], 'git', 'nixpacks'),
        );
        self::assertContains(
            "Coolify application setting 'swarm_replicas' must be between 1 and ".PHP_INT_MAX.'.',
            $validate->invoke($provider, ['swarm_replicas' => 0], 'git', 'nixpacks'),
        );
    }

    public function testPreviewCleanupUsesPullRequestEndpointWithoutDeletingProductionApplication(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };
        $provider = new CoolifyProvider(['api_token' => 'api-token']);
        $tagMethod = new \ReflectionMethod($provider, 'resourceTag');
        $tag = $tagMethod->invoke($provider, $project, $profile);
        $siteIdMethod = new \ReflectionMethod($provider, 'siteId');
        $productionSiteId = $siteIdMethod->invoke($provider, 'app-production');
        $production = ['uuid' => 'app-production', 'name' => 'storefront-production', 'tags' => [$tag, 'shippercli-managed']];
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, $production): array {
                $calls[] = [$method, $path, $body];

                return $path === '/applications?tag='.rawurlencode((string) $production['tags'][0]) ? [$production] : [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertTrue($provider->deletePreviewByPullRequest($project, $profile, 42));
        self::assertContains(['DELETE', '/applications/app-production/previews/42', null], $calls);
        self::assertFalse($provider->deleteSiteWithDatabases($project, $profile, $productionSiteId));
        self::assertNotContains(['DELETE', '/applications/app-production', null], $calls);
        self::assertNotContains(['DELETE', '/databases/db-production', null], $calls);
    }

    public function testPreviewSitesCanBeRecoveredFromOwnedWorkerTagsWithoutMcp(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'preview'; }
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'domain' ? '${GITHUB_PR_NUMBER}.preview.example.test' : $default;
            }
            public function config(): array { return []; }
        };
        $provider = new CoolifyProvider(['api_token' => 'api-token']);
        $resourceTag = new \ReflectionMethod($provider, 'resourceTag');
        $tag = $resourceTag->invoke($provider, $project, $profile);
        $previewTag = $tag.'-pr-42';
        $worker = [
            'uuid' => 'worker-pr-42',
            'name' => 'storefront-preview-pr-42-queue-default-1',
            'tags' => [$previewTag, 'shippercli-managed'],
        ];
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, $worker, $tag, $previewTag): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'DELETE' && $path === '/applications/worker-pr-42') {
                    return [];
                }

                return match ($path) {
                    '/applications?tag='.rawurlencode($tag) => [],
                    '/applications?tag='.rawurlencode($previewTag) => [$worker],
                    '/applications' => [$worker],
                    '/databases' => [],
                    default => throw new \RuntimeException("Unexpected Coolify request: {$method} {$path}"),
                };
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame([['site_id' => -42, 'domain' => '42.preview.example.test']], $provider->listSites($project, $profile));
        self::assertSame([
            ['GET', '/applications?tag='.rawurlencode($tag), null],
            ['GET', '/applications', null],
            ['GET', '/databases', null],
        ], $calls);
        self::assertTrue($provider->deleteSiteWithDatabases($project, $profile, -42));
    }

    public function testDestroyRemovesOwnedPreviewWorkersButNotPreviewApplications(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };
        $provider = new CoolifyProvider;
        $resourceTagMethod = new \ReflectionMethod($provider, 'resourceTag');
        $tag = $resourceTagMethod->invoke($provider, $project, $profile);
        $previewTag = $tag.'-pr-42';
        $previewApp = ['uuid' => 'preview-app-42', 'name' => 'storefront-production-pr-42', 'tags' => [$previewTag, 'shippercli-managed']];
        $worker = ['uuid' => 'worker-42', 'name' => 'storefront-production-pr-42-queue-default-1', 'tags' => [$previewTag, 'shippercli-managed']];
        $otherWorker = ['uuid' => 'other-worker', 'name' => 'other-queue-default-1', 'tags' => ['other-project-pr-42', 'shippercli-managed']];
        $deleted = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$deleted, $tag, $previewApp, $worker, $otherWorker): array {
                if ($method === 'DELETE') {
                    $deleted[] = $path;

                    return [];
                }

                return match ($path) {
                    '/applications?tag='.rawurlencode($tag), '/services?tag='.rawurlencode($tag), '/databases' => [],
                    '/applications' => [$previewApp, $worker, $otherWorker],
                    default => throw new \RuntimeException("Unexpected Coolify request: {$method} {$path}"),
                };
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertTrue($provider->destroy($project, $profile));
        self::assertSame(['/applications/worker-42'], $deleted);
    }

    public function testAdditionalDestinationsRejectInvalidAndUnsupportedConfigurations(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public array $settings = [];
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => $this->settings]; }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
            'source_type' => 'docker_image',
            'docker_image' => 'ghcr.io/example/storefront:latest',
        ]);

        $profile->settings = ['additional_destination_uuids' => 'destination-2'];
        self::assertContains(
            'Coolify additional_destination_uuids must be a list of non-empty destination UUIDs.',
            $provider->validate($project, $profile),
        );

        $profile->settings = ['additional_destination_uuids' => ['destination-2'], 'pull_request_id' => 42, 'docker_tag' => 'pr-42'];
        self::assertContains(
            'Coolify pull request previews are not deployed to additional destinations.',
            $provider->validate($project, $profile),
        );

        $profile->settings = ['additional_destination_uuids' => ['destination-2'], 'storages' => [['type' => 'persistent', 'name' => 'uploads', 'mount_path' => '/uploads']]];
        self::assertContains(
            'Coolify additional destinations do not support applications with persistent storage.',
            $provider->validate($project, $profile),
        );
    }

    public function testRailpackPhpExtensionsAreRestrictedToGitRailpackApplications(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['build_pack' => 'railpack', 'railpack_php_extensions' => 'gd,imagick,redis']]; }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
        ]);

        self::assertNotContains(
            'Coolify railpack_php_extensions is supported only for Git applications using the Railpack build pack.',
            $provider->validate($project, $profile),
        );
        $invalidProfile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['build_pack' => 'nixpacks', 'railpack_php_extensions' => 'gd,redis']]; }
        };
        self::assertContains(
            'Coolify railpack_php_extensions is supported only for Git applications using the Railpack build pack.',
            $provider->validate($project, $invalidProfile),
        );
    }

    public function testManagedApplicationEnvironmentPruningDeletesByCoolifyVariableUuid(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return $path === '/applications/app-1/envs'
                    ? [['uuid' => 'env-123', 'key' => 'OLD/KEY', 'is_preview' => false, 'comment' => 'Managed by ShipperCLI']]
                    : [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $project = new class {
            public function environment(): object
            {
                return new class {
                    public function variables(): array { return []; }
                };
            }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };

        (new \ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $client, 'app-1', $project, $profile);

        self::assertSame([
            ['GET', '/applications/app-1/envs', null],
            ['DELETE', '/applications/app-1/envs/env-123', null],
        ], $calls);
    }

    public function testManagedEnvironmentAppliesLiteralMultilineAndSecretFlags(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $project = new class {
            public function environment(): object
            {
                return new class {
                    public function variables(): array
                    {
                        return [
                            'PRIVATE_KEY' => "line one\nline two",
                            'API_TOKEN' => '{{team.API_TOKEN}}',
                        ];
                    }
                };
            }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => [
                    'secret_environment_variables' => ['PRIVATE_KEY'],
                    'environment_variable_options' => ['PRIVATE_KEY' => ['is_literal' => true, 'is_multiline' => true]],
                ]];
            }
        };

        (new \ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $client, 'app-1', $project, $profile);

        self::assertContains(['POST', '/applications/app-1/envs', [
            'key' => 'PRIVATE_KEY',
            'value' => "line one\nline two",
            'is_preview' => false,
            'is_shown_once' => true,
            'is_literal' => true,
            'is_multiline' => true,
            'comment' => 'Managed by ShipperCLI',
        ]], $calls);
        self::assertContains(['POST', '/applications/app-1/envs', [
            'key' => 'API_TOKEN',
            'value' => '{{team.API_TOKEN}}',
            'is_preview' => false,
            'is_shown_once' => false,
            'comment' => 'Managed by ShipperCLI',
        ]], $calls);
    }

    public function testRailpackPhpExtensionsAreSynchronizedAsManagedApplicationEnvironment(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $project = new class {
            public function environment(): object
            {
                return new class {
                    public function variables(): array { return []; }
                };
            }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['build_pack' => 'railpack', 'railpack_php_extensions' => 'gd, imagick,redis']]; }
        };
        $applyEnvironment = new \ReflectionMethod($provider, 'applyEnvironment');
        $applyEnvironment->invoke($provider, $client, 'app-123', $project, $profile);

        self::assertSame([
            ['GET', '/applications/app-123/envs', null],
            ['POST', '/applications/app-123/envs', [
                'key' => 'RAILPACK_PHP_EXTENSIONS',
                'value' => 'gd,imagick,redis',
                'is_preview' => false,
                'is_shown_once' => false,
                'comment' => 'Managed by ShipperCLI',
            ]],
            ['POST', '/applications/app-123/envs', [
                'key' => 'RAILPACK_PHP_EXTENSIONS',
                'value' => 'gd,imagick,redis',
                'is_preview' => true,
                'is_shown_once' => false,
                'comment' => 'Managed by ShipperCLI',
            ]],
        ], $calls);
    }

    public function testRailpackExtensionsDoNotRequireProjectPhpVersion(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['build_pack' => 'railpack', 'railpack_php_extensions' => 'gd,redis']]; }
        };
        $applyEnvironment = new \ReflectionMethod($provider, 'applyEnvironment');
        $applyEnvironment->invoke($provider, $client, 'app-123', new \stdClass, $profile);

        self::assertSame('RAILPACK_PHP_EXTENSIONS', $calls[1][2]['key']);
        self::assertSame('gd,redis', $calls[1][2]['value']);
    }

    public function testCoolifyProfileDatabaseDeclarationsRejectMalformedShapeAndFieldTypes(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
        ]);
        $malformedProfile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['databases' => ['primary' => 'mysql']]]; }
        };
        self::assertContains(
            "Coolify database declaration 'primary' must be a map.",
            $provider->validate($project, $malformedProfile),
        );

        $invalidFieldProfile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['databases' => ['primary' => [
                    'type' => ['mysql'],
                ]]]];
            }
        };
        self::assertContains(
            "Coolify database declaration 'primary' field 'type' must be a string.",
            $provider->validate($project, $invalidFieldProfile),
        );
    }

    public function testApplicationStorageValidationRejectsDuplicateMountPaths(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['storages' => [
                    ['type' => 'persistent', 'name' => 'uploads', 'mount_path' => '/storage'],
                    ['type' => 'file', 'mount_path' => '/storage', 'content' => 'conflict'],
                ]]];
            }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
        ]);

        self::assertContains(
            "Coolify storage '1' duplicates mount_path '/storage'.",
            $provider->validate($project, $profile),
        );
    }

    public function testPreviewStorageRejectsHostPathBindsForFileAndPersistentMounts(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
            public function repositoryUrl(): string { return 'https://github.com/example/storefront'; }
            public function queues(): array { return [new \stdClass]; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => [
                    'pull_request_id' => 42,
                    'storages' => [[
                        'type' => 'file',
                        'mount_path' => '/shared',
                        'is_directory' => true,
                        'fs_path' => '/srv/shared',
                    ]],
                ]];
            }
        };
        $provider = new CoolifyProvider([
            'url' => 'https://coolify.example.test',
            'api_token' => 'api-token',
            'server_uuid' => 'server-1',
        ]);
        $errors = $provider->validate($project, $profile);

        self::assertContains(
            "Coolify preview storage '0' cannot use a host-bound mount; use an isolated persistent volume or an inline file mount to avoid sharing production data.",
            $errors,
        );

        $hostPathProfile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => [
                    'is_preview_deployments_enabled' => true,
                    'storages' => [[
                        'type' => 'persistent',
                        'name' => 'shared-data',
                        'mount_path' => '/data',
                        'host_path' => '/srv/shared-data',
                    ]],
                ]];
            }
        };
        self::assertContains(
            "Coolify preview storage '0' cannot use a host-bound mount; use an isolated persistent volume or an inline file mount to avoid sharing production data.",
            $provider->validate($project, $hostPathProfile),
        );
        $productionHostPathProfile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['storages' => [[
                    'type' => 'persistent',
                    'name' => 'shared-data',
                    'mount_path' => '/data',
                    'host_path' => '/srv/shared-data',
                ]]]];
            }
        };
        self::assertNotContains(
            "Coolify preview storage '0' cannot use a host-bound mount; use an isolated persistent volume or an inline file mount to avoid sharing production data.",
            $provider->validate($project, $productionHostPathProfile),
        );
    }

    public function testStatusKeepsResourceFieldsWhenPrimaryApplicationIsMissing(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return []; }
        };
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static fn (): array => [],
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame([
            'provider' => 'coolify',
            'status' => 'not_found',
            'application' => null,
            'workloads' => [],
            'service' => null,
            'databases' => [],
            'pull_request_id' => null,
        ], $provider->status($project, $profile));
    }

    public function testAdditionalDestinationReconciliationIsAdditiveAndIdempotent(): void
    {
        $attached = [['uuid' => 'destination-1']];
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$attached, &$calls): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'GET' && $path === '/applications/app-123/destinations') {
                    return $attached;
                }
                if ($method === 'POST' && $path === '/applications/app-123/destinations') {
                    $attached[] = ['uuid' => $body['destination_uuid']];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $reconcile = new \ReflectionMethod($provider, 'reconcileAdditionalDestinations');

        $reconcile->invoke($provider, 'app-123', ['destination-1', 'destination-2']);
        $reconcile->invoke($provider, 'app-123', ['destination-1', 'destination-2']);

        self::assertSame([
            ['GET', '/applications/app-123/destinations', null],
            ['POST', '/applications/app-123/destinations', ['destination_uuid' => 'destination-2']],
            ['GET', '/applications/app-123/destinations', null],
        ], $calls);
    }

    public function testApplicationDestinationOperationsUseCoolifyMultiServerEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return $method === 'GET' ? [['uuid' => 'destination-2']] : [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame([['uuid' => 'destination-2']], $provider->applicationDestinations('app-123'));
        $provider->attachApplicationDestination('app-123', 'destination-2');
        $provider->detachApplicationDestination('app-123', 'destination-2');

        self::assertSame([
            ['GET', '/applications/app-123/destinations', null],
            ['POST', '/applications/app-123/destinations', ['destination_uuid' => 'destination-2']],
            ['DELETE', '/applications/app-123/destinations/destination-2', null],
        ], $calls);
    }

    public function testResourceDeletionCleanupFlagsAreExplicitForAllResourceTypes(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->deleteApplicationResource('app-1', true, false, false, true);
        $provider->deleteServiceResource('service-2');
        $provider->deleteDatabaseResource('db-3', false, false, false, false);

        self::assertSame([
            ['DELETE', '/applications/app-1?delete_configurations=1&delete_volumes=0&docker_cleanup=0&delete_connected_networks=1', null],
            ['DELETE', '/services/service-2?delete_configurations=1&delete_volumes=1&docker_cleanup=1&delete_connected_networks=1', null],
            ['DELETE', '/databases/db-3?delete_configurations=0&delete_volumes=0&docker_cleanup=0&delete_connected_networks=0', null],
        ], $calls);
    }

    public function testApplicationServiceAndDatabaseInventoryEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [['uuid' => 'resource-123']];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame([['uuid' => 'resource-123']], $provider->applications());
        self::assertSame([['uuid' => 'resource-123']], $provider->applications('shippercli-managed'));
        self::assertSame([['uuid' => 'resource-123']], $provider->applicationResource('app-123'));
        self::assertSame([['uuid' => 'resource-123']], $provider->services());
        self::assertSame([['uuid' => 'resource-123']], $provider->databases());

        self::assertSame([
            ['GET', '/applications', null],
            ['GET', '/applications?tag=shippercli-managed', null],
            ['GET', '/applications/app-123', null],
            ['GET', '/services', null],
            ['GET', '/databases', null],
        ], $calls);
    }

    public function testTeamAndResourceTagEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->tags();
        $provider->tag('tag-1');
        $provider->createTag(['name' => 'production']);
        $provider->updateTag('tag-1', ['name' => 'live']);
        $provider->resourceTags('databases', 'db-1');
        $provider->addResourceTags('databases', 'db-1', ['production', 'shippercli-managed']);
        $provider->removeResourceTag('applications', 'app-2', 'tag-1');
        $provider->deleteTag('tag-1');

        self::assertSame([
            ['GET', '/tags', null],
            ['GET', '/tags/tag-1', null],
            ['POST', '/tags', ['name' => 'production']],
            ['PATCH', '/tags/tag-1', ['name' => 'live']],
            ['GET', '/databases/db-1/tags', null],
            ['POST', '/databases/db-1/tags', ['tag_names' => ['production', 'shippercli-managed']]],
            ['DELETE', '/applications/app-2/tags/tag-1', null],
            ['DELETE', '/tags/tag-1', null],
        ], $calls);
    }

    public function testServerListingEndpoint(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [['uuid' => 'server-123']];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        self::assertSame([['uuid' => 'server-123']], $provider->servers());
        self::assertSame([['GET', '/servers', null]], $calls);
    }

    public function testServerProxyConfigurationIsSavedWithMultilineContentEncoded(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return ['message' => 'Proxy configuration saved.'];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $configuration = "services:\n  traefik:\n    image: traefik";

        self::assertSame(
            ['message' => 'Proxy configuration saved.'],
            $provider->saveServerProxyConfiguration('server/123', $configuration),
        );
        self::assertSame([[
            'PUT',
            '/servers/server%2F123/proxy/configuration',
            ['configuration' => base64_encode($configuration)],
        ]], $calls);
    }

    public function testServerDestinationManagementEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->destinations();
        $provider->serverDestinations('server-123');
        $provider->destination('destination-456');
        $provider->createServerDestination('server-123', ['network' => 'shipper-net', 'type' => 'standalone']);
        $provider->updateDestination('destination-456', ['name' => 'Production']);
        $provider->deleteDestination('destination-456');

        self::assertSame([
            ['GET', '/destinations', null],
            ['GET', '/servers/server-123/destinations', null],
            ['GET', '/destinations/destination-456', null],
            ['POST', '/servers/server-123/destinations', ['network' => 'shipper-net', 'type' => 'standalone']],
            ['PATCH', '/destinations/destination-456', ['name' => 'Production']],
            ['DELETE', '/destinations/destination-456', null],
        ], $calls);
    }

    public function testApplicationAndServiceCloneMigrationAndMoveEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->cloneApplication('app-123', 'destination-2', 'storefront-copy', true);
        $provider->migrateApplication('app-123', 'destination-3', false);
        $provider->moveApplication('app-123', 'environment-2');
        $provider->cloneService('service-123', 'destination-2', null, true);
        $provider->migrateService('service-123', 'destination-3');
        $provider->moveService('service-123', 'environment-2');
        $provider->cloneDatabase('database-123', 'destination-2', 'mysql-copy', true);
        $provider->migrateDatabase('database-123', 'destination-3', false);
        $provider->moveDatabase('database-123', 'environment-2');

        self::assertSame([
            ['POST', '/applications/app-123/clone', ['destination_uuid' => 'destination-2', 'clone_volumes' => true, 'name' => 'storefront-copy']],
            ['POST', '/applications/app-123/migrate', ['destination_uuid' => 'destination-3', 'migrate_volumes' => false]],
            ['POST', '/applications/app-123/move', ['environment_uuid' => 'environment-2']],
            ['POST', '/services/service-123/clone', ['destination_uuid' => 'destination-2', 'clone_volumes' => true]],
            ['POST', '/services/service-123/migrate', ['destination_uuid' => 'destination-3', 'migrate_volumes' => true]],
            ['POST', '/services/service-123/move', ['environment_uuid' => 'environment-2']],
            ['POST', '/databases/database-123/clone', ['destination_uuid' => 'destination-2', 'clone_volumes' => true, 'name' => 'mysql-copy']],
            ['POST', '/databases/database-123/migrate', ['destination_uuid' => 'destination-3', 'migrate_volumes' => false]],
            ['POST', '/databases/database-123/move', ['environment_uuid' => 'environment-2']],
        ], $calls);
    }

    public function testStorageCrudAndBackupScheduleEndpointsAcrossResourceTypes(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->resourceStorages('applications', 'app-1');
        $provider->createResourceStorage('applications', 'app-1', ['type' => 'persistent', 'mount_path' => '/data']);
        $provider->updateResourceStorage('services', 'service-2', ['uuid' => 'storage-2', 'type' => 'file']);
        $provider->deleteResourceStorage('databases', 'db-3', 'storage-3');
        $provider->setResourceStorageBackupSchedule('applications', 'app-1', 'storage-1', ['frequency' => 'daily']);
        $provider->deleteResourceStorageBackupSchedule('services', 'service-2', 'storage-2');
        $provider->runResourceStorageBackup('databases', 'db-3', 'storage-3');

        self::assertSame([
            ['GET', '/applications/app-1/storages', null],
            ['POST', '/applications/app-1/storages', ['type' => 'persistent', 'mount_path' => '/data']],
            ['PATCH', '/services/service-2/storages', ['uuid' => 'storage-2', 'type' => 'file']],
            ['DELETE', '/databases/db-3/storages/storage-3', null],
            ['PUT', '/applications/app-1/storages/storage-1/backups', ['frequency' => 'daily']],
            ['DELETE', '/services/service-2/storages/storage-2/backups', null],
            ['POST', '/databases/db-3/storages/storage-3/backups/run', null],
        ], $calls);
    }

    public function testDatabaseBackupConfigurationAndExecutionEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->databaseBackups('database-123');
        $provider->createDatabaseBackup('database-123', ['frequency' => '0 2 * * *', 'enabled' => true]);
        $provider->updateDatabaseBackup('database-123', 'backup-456', ['enabled' => false]);
        $provider->databaseBackupExecutions('database-123', 'backup-456');
        $provider->runDatabaseStorageBackup('database-123', 'storage-789');

        self::assertSame([
            ['GET', '/databases/database-123/backups', null],
            ['POST', '/databases/database-123/backups', ['frequency' => '0 2 * * *', 'enabled' => true]],
            ['PATCH', '/databases/database-123/backups/backup-456', ['enabled' => false]],
            ['GET', '/databases/database-123/backups/backup-456/executions', null],
            ['POST', '/databases/database-123/storages/storage-789/backups/run', null],
        ], $calls);
    }

    public function testScheduledTaskStatusIncludesLatestExecutionOnlyForShipperTasks(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
        };
        $prefix = 'shipper-'.substr(hash('sha256', 'storefront|production'), 0, 16).'-';
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use ($prefix): array {
                if ($method === 'GET' && $path === '/applications/app-1/scheduled-tasks') {
                    return [
                        ['uuid' => 'task-1', 'name' => $prefix.'nightly', 'command' => 'php artisan backup', 'frequency' => 'daily', 'enabled' => true],
                        ['uuid' => 'task-2', 'name' => 'operator-maintenance', 'command' => 'true', 'frequency' => 'hourly', 'enabled' => true],
                    ];
                }
                if ($method === 'GET' && $path === '/applications/app-1/scheduled-tasks/task-1/executions') {
                    return [
                        ['uuid' => 'execution-new', 'status' => 'success', 'created_at' => '2026-10-02T01:00:00Z', 'started_at' => '2026-10-02T03:00:00Z'],
                        ['uuid' => 'execution-old', 'status' => 'failed', 'created_at' => '2026-10-02T02:00:00Z', 'started_at' => '2026-10-02T02:30:00Z'],
                    ];
                }

                throw new \RuntimeException("Unexpected Coolify request: {$method} {$path}");
            },
        );
        $provider = new CoolifyProvider([], $client);
        $scheduledTaskStatuses = new \ReflectionMethod($provider, 'scheduledTaskStatuses');

        self::assertSame([[
            'uuid' => 'task-1',
            'name' => $prefix.'nightly',
            'command' => 'php artisan backup',
            'frequency' => 'daily',
            'enabled' => true,
            'container' => null,
            'executions' => [
                ['uuid' => 'execution-new', 'status' => 'success', 'created_at' => '2026-10-02T01:00:00Z', 'started_at' => '2026-10-02T03:00:00Z'],
                ['uuid' => 'execution-old', 'status' => 'failed', 'created_at' => '2026-10-02T02:00:00Z', 'started_at' => '2026-10-02T02:30:00Z'],
            ],
            'latest_execution' => ['uuid' => 'execution-new', 'status' => 'success', 'created_at' => '2026-10-02T01:00:00Z', 'started_at' => '2026-10-02T03:00:00Z'],
        ]], $scheduledTaskStatuses->invoke($provider, 'applications', 'app-1', $project, $profile));
    }

    public function testCronTasksConfiguredForComposeContainersAreNotDuplicatedOnTheApplication(): void
    {
        $project = new class {
            public function cron(): array
            {
                return [
                    'app-task' => new class {
                        public function command(): string { return 'php artisan schedule:run'; }
                        public function frequency(): string { return 'daily'; }
                        public function enabled(): bool { return true; }
                    },
                    'worker-task' => new class {
                        public function command(): string { return 'php artisan queue:restart'; }
                        public function frequency(): string { return 'hourly'; }
                        public function enabled(): bool { return true; }
                    },
                ];
            }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['scheduled_task_containers' => ['worker-task' => 'worker'], 'cron_timeout' => 900]]; }
        };
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $applyScheduledTasks = new \ReflectionMethod($provider, 'applyScheduledTasks');

        $applyScheduledTasks->invoke($provider, $client, 'app-1', $project, $profile, 'applications', 'application');
        $applyScheduledTasks->invoke($provider, $client, 'service-1', $project, $profile, 'services', 'service');

        $createdTasks = \array_values(\array_filter($calls, static fn (array $call): bool => $call[0] === 'POST'));
        self::assertCount(2, $createdTasks);
        self::assertSame('/applications/app-1/scheduled-tasks', $createdTasks[0][1]);
        self::assertStringEndsWith('-app-task', $createdTasks[0][2]['name']);
        self::assertSame(900, $createdTasks[0][2]['timeout']);
        self::assertSame('/services/service-1/scheduled-tasks', $createdTasks[1][1]);
        self::assertStringEndsWith('-worker-task', $createdTasks[1][2]['name']);
        self::assertSame('worker', $createdTasks[1][2]['container']);
        self::assertSame(900, $createdTasks[1][2]['timeout']);
    }

    public function testDatabaseBackupOptionsPatchOnlyTheExplicitlyIdentifiedSchedule(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['database_backups' => [
                    'primary' => ['uuid' => 'backup-123', 'frequency' => 'daily', 'enabled' => true],
                ]]];
            }
        };
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $applyBackupOptions = new \ReflectionMethod($provider, 'applyDatabaseBackupOptions');

        $applyBackupOptions->invoke($provider, $client, 'database-456', $project, $profile, 'primary');

        self::assertSame([[
            'PATCH',
            '/databases/database-456/backups/backup-123',
            ['frequency' => 'daily', 'enabled' => true],
        ]], $calls);
    }

    public function testDatabaseStorageIsCreatedAndBackupScheduleUsesDiscoveredStorageUuid(): void
    {
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array
            {
                return ['coolify' => ['database_options' => ['primary' => [
                    'storages' => [[
                        'type' => 'persistent',
                        'name' => 'mysql-data',
                        'mount_path' => '/var/lib/mysql',
                    ]],
                    'storage_backups' => ['/var/lib/mysql' => ['frequency' => 'daily', 'enabled' => true]],
                ]]]];
            }
        };
        $calls = [];
        $storageInventoryReads = 0;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, &$storageInventoryReads): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'GET' && $path === '/databases/database-123/storages') {
                    ++$storageInventoryReads;

                    return $storageInventoryReads === 1 ? [] : ['persistent_storages' => [[
                        'uuid' => 'storage-456',
                        'type' => 'persistent',
                        'name' => 'mysql-data',
                        'mount_path' => '/var/lib/mysql',
                    ]]];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $applyDatabaseStorages = new \ReflectionMethod($provider, 'applyDatabaseStorages');

        $applyDatabaseStorages->invoke($provider, $client, 'database-123', $project, $profile, 'primary');

        self::assertSame([
            ['GET', '/databases/database-123/storages', null],
            ['POST', '/databases/database-123/storages', ['type' => 'persistent', 'name' => 'mysql-data', 'mount_path' => '/var/lib/mysql']],
            ['GET', '/databases/database-123/storages', null],
            ['PUT', '/databases/database-123/storages/storage-456/backups', ['frequency' => 'daily', 'enabled' => true]],
        ], $calls);
    }

    public function testApplicationEnvironmentVariableEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->applicationEnvironmentVariables('app-123');
        $provider->upsertApplicationEnvironmentVariable('app-123', [
            'key' => 'APP_KEY',
            'value' => 'secret',
            'is_preview' => true,
            'is_shown_once' => true,
        ]);
        $provider->upsertApplicationEnvironmentVariables('app-123', [
            ['key' => 'APP_ENV', 'value' => 'production'],
        ]);
        $provider->deleteApplicationEnvironmentVariable('app-123', 'APP_KEY');

        self::assertSame([
            ['GET', '/applications/app-123/envs', null],
            ['PATCH', '/applications/app-123/envs', [
                'key' => 'APP_KEY',
                'value' => 'secret',
                'is_preview' => true,
                'is_shown_once' => true,
            ]],
            ['PATCH', '/applications/app-123/envs/bulk', ['data' => [
                ['key' => 'APP_ENV', 'value' => 'production'],
            ]]],
            ['DELETE', '/applications/app-123/envs/APP_KEY', null],
        ], $calls);
    }

    public function testServiceEnvironmentVariableEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->serviceEnvironmentVariables('service-123');
        $provider->createServiceEnvironmentVariable('service-123', ['key' => 'APP_ENV', 'value' => 'staging']);
        $provider->updateServiceEnvironmentVariable('service-123', ['key' => 'APP_ENV', 'value' => 'production']);
        $provider->deleteServiceEnvironmentVariable('service-123', 'env-456');

        self::assertSame([
            ['GET', '/services/service-123/envs', null],
            ['POST', '/services/service-123/envs', ['key' => 'APP_ENV', 'value' => 'staging']],
            ['PATCH', '/services/service-123/envs', ['key' => 'APP_ENV', 'value' => 'production']],
            ['DELETE', '/services/service-123/envs/env-456', null],
        ], $calls);
    }

    public function testCatalogServiceCreationUsesTypeWithoutCustomComposePayload(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return match ([$method, $path]) {
                    ['GET', '/services?tag='.rawurlencode('shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16))] => [],
                    ['POST', '/services'] => ['uuid' => 'service-123', 'service_type' => 'actualbudget'],
                    default => [],
                };
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['service' => ['type' => 'actualbudget']]]; }
        };
        $apply = new \ReflectionMethod($provider, 'applyCustomService');

        self::assertSame('service-123', $apply->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1'));
        $createCall = null;
        foreach ($calls as $call) {
            if ($call[0] === 'POST' && $call[1] === '/services') {
                $createCall = $call;
                break;
            }
        }
        self::assertNotNull($createCall);
        self::assertSame('actualbudget', $createCall[2]['type']);
        self::assertArrayNotHasKey('docker_compose_raw', $createCall[2]);
    }

    public function testCatalogServiceCannotBeConvertedToCustomComposeInPlace(): void
    {
        $tag = 'shippercli-'.substr(hash('sha256', 'storefront|production'), 0, 16);
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, $tag): array {
                $calls[] = [$method, $path, $body];
                if ($method === 'GET' && $path === '/services?tag='.rawurlencode($tag)) {
                    return [[
                        'uuid' => 'service-123',
                        'name' => 'storefront-production-stack',
                        'service_type' => 'actualbudget',
                        'tags' => [$tag, 'shippercli-managed'],
                    ]];
                }

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $project = new class {
            public function name(): string { return 'storefront'; }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['docker_compose' => "services:\n  web:\n    image: nginx"]]; }
        };
        $apply = new \ReflectionMethod($provider, 'applyCustomService');

        try {
            $apply->invoke($provider, $client, $project, $profile, 'project-1', 'environment-1', 'production', 'server-1');
            self::fail('Expected a catalog-to-Compose service conversion to be refused.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refusing to convert', $exception->getMessage());
        }
        self::assertNotContains('PATCH', array_column($calls, 0));
    }

    public function testSharedEnvironmentVariableCrudSupportsAllCoolifyScopes(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->sharedEnvironmentVariables('team');
        $provider->createSharedEnvironmentVariable('project', ['key' => 'APP_ENV'], 'project-123');
        $provider->sharedEnvironmentVariables('environment', 'project-123', 'staging env');
        $provider->updateSharedEnvironmentVariable('server', '42', ['value' => 'production'], 'server-456');
        $provider->deleteSharedEnvironmentVariable('environment', '43', 'project-123', 'staging env');

        self::assertSame([
            ['GET', '/team/envs', null],
            ['POST', '/projects/project-123/envs', ['key' => 'APP_ENV']],
            ['GET', '/projects/project-123/environments/staging%20env/envs', null],
            ['PATCH', '/servers/server-456/envs/42', ['value' => 'production']],
            ['DELETE', '/projects/project-123/environments/staging%20env/envs/43', null],
        ], $calls);
    }

    public function testSharedEnvironmentVariablePayloadsValidateDocumentedFields(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider([], $client);
        $provider->createSharedEnvironmentVariable('team', [
            'key' => 'PRIVATE_KEY',
            'value' => "line one\nline two",
            'is_literal' => true,
            'is_multiline' => true,
            'is_shown_once' => true,
            'comment' => 'Private deployment key',
        ]);

        try {
            $provider->createSharedEnvironmentVariable('team', ['key' => 'INVALID', 'unknown' => true]);
            self::fail('Expected unsupported shared environment-variable fields to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Unsupported Coolify shared environment-variable field', $exception->getMessage());
        }

        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0][0]);
        self::assertSame('/team/envs', $calls[0][1]);
        self::assertSame(true, $calls[0][2]['is_shown_once']);
    }

    public function testEnvironmentResolutionReusesNameOnlyCoolifyListEntries(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [['id' => 7, 'name' => 'staging']];
            },
        );
        $profile = new class {
            public function name(): string { return 'production'; }
            public function config(): array { return ['coolify' => ['environment_name' => 'staging']]; }
        };
        $provider = new CoolifyProvider(['project_uuid' => 'project-123'], $client);
        $resolveEnvironment = new \ReflectionMethod($provider, 'resolveEnvironment');

        self::assertSame(['project-123', '', 'staging'], $resolveEnvironment->invoke($provider, $client, new \stdClass, $profile));
        self::assertSame([['GET', '/projects/project-123/environments', null]], $calls);

        $placement = new \ReflectionMethod($provider, 'environmentPlacement');
        self::assertSame(['environment_name' => 'staging'], $placement->invoke($provider, '', 'staging'));
        self::assertSame(['environment_uuid' => 'environment-456'], $placement->invoke($provider, 'environment-456', 'staging'));
    }

    public function testProjectAndEnvironmentCrudEndpoints(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return [];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->projects();
        $provider->project('project-123');
        $provider->createProject(['name' => 'shop']);
        $provider->updateProject('project-123', ['description' => 'Storefront']);
        $provider->environments('project-123');
        $provider->environment('project-123', 'staging env');
        $provider->createEnvironment('project-123', ['name' => 'staging']);
        $provider->updateEnvironment('project-123', 'staging env', ['name' => 'staging']);
        $provider->deleteEnvironment('project-123', 'staging env');
        $provider->deleteProjectResource('project-123');

        self::assertSame([
            ['GET', '/projects', null],
            ['GET', '/projects/project-123', null],
            ['POST', '/projects', ['name' => 'shop']],
            ['PATCH', '/projects/project-123', ['description' => 'Storefront']],
            ['GET', '/projects/project-123/environments', null],
            ['GET', '/projects/project-123/staging%20env', null],
            ['POST', '/projects/project-123/environments', ['name' => 'staging']],
            ['PATCH', '/projects/project-123/environments/staging%20env', ['name' => 'staging']],
            ['DELETE', '/projects/project-123/environments/staging%20env', null],
            ['DELETE', '/projects/project-123', null],
        ], $calls);
    }

    public function testServerUpdateForwardsDocumentedConnectionAndDeploymentSettings(): void
    {
        $request = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$request): array {
                $request = [$method, $path, $body];

                return ['uuid' => 'sv-123'];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $provider->updateServer('sv-123', [
            'ip' => '192.0.2.20',
            'port' => 2222,
            'server_role' => 'both',
            'concurrent_builds' => 4,
            'server_disk_usage_notification_threshold' => 85,
        ]);

        self::assertSame([
            'PATCH',
            '/servers/sv-123',
            [
                'ip' => '192.0.2.20',
                'port' => 2222,
                'server_role' => 'both',
                'concurrent_builds' => 4,
                'server_disk_usage_notification_threshold' => 85,
            ],
        ], $request);
    }

    public function testServerUpdateRejectsUnsupportedRoleBeforeCallingCoolify(): void
    {
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static fn (): array => self::fail('Invalid server settings must not reach Coolify.'),
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('server_role must be deployment, build, or both');
        $provider->updateServer('sv-123', ['server_role' => 'invalid']);
    }

    public function testServerRecordDeletionRequiresOwnershipAndNoRemainingResources(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return match ([$method, $path]) {
                    ['POST', '/servers'] => ['uuid' => 'sv-123'],
                    ['GET', '/servers/sv-123'] => ['uuid' => 'sv-123', 'name' => 'shippercli-prod'],
                    ['GET', '/servers/sv-123/resources'] => [],
                    default => [],
                };
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $created = $provider->createServer(['name' => 'prod', 'ip' => '192.0.2.10']);

        self::assertTrue($provider->deleteServer('sv-123', $created['ownership_token']));
        self::assertSame(['DELETE', '/servers/sv-123', null], $calls[array_key_last($calls)]);
    }

    public function testShipperProvisionedCloudServerDeletionAlsoDeletesTheLinkedInstance(): void
    {
        $calls = [];
        $ownershipMarker = null;
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls, &$ownershipMarker): array {
                $calls[] = [$method, $path, $body];

                return match ([$method, $path]) {
                    ['POST', '/servers/hetzner'] => ['uuid' => 'sv-cloud', 'hetzner_server_id' => 731],
                    ['GET', '/servers/sv-cloud'] => [
                        'uuid' => 'sv-cloud',
                        'name' => 'shippercli-production',
                        'cloud_provider' => 'hetzner',
                        'hetzner_server_id' => 731,
                        'description' => $ownershipMarker,
                    ],
                    ['GET', '/servers/sv-cloud/resources'] => [],
                    ['PATCH', '/servers/sv-cloud'] => (static function () use (&$ownershipMarker, $body): array {
                        $ownershipMarker = $body['description'] ?? null;

                        return ['description' => $ownershipMarker];
                    })(),
                    default => [],
                };
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $created = $provider->createServer([
            'cloud_provider' => 'hetzner',
            'name' => 'production',
            'location' => 'fsn1',
            'server_type' => 'cx23',
            'image' => 2404,
            'private_key_uuid' => 'key-123',
        ]);

        $resolved = $provider->resolveServer(['uuid' => 'sv-cloud']);
        self::assertNotNull($resolved);
        self::assertFalse($resolved['server_record_only']);
        self::assertSame($created['ownership_token'], $resolved['ownership_token']);
        self::assertTrue($provider->deleteServer('sv-cloud', $resolved['ownership_token']));
        self::assertSame(['DELETE', '/servers/sv-cloud?delete_from_provider=true', null], $calls[array_key_last($calls)]);
    }

    public function testResolvedExistingCloudServerRemainsRecordOnly(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return ['uuid' => 'sv-existing', 'name' => 'shippercli-existing', 'cloud_provider' => 'digitalocean', 'digitalocean_droplet_id' => 927];
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $resolved = $provider->resolveServer(['uuid' => 'sv-existing']);

        self::assertNotNull($resolved);
        self::assertTrue($resolved['server_record_only']);
        self::assertFalse($provider->deleteServer('sv-existing', $resolved['ownership_token']));
        self::assertSame('Refusing to delete a linked cloud server without proof that Shipper provisioned it.', $provider->getLastError());
        self::assertNotContains(['DELETE', '/servers/sv-existing?delete_from_provider=true', null], $calls);
    }

    public function testServerRecordIsNotRemovedWhileCoolifyResourcesRemain(): void
    {
        $calls = [];
        $client = new CoolifyApiClient(
            'https://coolify.example.test',
            'api-token',
            transport: static function (string $method, string $path, ?array $body) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return match ([$method, $path]) {
                    ['POST', '/servers'] => ['uuid' => 'sv-456'],
                    ['GET', '/servers/sv-456'] => ['uuid' => 'sv-456', 'name' => 'shippercli-stage'],
                    ['GET', '/servers/sv-456/resources'] => [['uuid' => 'app-1']],
                    default => [],
                };
            },
        );
        $provider = new CoolifyProvider(['api_token' => 'api-token'], $client);
        $created = $provider->createServer(['name' => 'stage', 'ip' => '192.0.2.11']);

        self::assertFalse($provider->deleteServer('sv-456', $created['ownership_token']));
        self::assertStringContainsString('still hosts managed resources', $provider->getLastError());
        self::assertNotContains(['DELETE', '/servers/sv-456', null], $calls);
    }
}
