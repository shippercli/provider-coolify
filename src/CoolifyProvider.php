<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

use ShipperCli\Contracts\DeploymentLogsProviderInterface;
use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\DeploymentRollbackProviderInterface;
use ShipperCli\Contracts\DeploymentStatusProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;
use ShipperCli\Contracts\ServerLifecycleProviderInterface;

final class CoolifyProvider implements DeploymentProviderInterface, DeploymentLogsProviderInterface, DeploymentStatusProviderInterface, DeploymentRollbackProviderInterface, ProviderCapabilitiesInterface, ServerLifecycleProviderInterface
{
    use CoolifyPreviewOperations;

    use CoolifyServerLifecycleMetadata;

    use CoolifySecretManagerOperations;
    use CoolifyDatabaseBackupOperations;
    use CoolifySystemOperations;
    use CoolifyServiceComponentOperations;
    use CoolifyPrivateKeyOperations;
    use CoolifyAdditionalResourceOperations;
    use CoolifySourceOperations;

    private const MARKER = 'shippercli-managed';
    private const ENVIRONMENT_MARKER = 'Managed by ShipperCLI';

    /** @var array<string, mixed> */
    private readonly array $config;

    private ?CoolifyApiClient $client;

    private ?CoolifyMcpClient $mcpClient;

    private string $lastError = '';

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], ?CoolifyApiClient $client = null, ?CoolifyMcpClient $mcpClient = null)
    {
        $this->config = $config;
        $this->client = $client;
        $this->mcpClient = $mcpClient;
    }

    public function getName(): string
    {
        return 'coolify';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function capabilities(): array
    {
        return [
            'app_deploy' => ['state' => 'supported'],
            'domain_management' => ['state' => 'supported'],
            'ssl' => ['state' => 'supported', 'notes' => 'Coolify provisions and renews Let’s Encrypt certificates for HTTPS domains. Wildcard certificates require prior Coolify proxy/DNS-challenge setup; custom certificates must already be installed in the Coolify proxy because the public API does not manage certificate files. Shipper maps forceHttps to Coolify’s HTTPS redirect setting.'],
            'env' => ['state' => 'supported'],
            'databases' => ['state' => 'supported'],
            'profiles' => ['state' => 'supported'],
            'background_workloads' => ['state' => 'partial', 'limitations' => ['Queue workers and daemons run as separate Coolify applications; process counts are represented as separate app resources. Daemon users map to Coolify custom Docker --user options. Coolify scheduled-task APIs expose no task user field.']],
            'observability' => ['state' => 'supported'],
            'rollback' => ['state' => 'partial', 'limitations' => ['Rollback selects a retained application image/commit. It does not revert database migrations, persistent storage, or external services.']],
            'previews' => ['state' => 'partial', 'limitations' => ['Set mcp_enabled=true and enable Coolify MCP for the instance and team to enumerate active app-only previews through list_application_previews. Without MCP, Shipper recovers PR IDs from tagged Shipper-owned workers/databases and derives domains from coolify.preview_domains (including Compose domains) or a profile domain containing ${GITHUB_PR_NUMBER}; app-only previews without tagged resources cannot be enumerated.', 'Initial Git-based previews must be created by Coolify\'s configured PR webhook; Docker Image previews can be created or updated through deploy when docker_tag is supplied. PR previews require separately configured preview_environment_variables. Coolify automatically deletes previews when PRs close or merge; Shipper cleanup and profile destroy remove tagged workers/databases left behind.', 'Scheduled tasks are rejected for explicit PR previews because Coolify does not document preview-scoped scheduled-task lifecycle.']],
            'server_lifecycle' => ['state' => 'partial', 'limitations' => ['Coolify can provision Hetzner, DigitalOcean, and Vultr servers and register existing SSH hosts. Other cloud providers and generic infrastructure are not provisioned by this plugin.', 'TTL cleanup requires Shipper scheduler execution and provider-reported creation timestamps. Shipper-provisioned cloud servers can be removed with Coolify delete_from_provider when a matching Coolify cloud-provider token is configured; existing resolved cloud servers remain record-only.', 'Server deletion is refused while Coolify reports resources on the server.']],
        ];
    }

    public function validate(object $project, object $profile): array
    {
        $errors = [];
        $profileOptions = $this->profileOptions($profile);
        if (\array_key_exists('mcp_enabled', $this->config) && ! \is_bool($this->config['mcp_enabled'])) {
            $errors[] = 'Coolify mcp_enabled must be a boolean.';
        }
        $forceDeploy = $this->profileOptions($profile)['force_deploy'] ?? $this->config['force_deploy'] ?? false;
        if (! \is_bool($forceDeploy)) {
            $errors[] = 'Coolify force_deploy must be a boolean.';
        }
        $showLogTimestamps = $this->profileOptions($profile)['show_log_timestamps']
            ?? $this->config['show_log_timestamps']
            ?? false;
        if (! \is_bool($showLogTimestamps)) {
            $errors[] = 'Coolify show_log_timestamps must be a boolean.';
        }
        $secretManager = $this->profileOptions($profile)['secret_manager'] ?? $this->config['secret_manager'] ?? null;
        if ($secretManager !== null) {
            if (! \is_array($secretManager)
                || ! \is_string($secretManager['integration_token_uuid'] ?? null)
                || \trim($secretManager['integration_token_uuid']) === '') {
                $errors[] = 'Coolify secret_manager requires a non-empty integration_token_uuid.';
            } elseif (isset($secretManager['settings']) && (! \is_array($secretManager['settings'])
                || ($secretManager['settings'] !== [] && (\array_is_list($secretManager['settings'])
                    || \array_filter(\array_keys($secretManager['settings']), static fn (mixed $key): bool => ! \is_string($key)) !== [])))) {
                $errors[] = 'Coolify secret_manager settings must be an object-like map.';
            }
            if (\is_array($secretManager)) {
                $unsupportedSecretManagerKeys = \array_diff(\array_keys($secretManager), ['integration_token_uuid', 'settings']);
                if ($unsupportedSecretManagerKeys !== []) {
                    $errors[] = 'Coolify secret_manager accepts only integration_token_uuid and settings.';
                }
            }
        }
        $applicationOptions = $this->effectiveApplicationOptions($profile);
        foreach (['is_build_server_enabled', 'is_debug_enabled', 'is_swarm_only_worker_nodes'] as $readOnlyApplicationSetting) {
            if (\array_key_exists($readOnlyApplicationSetting, $this->config)
                || \array_key_exists($readOnlyApplicationSetting, $profileOptions)
                || (\is_array($profileOptions['application'] ?? null)
                    && \array_key_exists($readOnlyApplicationSetting, $profileOptions['application']))) {
                $errors[] = "Coolify application setting '{$readOnlyApplicationSetting}' is response-only in the public API; use the documented writable setting where available.";
            }
        }
        foreach (['swarm_replicas', 'swarm_placement_constraints'] as $swarmOption) {
            if (\array_key_exists($swarmOption, $applicationOptions)) {
                $errors[] = "Coolify's public API does not support writing application setting '{$swarmOption}'.";
            }
        }
        $buildPack = $this->effectiveBuildPack($profile);
        $profileOptions = $this->profileOptions($profile);
        if (\array_key_exists('databases', $profileOptions)) {
            if (! \is_array($profileOptions['databases'])) {
                $errors[] = 'Coolify databases must be a list or map of database declaration objects.';
            } else {
                foreach ($profileOptions['databases'] as $key => $database) {
                    if (! \is_array($database)) {
                        $errors[] = "Coolify database declaration '{$key}' must be a map.";
                        continue;
                    }
                    $name = $database['name'] ?? (\is_string($key) ? $key : '');
                    if (! \is_string($name) || \trim($name) === '') {
                        $errors[] = "Coolify database declaration '{$key}' requires a non-empty name.";
                    }
                    foreach (['type', 'user', 'password'] as $field) {
                        if (isset($database[$field]) && ! \is_string($database[$field])) {
                            $errors[] = "Coolify database declaration '{$key}' field '{$field}' must be a string.";
                        }
                    }
                }
            }
        }
        $workloadLogName = $profileOptions['workload_log_name'] ?? $this->config['workload_log_name'] ?? null;
        if ($workloadLogName !== null && (! \is_string($workloadLogName) || \trim($workloadLogName) === '')) {
            $errors[] = 'Coolify workload_log_name must be a non-empty generated Shipper worker application name.';
        }
        if ($workloadLogName !== null && (isset($profileOptions['database_log_name']) || isset($this->config['database_log_name'])
            || isset($profileOptions['sub_service_name']) || isset($this->config['sub_service_name']))) {
            $errors[] = 'Coolify workload_log_name cannot be combined with database_log_name or sub_service_name.';
        }
        $databaseLogName = $profileOptions['database_log_name'] ?? $this->config['database_log_name'] ?? null;
        if ($databaseLogName !== null) {
            if (! \is_string($databaseLogName) || \trim($databaseLogName) === '') {
                $errors[] = 'Coolify database_log_name must be a non-empty Shipper database declaration name.';
            } elseif (\array_filter($this->databaseDeclarations($project, $profile), static fn (array $database): bool => $database['name'] === $databaseLogName) === []) {
                $errors[] = "Coolify database_log_name '{$databaseLogName}' does not match a declared Shipper database.";
            }
            if (isset($profileOptions['sub_service_name']) || isset($this->config['sub_service_name'])) {
                $errors[] = 'Coolify database_log_name and sub_service_name cannot be used together.';
            }
        }
        $applicationFields = \array_flip($this->applicationOptionFields());
        $applicationOptions = \array_merge(
            \array_intersect_key($this->config, $applicationFields),
            \array_intersect_key($profileOptions, $applicationFields),
        );
        $nestedApplicationOptions = $profileOptions['application'] ?? $this->config['application'] ?? [];
        if (\is_array($nestedApplicationOptions)) {
            $applicationOptions = \array_merge($applicationOptions, $nestedApplicationOptions);
        } else {
            $errors[] = 'Coolify application settings must be a map.';
        }
        $errors = [...$errors, ...$this->validateApplicationOptions($applicationOptions, $this->sourceType($profile), $buildPack)];
        if ($this->stringConfig('url') === '') {
            $errors[] = 'Coolify URL is required (for example https://coolify.example.com).';
        } elseif (\filter_var($this->stringConfig('url'), FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Coolify URL must be a valid absolute URL.';
        }
        if ($this->stringConfig('api_token', $this->stringConfig('token')) === '') {
            $errors[] = 'Coolify API token is required.';
        }
        if ($this->configuredServerUuid($profile) === '') {
            $serverConfig = \method_exists($profile, 'server') ? $profile->server() : null;
            if (! (\is_object($serverConfig) && \method_exists($serverConfig, 'isCreate') && $serverConfig->isCreate())) {
                $errors[] = 'Coolify server_uuid is required to create applications and databases.';
            }
        }
        $serverConfig = \method_exists($profile, 'server') ? $profile->server() : null;
        if (\is_object($serverConfig) && \method_exists($serverConfig, 'isCreate') && $serverConfig->isCreate()
            && \method_exists($serverConfig, 'spec') && \is_array($serverConfig->spec())) {
            $errors = [...$errors, ...$this->validateServerLifecycleSpec($serverConfig->spec())];
        }
        if ($this->sourceType($profile) === 'git' && $this->repositoryUrl($project) === '') {
            $errors[] = 'A repository URL is required for Coolify Git-based deployments.';
        }
        if ($this->sourceType($profile) === 'docker_image' && $this->dockerImage($profile) === '') {
            $errors[] = 'Coolify Docker Image deployments require docker_image or docker_registry_image_name.';
        }
        if (\method_exists($project, 'nginxConfig') && \trim($project->nginxConfig()) !== ''
            && ($this->sourceType($profile) !== 'git' || ! $this->supportsStaticNginxConfiguration($buildPack, $applicationOptions))) {
            $errors[] = 'Coolify custom_nginx_configuration requires a Git-based Static application or a Git-based Nixpacks/Railpack application with coolify.application.is_static enabled.';
        }
        if ($this->sourceType($profile) === 'docker_image' && $this->pullRequestId($profile) !== ''
            && $this->profileOption($profile, 'docker_tag', $this->stringConfig('docker_tag')) === '') {
            $errors[] = 'Coolify Docker Image PR previews require docker_tag, the image tag built for that pull request.';
        }
        if ($this->sourceType($profile) === 'dockerfile' && $this->dockerfileContent($profile) === '') {
            $errors[] = 'Standalone Coolify Dockerfile deployments require dockerfile content.';
        }
        if ($this->sourceType($profile) === 'dockerfile' && $this->pullRequestId($profile) !== '') {
            $errors[] = 'Standalone Coolify Dockerfile applications cannot create pull request previews; use a Git application or a Docker Image preview with docker_tag.';
        }
        if (! \in_array($this->sourceType($profile), ['git', 'docker_image', 'dockerfile'], true)) {
            $errors[] = 'Coolify source_type must be git, docker_image, or dockerfile.';
        }
        $hasEnabledWorkloads = \array_filter(
            [...(\method_exists($project, 'queues') ? $project->queues() : []), ...(\method_exists($project, 'daemons') ? $project->daemons() : [])],
            static fn (mixed $workload): bool => ! \method_exists($workload, 'enabled') || $workload->enabled(),
        ) !== [];
        if (\in_array($this->sourceType($profile), ['docker_image', 'dockerfile'], true)) {
            foreach (['install_command', 'build_command', 'dockerfile_location', 'docker_compose_location'] as $option) {
                if ($this->profileOption($profile, $option, $this->stringConfig($option)) !== '') {
                    $errors[] = "Coolify {$this->sourceType($profile)} source does not accept the '{$option}' build setting.";
                }
            }
        }
        $rawPullRequestId = $this->pullRequestIdValue($profile);
        $pullRequestId = $this->pullRequestId($profile);
        if ($rawPullRequestId !== null && ($pullRequestId === '' || ! \ctype_digit($pullRequestId) || (int) $pullRequestId < 1)) {
            $errors[] = 'Coolify pull_request_id must be a positive integer.';
        }
        $additionalDestinations = $profileOptions['additional_destination_uuids'] ?? $this->config['additional_destination_uuids'] ?? [];
        if (! \is_array($additionalDestinations) || ! \array_is_list($additionalDestinations)
            || \array_filter($additionalDestinations, static fn (mixed $uuid): bool => ! \is_string($uuid) || \trim($uuid) === '') !== []) {
            $errors[] = 'Coolify additional_destination_uuids must be a list of non-empty destination UUIDs.';
        } elseif ($additionalDestinations !== []) {
            if (\count(\array_unique($additionalDestinations)) !== \count($additionalDestinations)) {
                $errors[] = 'Coolify additional_destination_uuids must not contain duplicates.';
            }
            if ($pullRequestId !== '') {
                $errors[] = 'Coolify pull request previews are not deployed to additional destinations.';
            }
            $compose = $profileOptions['docker_compose'] ?? $this->config['docker_compose'] ?? null;
            if ($buildPack === 'dockercompose' || (\is_string($compose) && \trim($compose) !== '')) {
                $errors[] = 'Coolify additional destinations do not support Docker Compose applications or stacks.';
            }
            $storages = $profileOptions['storages'] ?? $this->config['storages'] ?? [];
            if ($storages !== []) {
                $errors[] = 'Coolify additional destinations do not support applications with persistent storage.';
            }
            if ($this->dockerImage($profile) === ''
                && (! \is_string($applicationOptions['docker_registry_image_name'] ?? null)
                    || \trim($applicationOptions['docker_registry_image_name']) === '')) {
                $errors[] = 'Coolify additional destinations require a Docker registry image.';
            }
        }
        $previewEnvironment = $this->profileOptions($profile)['preview_environment_variables'] ?? $this->config['preview_environment_variables'] ?? [];
        if (! \is_array($previewEnvironment)) {
            $errors[] = 'Coolify preview_environment_variables must be a map of variable names to string values.';
        } else {
            foreach ($previewEnvironment as $key => $value) {
                if (! \is_string($key) || ! \is_string($value)) {
                    $errors[] = 'Coolify preview_environment_variables must be a map of variable names to string values.';
                    break;
                }
            }
        }
        $environmentVariableOptions = $this->profileOptions($profile)['environment_variable_options'] ?? $this->config['environment_variable_options'] ?? [];
        if (! \is_array($environmentVariableOptions)) {
            $errors[] = 'Coolify environment_variable_options must be a map keyed by environment variable name.';
        } else {
            foreach ($environmentVariableOptions as $variableName => $options) {
                if (! \is_string($variableName) || \trim($variableName) === '' || ! \is_array($options)) {
                    $errors[] = 'Coolify environment_variable_options entries must map variable names to option maps.';
                    continue;
                }
                foreach ($options as $option => $value) {
                    if (! \in_array($option, ['is_literal', 'is_multiline', 'is_buildtime', 'is_runtime'], true)) {
                        $errors[] = "Unsupported Coolify environment-variable option '{$option}'.";
                    } elseif (! \is_bool($value)) {
                        $errors[] = "Coolify environment-variable option '{$option}' for '{$variableName}' must be boolean.";
                    }
                }
            }
        }
        $previewDomains = $this->profileOptions($profile)['preview_domains'] ?? $this->config['preview_domains'] ?? null;
        if ($previewDomains !== null) {
            if (! \is_array($previewDomains)) {
                $errors[] = 'Coolify preview_domains must be a map of preview domain API fields.';
            } else {
                foreach ($previewDomains as $key => $value) {
                    if (! \in_array($key, ['domains', 'docker_compose_domains', 'force_domain_override'], true)) {
                        $errors[] = "Unsupported Coolify preview_domains setting '{$key}'.";
                    } elseif ($key === 'domains' && $value !== null && ! \is_string($value)) {
                        $errors[] = 'Coolify preview_domains.domains must be a string or null.';
                    } elseif ($key === 'docker_compose_domains' && $value !== null && ! \is_array($value)) {
                        $errors[] = 'Coolify preview_domains.docker_compose_domains must be an array or null.';
                    } elseif ($key === 'force_domain_override' && ! \is_bool($value)) {
                        $errors[] = 'Coolify preview_domains.force_domain_override must be a boolean.';
                    }
                }
                if (! \array_key_exists('domains', $previewDomains) && ! \array_key_exists('docker_compose_domains', $previewDomains)) {
                    $errors[] = 'Coolify preview_domains must include domains or docker_compose_domains.';
                }
                if ($pullRequestId === '') {
                    $errors[] = 'Coolify preview_domains requires a positive pull_request_id.';
                }
            }
        }
        if ($pullRequestId !== '') {
            if (isset($profileOptions['sub_service_name']) || isset($this->config['sub_service_name'])) {
                $errors[] = 'Coolify PR preview logs cannot target sub_service_name because the separately managed Compose service remains shared with production.';
            }
            foreach (\method_exists($project, 'cron') ? $project->cron() : [] as $taskName => $task) {
                if (! \method_exists($task, 'enabled') || $task->enabled()) {
                    $errors[] = "Coolify PR preview cannot safely configure scheduled task '{$taskName}' because Coolify does not document preview-scoped scheduled tasks.";
                }
            }
        }
        if (\in_array($this->sourceType($profile), ['docker_image', 'dockerfile'], true) && $hasEnabledWorkloads
            && \is_string($applicationOptions['custom_docker_run_options'] ?? null)
            && \preg_match('/(?:^|\s)--entrypoint(?:=|\s)/', $applicationOptions['custom_docker_run_options']) === 1) {
            $errors[] = 'Coolify custom_docker_run_options cannot set --entrypoint when Shipper manages Docker Image or Dockerfile queue workers or daemons.';
        }
        if (\method_exists($project, 'networkRules') && $project->networkRules() !== []) {
            $errors[] = 'Coolify does not expose Shipper network_rules through its deployment API; configure server firewall policy separately.';
        }
        $redirectDirections = [];
        $hasPathRedirects = false;
        foreach ($this->redirects($project) as $name => $redirect) {
            if (! \is_object($redirect) || (\method_exists($redirect, 'enabled') && ! $redirect->enabled())) {
                continue;
            }
            $direction = $this->redirectDirection($redirect);
            if ($direction === null) {
                if (! $this->isPathRedirect($redirect)) {
                    $errors[] = "Coolify redirect '{$name}' must use either a supported www/non-www hostname pair or absolute URL paths.";
                    continue;
                }
                if ($this->profileOption($profile, 'build_pack', $this->stringConfig('build_pack', 'nixpacks')) === 'dockercompose') {
                    $errors[] = "Coolify path redirect '{$name}' cannot be attached to a Docker Compose application's service labels; configure the redirect in its Compose definition.";
                    continue;
                }
                if (! $this->supportsRedirectType($redirect)) {
                    $errors[] = "Coolify path redirect '{$name}' supports only 301/permanent or 302/temporary redirect types.";
                    continue;
                }
                $hasPathRedirects = true;
                continue;
            }
            if (\method_exists($redirect, 'type') && ! \in_array((string) $redirect->type(), ['301', 'permanent'], true)) {
                $errors[] = "Coolify redirect '{$name}' requests a status code that Coolify's www/non-www direction setting cannot preserve.";
            }
            $redirectDirections[$name] = $direction;
        }
        if (\count(\array_unique($redirectDirections)) > 1) {
            $errors[] = 'Coolify supports only one www/non-www redirect direction per application; configured redirect rules conflict.';
        }
        if ($hasPathRedirects && $this->profileOption($profile, 'custom_service_compose', $this->stringConfig('custom_service_compose')) !== '') {
            $errors[] = 'Coolify path redirects cannot be attached to Shipper custom Service components; configure them in the Compose definition.';
        }
        if (\method_exists($project, 'ssl')) {
            $ssl = $project->ssl();
            if ((! \method_exists($ssl, 'enabled') || $ssl->enabled())
                && \method_exists($ssl, 'type') && ! \in_array(\strtolower($ssl->type()), ['letsencrypt', 'auto', 'wildcard', 'custom'], true)) {
                $errors[] = 'Coolify SSL type must be letsencrypt, auto, wildcard, or custom. Wildcard and custom certificates must already be configured on the Coolify proxy.';
            }
        }
        if ($hasEnabledWorkloads && \in_array($buildPack, ['static', 'dockercompose'], true)) {
            $errors[] = "Coolify build_pack '{$buildPack}' cannot run Shipper queue workers or daemons as separate applications; use a Nixpacks, Railpack, or Dockerfile application build with a runnable start command.";
        }
        foreach (\method_exists($project, 'queues') ? $project->queues() : [] as $queueName => $queue) {
            if (\method_exists($queue, 'enabled') && ! $queue->enabled()) {
                continue;
            }
            if (\method_exists($queue, 'processes') && $queue->processes() < 1) {
                $errors[] = "Coolify queue '{$queueName}' process count must be at least 1.";
            }
        }
        foreach (\method_exists($project, 'daemons') ? $project->daemons() : [] as $daemonName => $daemon) {
            if (\method_exists($daemon, 'enabled') && ! $daemon->enabled()) {
                continue;
            }
            if (\method_exists($daemon, 'processes') && $daemon->processes() < 1) {
                $errors[] = "Coolify daemon '{$daemonName}' process count must be at least 1.";
            }
        }
        foreach (\method_exists($project, 'cron') ? $project->cron() : [] as $taskName => $task) {
            if (\method_exists($task, 'enabled') && ! $task->enabled()) {
                continue;
            }
            if (\method_exists($task, 'user') && $task->user() !== '') {
                $errors[] = "Coolify scheduled task '{$taskName}' cannot select Shipper's task user; set user to an empty string to use the container's runtime identity.";
            }
            if (\method_exists($task, 'command') && \trim($task->command()) === '') {
                $errors[] = "Coolify scheduled task '{$taskName}' requires a non-empty command.";
            }
            if (\method_exists($task, 'frequency') && ! $this->isSupportedCronFrequency($task->frequency())) {
                $errors[] = "Coolify scheduled task '{$taskName}' frequency must be a five-field cron expression or a Coolify-supported named schedule.";
            }
        }
        if (\method_exists($project, 'cron') && \array_filter($project->cron(), static fn (mixed $task): bool => ! \method_exists($task, 'enabled') || $task->enabled()) !== []) {
            $timeout = $profileOptions['cron_timeout'] ?? $this->config['cron_timeout'] ?? 300;
            if (! \is_int($timeout) || $timeout < 60 || $timeout > 36000) {
                $errors[] = 'Coolify cron_timeout must be between 60 and 36000 seconds.';
            }
        }

        if ($this->sourceType($profile) === 'git' && ! \in_array($buildPack, ['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose'], true)) {
            $errors[] = "Unsupported Coolify build_pack '{$buildPack}'.";
        }
        $configuredPublishDirectory = $this->profileOption($profile, 'publish_directory');
        if ($configuredPublishDirectory !== ''
            && ($this->sourceType($profile) !== 'git' || $buildPack !== 'static')) {
            $errors[] = 'Coolify publish_directory is supported only for Git applications using the static build pack.';
        }
        if (\method_exists($project, 'phpVersion') && \trim($project->phpVersion()) !== '') {
            if ($this->sourceType($profile) === 'git' && $buildPack === 'railpack') {
                $errors[] = 'Coolify Railpack selects PHP from composer.json (PHP 8.2+; default 8.4) and does not document an override variable. Remove Shipper php_version or pin the PHP requirement in composer.json.';
            } elseif ($this->sourceType($profile) !== 'git' || $buildPack !== 'nixpacks') {
                $errors[] = "Shipper php_version is only configurable by Coolify for Git applications using Nixpacks; set the runtime version in the Dockerfile/image or remove php_version for build pack '{$buildPack}'.";
            }
        }
        $railpackExtensions = $this->profileOption($profile, 'railpack_php_extensions', $this->stringConfig('railpack_php_extensions'));
        if ($railpackExtensions !== '') {
            if ($this->sourceType($profile) !== 'git' || $buildPack !== 'railpack') {
                $errors[] = 'Coolify railpack_php_extensions is supported only for Git applications using the Railpack build pack.';
            } elseif (\array_filter(\explode(',', $railpackExtensions), static fn (string $extension): bool => \trim($extension) === '') !== []) {
                $errors[] = 'Coolify railpack_php_extensions must be a comma-separated list of non-empty extension names.';
            }
        }

        foreach ($this->databaseDeclarations($project, $profile) as $database) {
            $type = \strtolower($database['type']);
            if (! \in_array($type, ['mysql', 'mariadb', 'postgres', 'postgresql', 'redis', 'mongodb', 'sqlite', 'clickhouse', 'dragonfly', 'keydb'], true)) {
                $errors[] = "Unsupported Coolify database type '{$type}'.";
            }
            if ($database['name'] === '') {
                $errors[] = 'Coolify database declarations require a non-empty name.';
            }
            $databaseOptions = $this->profileOptions($profile)['database_options'] ?? $this->config['database_options'] ?? [];
            if (! \is_array($databaseOptions)) {
                $errors[] = 'Coolify database_options must be a map keyed by Shipper database declaration name.';
                break;
            }
            $options = $databaseOptions[$database['name']] ?? [];
            if (! \is_array($options)) {
                $errors[] = "Coolify database_options for '{$database['name']}' must be a map.";
                continue;
            }
            $databaseStorages = $options['storages'] ?? [];
            if (\array_key_exists('storages', $options)) {
                $errors = [...$errors, ...$this->validateDatabaseStorages($databaseStorages, $database['name'])];
            }
            $databaseStorageBackups = $options['storage_backups'] ?? [];
            if (\array_key_exists('storage_backups', $options)) {
                $errors = [...$errors, ...$this->validateStorageBackupSchedules($databaseStorageBackups, "database '{$database['name']}'", $databaseStorages)];
                $databaseMountPaths = \array_column(\is_array($databaseStorages) ? $databaseStorages : [], 'mount_path');
                if (\is_array($databaseStorageBackups)) {
                    foreach (\array_keys($databaseStorageBackups) as $mountPath) {
                        if (! \in_array($mountPath, $databaseMountPaths, true)) {
                            $errors[] = "Coolify database '{$database['name']}' storage_backups references undeclared storage mount '{$mountPath}'.";
                        }
                    }
                }
            }
            $options = \array_diff_key($options, ['storages' => true, 'storage_backups' => true]);
            $allowed = $this->databaseOptionFields($type);
            foreach ($options as $key => $value) {
                if (! \is_string($key) || ! \in_array($key, $allowed, true)) {
                    $errors[] = "Coolify database option '{$key}' is not documented for database type '{$type}'.";
                } elseif (! $this->validDatabaseOptionType($key, $value)) {
                    $expected = match ($key) {
                        'is_public', 'health_check_enabled', 'instant_deploy', 'migrate_volumes' => 'boolean',
                        'public_port', 'public_port_timeout', 'limits_memory_swappiness', 'limits_cpu_shares',
                        'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period' => 'integer',
                        'tags' => 'a list of non-empty strings',
                        default => 'string',
                    };
                    $errors[] = "Coolify database option '{$key}' must be a {$expected}.";
                } elseif ($key === 'destination_uuid' && \trim($value) === '') {
                    $errors[] = "Coolify database option 'destination_uuid' must not be empty.";
                }
            }
            foreach ([
                'public_port' => [1, 65535],
                'public_port_timeout' => [1, PHP_INT_MAX],
                'health_check_interval' => [1, PHP_INT_MAX],
                'health_check_timeout' => [1, PHP_INT_MAX],
                'health_check_retries' => [1, PHP_INT_MAX],
                'health_check_start_period' => [0, PHP_INT_MAX],
            ] as $field => [$minimum, $maximum]) {
                if (isset($options[$field]) && \is_int($options[$field])
                    && ($options[$field] < $minimum || $options[$field] > $maximum)) {
                    $errors[] = "Coolify database option '{$field}' must be between {$minimum} and {$maximum}.";
                }
            }
            $databaseEnvironments = $this->profileOptions($profile)['database_environment_variables'] ?? $this->config['database_environment_variables'] ?? [];
            if (! \is_array($databaseEnvironments)) {
                $errors[] = 'Coolify database_environment_variables must be a map keyed by Shipper database declaration name.';
            } else {
                $databaseEnvironment = $databaseEnvironments[$database['name']] ?? [];
                if (! \is_array($databaseEnvironment)) {
                    $errors[] = "Coolify database_environment_variables for '{$database['name']}' must be a map.";
                } else {
                    foreach ($databaseEnvironment as $key => $value) {
                        if (! \is_string($key) || ! \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                            $errors[] = "Coolify database environment variable names for '{$database['name']}' must be valid environment identifiers.";
                        }
                        if (! \is_string($value)) {
                            $errors[] = "Coolify database environment variable values for '{$database['name']}' must be strings.";
                        }
                    }
                }
            }
            $backupOptions = $this->profileOptions($profile)['database_backups'] ?? $this->config['database_backups'] ?? [];
            if (! \is_array($backupOptions)) {
                $errors[] = 'Coolify database_backups must be a map keyed by Shipper database declaration name.';
                continue;
            }
            $backup = $backupOptions[$database['name']] ?? null;
            if ($backup !== null) {
                if (! \is_array($backup) || ! \is_string($backup['uuid'] ?? null) || \trim($backup['uuid']) === '') {
                    $errors[] = "Coolify database_backups for '{$database['name']}' must include a non-empty Coolify backup UUID.";
                    continue;
                }
                if (! \in_array($type, ['postgres', 'postgresql', 'mysql', 'mariadb', 'mongodb', 'clickhouse'], true)) {
                    $errors[] = "Coolify database type '{$type}' does not support scheduled database backups.";
                }
                $backupFields = [
                    'frequency', 'enabled', 'save_s3', 's3_storage_uuid', 'databases_to_backup', 'dump_all',
                    'database_backup_retention_amount_locally', 'database_backup_retention_days_locally',
                    'database_backup_retention_max_storage_locally', 'database_backup_retention_amount_s3',
                    'database_backup_retention_days_s3', 'database_backup_retention_max_storage_s3',
                    'timeout', 'missing_backup_notification_days',
                ];
                foreach ($backup as $key => $value) {
                    if ($key === 'uuid') {
                        continue;
                    }
                    if (! \is_string($key) || ! \in_array($key, $backupFields, true)) {
                        $errors[] = "Unsupported Coolify database backup setting '{$key}'.";
                        continue;
                    }
                    if ($key === 'frequency' || $key === 's3_storage_uuid' || $key === 'databases_to_backup') {
                        if (! \is_string($value) || \trim($value) === '') {
                            $errors[] = "Coolify database backup setting '{$key}' must be a non-empty string.";
                        }
                    } elseif (\in_array($key, ['enabled', 'save_s3', 'dump_all'], true)) {
                        if (! \is_bool($value)) {
                            $errors[] = "Coolify database backup setting '{$key}' must be boolean.";
                        }
                    } elseif (\in_array($key, ['database_backup_retention_max_storage_locally', 'database_backup_retention_max_storage_s3'], true)) {
                        if (! (\is_int($value) || \is_float($value)) || $value < 0) {
                            $errors[] = "Coolify database backup setting '{$key}' must be a non-negative number.";
                        }
                    } elseif (! \is_int($value) || $value < 0) {
                        $errors[] = "Coolify database backup setting '{$key}' must be a non-negative integer.";
                    }
                }
                if (($backup['save_s3'] ?? false) === true && (! \is_string($backup['s3_storage_uuid'] ?? null) || \trim($backup['s3_storage_uuid']) === '')) {
                    $errors[] = "Coolify database backup for '{$database['name']}' requires s3_storage_uuid when save_s3 is enabled.";
                }
                if (isset($backup['timeout']) && \is_int($backup['timeout']) && ($backup['timeout'] < 60 || $backup['timeout'] > 36000)) {
                    $errors[] = "Coolify database backup timeout for '{$database['name']}' must be between 60 and 36000 seconds.";
                }
                if (isset($backup['missing_backup_notification_days']) && \is_int($backup['missing_backup_notification_days']) && $backup['missing_backup_notification_days'] > 365) {
                    $errors[] = "Coolify database backup notification days for '{$database['name']}' must not exceed 365.";
                }
            }
        }
        $declaredDatabaseNames = [];
        foreach ($this->databaseDeclarations($project, $profile) as $database) {
            $declaredDatabaseNames[$database['name']] = true;
        }
        foreach ([
            'database_options' => $this->profileOptions($profile)['database_options'] ?? $this->config['database_options'] ?? [],
            'database_environment_variables' => $this->profileOptions($profile)['database_environment_variables'] ?? $this->config['database_environment_variables'] ?? [],
            'database_backups' => $this->profileOptions($profile)['database_backups'] ?? $this->config['database_backups'] ?? [],
        ] as $optionName => $optionMap) {
            if (! \is_array($optionMap)) {
                continue;
            }
            foreach ($optionMap as $databaseName => $_) {
                if (! \is_string($databaseName) || ! isset($declaredDatabaseNames[$databaseName])) {
                    $errors[] = "Coolify {$optionName} references undeclared Shipper database '{$databaseName}'.";
                }
            }
        }
        $configuredStorages = $this->profileOptions($profile)['storages'] ?? $this->config['storages'] ?? [];
        if (! \is_array($configuredStorages)) {
            $errors[] = 'Coolify storages must be an array of storage declarations.';
        } else {
            $seenStorageMountPaths = [];
            foreach ($configuredStorages as $index => $storage) {
                if (! \is_array($storage) || ! \in_array($storage['type'] ?? null, ['persistent', 'file'], true)
                    || ! \is_string($storage['mount_path'] ?? null) || \trim($storage['mount_path']) === '') {
                    $errors[] = "Coolify storage '{$index}' needs type=persistent|file and a non-empty mount_path.";
                    continue;
                }
                $mountPath = \trim($storage['mount_path']);
                if (isset($seenStorageMountPaths[$mountPath])) {
                    $errors[] = "Coolify storage '{$index}' duplicates mount_path '{$mountPath}'.";
                }
                $seenStorageMountPaths[$mountPath] = true;
                if (($storage['type'] ?? null) === 'persistent'
                    && (! \is_string($storage['name'] ?? null) || \trim($storage['name']) === '')) {
                    $errors[] = "Coolify persistent storage '{$index}' requires a volume name.";
                }
                if (\array_key_exists('host_path', $storage)
                    && (($storage['type'] ?? null) !== 'persistent'
                        || ! \is_string($storage['host_path']) || \trim($storage['host_path']) === '')) {
                    $errors[] = "Coolify storage '{$index}' host_path must be a non-empty string and is supported only for persistent storage.";
                }
                if (\array_key_exists('is_directory', $storage) && ! \is_bool($storage['is_directory'])) {
                    $errors[] = "Coolify file storage '{$index}' is_directory must be boolean.";
                }
                if (($storage['type'] ?? null) === 'file' && ($storage['is_directory'] ?? false)
                    && (! \is_string($storage['fs_path'] ?? null) || \trim($storage['fs_path']) === '')) {
                    $errors[] = "Coolify directory file storage '{$index}' requires fs_path.";
                }
                if ($this->previewDeploymentsEnabled($profile)
                    && ((($storage['type'] ?? null) === 'file' && ($storage['is_directory'] ?? false))
                        || (($storage['type'] ?? null) === 'persistent' && isset($storage['host_path'])))) {
                    $errors[] = "Coolify preview storage '{$index}' cannot use a host-bound mount; use an isolated persistent volume or an inline file mount to avoid sharing production data.";
                }
            }
        }
        $errors = [...$errors, ...$this->validateStorageBackupSchedules(
            $this->profileOptions($profile)['storage_backups'] ?? $this->config['storage_backups'] ?? [],
            'application',
            $this->profileOptions($profile)['storages'] ?? $this->config['storages'] ?? [],
        )];
        $serviceOptions = $this->profileOptions($profile)['service'] ?? $this->config['service'] ?? [];
        if (! \is_array($serviceOptions)) {
            $errors[] = 'Coolify service settings must be a map.';
        } else {
            foreach ($serviceOptions as $key => $value) {
                if (! \in_array($key, ['type', 'name', 'description', 'tags', 'instant_deploy', 'connect_to_docker_network', 'urls', 'force_domain_override', 'is_container_label_escape_enabled', 'storage_backups', 'storages', 'applications', 'databases', 'destination_uuid', 'migrate_volumes'], true)) {
                    $errors[] = "Unsupported Coolify service setting '{$key}'.";
                } elseif ($key === 'type' && (! \is_string($value) || \preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value) !== 1)) {
                    $errors[] = 'Coolify service type must be a lowercase catalog type identifier.';
                } elseif (\in_array($key, ['name', 'description'], true) && ! \is_string($value)) {
                    $errors[] = "Coolify service setting '{$key}' must be a string.";
                } elseif ($key === 'name' && (\trim($value) === '' || \mb_strlen($value) > 255)) {
                    $errors[] = 'Coolify service name must be non-empty and at most 255 characters.';
                } elseif ($key === 'destination_uuid' && (! \is_string($value) || \trim($value) === '')) {
                    $errors[] = 'Coolify service destination_uuid must be a non-empty UUID.';
                } elseif ($key === 'migrate_volumes' && ! \is_bool($value)) {
                    $errors[] = 'Coolify service migrate_volumes must be a boolean.';
                } elseif ($key === 'tags'
                    && (! \is_array($value) || ! \array_is_list($value)
                        || \array_filter($value, static fn (mixed $tag): bool => ! \is_string($tag) || \trim($tag) === '') !== [])) {
                    $errors[] = 'Coolify service tags must be a list of non-empty strings.';
                } elseif (\in_array($key, ['urls', 'storage_backups', 'storages', 'applications', 'databases'], true) && ! \is_array($value)) {
                    $errors[] = "Coolify service setting '{$key}' has an invalid value type.";
                } elseif (\in_array($key, ['instant_deploy', 'connect_to_docker_network', 'force_domain_override', 'is_container_label_escape_enabled', 'migrate_volumes'], true) && ! \is_bool($value)) {
                    $errors[] = "Coolify service setting '{$key}' has an invalid value type.";
                } elseif ($key === 'urls') {
                    foreach ($value as $urlEntry) {
                        if (! \is_array($urlEntry) || ! \is_string($urlEntry['name'] ?? null)
                            || \trim($urlEntry['name']) === '' || ! \is_string($urlEntry['url'] ?? null)
                            || \trim($urlEntry['url']) === '') {
                            $errors[] = 'Coolify service urls must contain entries with a Compose service name and URL list.';
                            break;
                        }
                    }
                }
            }
            foreach (['applications', 'databases'] as $componentKind) {
                if (isset($serviceOptions[$componentKind])) {
                    $errors = [...$errors, ...$this->serviceComponentOptionErrors($serviceOptions[$componentKind], $componentKind)];
                }
            }
            $errors = [...$errors, ...$this->validateStorageBackupSchedules($serviceOptions['storage_backups'] ?? [], 'service', $serviceOptions['storages'] ?? [])];
            $errors = [...$errors, ...$this->validateServiceStorages($serviceOptions['storages'] ?? [])];
            $compose = $this->profileOption($profile, 'docker_compose', $this->stringConfig('docker_compose'));
            if (isset($serviceOptions['type']) && \trim($compose) !== '') {
                $errors[] = 'Coolify service type cannot be combined with a custom docker_compose definition.';
            }
        }
        $secretVariables = $this->profileOptions($profile)['secret_environment_variables'] ?? $this->config['secret_environment_variables'] ?? [];
        if (! \is_array($secretVariables) || \array_filter($secretVariables, static fn (mixed $key): bool => ! \is_string($key)) !== []) {
            $errors[] = 'Coolify secret_environment_variables must be a list of variable names.';
        }
        $taskContainers = $this->profileOptions($profile)['scheduled_task_containers'] ?? $this->config['scheduled_task_containers'] ?? [];
        if (! \is_array($taskContainers)) {
            $errors[] = 'Coolify scheduled_task_containers must map task names to service container names.';
        } else {
            $cronTasks = \method_exists($project, 'cron') ? $project->cron() : [];
            foreach ($taskContainers as $taskName => $container) {
                if (! \is_string($taskName) || ! \is_string($container) || \trim($container) === '') {
                    $errors[] = 'Coolify scheduled_task_containers must map string task names to non-empty container names.';
                    continue;
                }
                if (! \array_key_exists($taskName, $cronTasks)
                    || (\method_exists($cronTasks[$taskName], 'enabled') && ! $cronTasks[$taskName]->enabled())) {
                    $errors[] = "Coolify scheduled_task_containers references unknown or disabled cron task '{$taskName}'.";
                }
            }
            if ($taskContainers !== []) {
                $serviceOptions = $this->profileOptions($profile)['service'] ?? $this->config['service'] ?? [];
                $compose = $this->profileOption($profile, 'docker_compose', $this->stringConfig('docker_compose'));
                if (\trim($compose) === '' && (! \is_array($serviceOptions) || ! \is_string($serviceOptions['type'] ?? null) || \trim($serviceOptions['type']) === '')) {
                    $errors[] = 'Coolify scheduled_task_containers requires a configured Coolify Compose or catalog service.';
                }
            }
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        $name = $this->resourceName($project, $profile);
        $actions = [
            'Resolve or create the Coolify project and profile environment',
            'Create or update the owned application '.$name,
            'Synchronize configured environment variables and domains',
        ];
        $serverConfig = \method_exists($profile, 'server') ? $profile->server() : null;
        if (\is_object($serverConfig) && \method_exists($serverConfig, 'isCreate') && $serverConfig->isCreate()) {
            $actions[] = 'Resolve or provision the configured Coolify server before deployment';
        }
        $deployScript = $this->profileValue($profile, 'deploy_script', '');
        if ($deployScript === '' && \method_exists($project, 'deployScript')) {
            $deployScript = $project->deployScript();
        }
        $applicationOptions = $this->effectiveApplicationOptions($profile);
        $hasExplicitPostDeploymentCommand = \is_string($applicationOptions['post_deployment_command'] ?? null)
            && $applicationOptions['post_deployment_command'] !== '';
        if ($deployScript !== '' && ! $hasExplicitPostDeploymentCommand) {
            $actions[] = 'Configure the Shipper deployment script as a Coolify post-deployment command';
        }
        if (\method_exists($project, 'ssl') && \method_exists($project->ssl(), 'enabled')) {
            $actions[] = $project->ssl()->enabled()
                ? 'Enable HTTPS domains with Coolify-managed proxy certificates'
                : 'Convert configured HTTPS domains to HTTP (Shipper SSL is disabled)';
        }
        if (\method_exists($project, 'phpVersion') && \trim($project->phpVersion()) !== '') {
            $buildPack = $this->effectiveBuildPack($profile);
            if ($this->sourceType($profile) === 'git' && $buildPack === 'nixpacks') {
                $actions[] = 'Set Nixpacks PHP version to '.$project->phpVersion();
            }
        }
        if ($this->sourceType($profile) === 'git'
            && $this->supportsStaticNginxConfiguration($this->effectiveBuildPack($profile), $applicationOptions)
            && ! \array_key_exists('custom_nginx_configuration', $applicationOptions)
            && \method_exists($project, 'nginxConfig') && \trim($project->nginxConfig()) !== '') {
            $actions[] = 'Apply Shipper Nginx configuration as Coolify custom Nginx configuration';
        }
        foreach ($this->databaseDeclarations($project, $profile) as $database) {
            $actions[] = 'Create or reuse Coolify '.$database['type'].' resource: '.$database['name'];
        }
        foreach (\method_exists($project, 'queues') ? $project->queues() : [] as $queueName => $queue) {
            if (! \method_exists($queue, 'enabled') || $queue->enabled()) {
                $actions[] = 'Create or update queue worker: '.$queueName;
            }
        }
        foreach (\method_exists($project, 'daemons') ? $project->daemons() : [] as $daemonName => $daemon) {
            if (! \method_exists($daemon, 'enabled') || $daemon->enabled()) {
                $actions[] = 'Create or update daemon application: '.$daemonName;
            }
        }
        foreach (\method_exists($project, 'cron') ? $project->cron() : [] as $cronName => $cron) {
            if (! \method_exists($cron, 'enabled') || $cron->enabled()) {
                $taskContainers = $this->profileOptions($profile)['scheduled_task_containers'] ?? $this->config['scheduled_task_containers'] ?? [];
                $target = \is_array($taskContainers) && \array_key_exists($cronName, $taskContainers) ? 'Compose service' : 'application';
                $actions[] = 'Create or update Coolify scheduled task on '.$target.': '.$cronName;
            }
        }
        $profileOptions = $this->profileOptions($profile);
        $compose = $profileOptions['docker_compose'] ?? $this->config['docker_compose'] ?? null;
        if (\is_string($compose) && \trim($compose) !== '') {
            if ($this->pullRequestId($profile) === '') {
                $actions[] = 'Create or update the owned Coolify Compose stack';
            } else {
                $actions[] = 'Leave the shared Coolify Compose service unchanged for this pull request preview';
            }
        }
        $storages = $profileOptions['storages'] ?? $this->config['storages'] ?? [];
        if (\is_array($storages)) {
            foreach ($storages as $storage) {
                if (\is_array($storage)) {
                    $actions[] = 'Create or update Coolify '.($storage['type'] ?? 'storage').' mount at '.($storage['mount_path'] ?? '(unspecified path)');
                }
            }
        }
        foreach ($this->desiredDomains($project, $profile) as $domain) {
            $actions[] = 'Route domain through Coolify proxy: '.$domain;
        }
        $actions[] = 'Queue Coolify deployments';
        $pullRequestId = $this->pullRequestId($profile);
        if ($pullRequestId !== '') {
            $actions[] = 'Deploy Coolify pull request preview #'.$pullRequestId;
            $previewDomains = $this->profileOptions($profile)['preview_domains'] ?? $this->config['preview_domains'] ?? null;
            if (\is_array($previewDomains)) {
                $actions[] = 'Update Coolify preview domains for pull request #'.$pullRequestId;
            }
        }

        return [
            'provider' => $this->getName(),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'application' => $name,
            'repository' => $this->repositoryUrl($project),
            'branch' => $this->profileName($profile) !== '' && \method_exists($profile, 'branch') ? $profile->branch() : '',
            'domain' => $this->desiredDomains($project, $profile),
            'actions' => $actions,
            'note' => 'Coolify resources are tagged for safe adoption and cleanup; existing untagged resources are not deleted.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $this->lastError = '';
        $errors = $this->validate($project, $profile);
        if ($errors !== []) {
            $this->lastError = \implode("\n", $errors);

            return false;
        }

        try {
            $client = $this->getClient();
            [$projectUuid, $environmentUuid, $environmentName] = $this->resolveEnvironment($client, $project, $profile);
            $serverUuid = $this->configuredServerUuid($profile);
            $app = $this->ensureApplication($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid, $this->resourceName($project, $profile), null);
            $applicationUuid = $this->resourceUuid($app);
            $applicationDestination = $this->effectiveApplicationOptions($profile)['destination_uuid'] ?? '';
            $this->reconcileResourceDestination($client, 'applications', $applicationUuid, $app, $serverUuid,
                \is_string($applicationDestination) ? $applicationDestination : '');
            if ($environmentUuid !== '' && ($app['environment_uuid'] ?? null) !== $environmentUuid) {
                $this->moveApplication($applicationUuid, $environmentUuid);
            }
            $secretManager = $this->profileOptions($profile)['secret_manager'] ?? $this->config['secret_manager'] ?? null;
            if (\is_array($secretManager)) {
                $this->configureApplicationSecretManager(
                    $applicationUuid,
                    $secretManager['integration_token_uuid'],
                    $secretManager['settings'] ?? [],
                );
            }
            $this->reconcileAdditionalDestinations($applicationUuid, $this->profileOptions($profile)['additional_destination_uuids'] ?? $this->config['additional_destination_uuids'] ?? []);
            $databaseEnvironment = $this->applyDatabases($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid);
            $this->applyEnvironment($client, $applicationUuid, $project, $profile, $databaseEnvironment);
            $this->applyStorages($client, $applicationUuid, $profile);
            if ($this->pullRequestId($profile) === '') {
                $this->applyScheduledTasks($client, $applicationUuid, $project, $profile, 'applications', 'application');
            }
            $desiredWorkerNames = $this->applyWorkers($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid, $databaseEnvironment);
            $serviceUuid = null;
            if ($this->pullRequestId($profile) === '') {
                $serviceUuid = $this->applyCustomService($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid, $databaseEnvironment);
                if ($serviceUuid !== null) {
                    $this->applyScheduledTasks($client, $serviceUuid, $project, $profile, 'services', 'service');
                }
            }

            $pullRequestId = $this->pullRequestId($profile);
            $dockerTag = $this->sourceType($profile) === 'docker_image' && $pullRequestId !== ''
                ? $this->profileOption($profile, 'docker_tag', $this->stringConfig('docker_tag'))
                : '';
            $forceDeploy = $this->profileOptions($profile)['force_deploy'] ?? $this->config['force_deploy'] ?? false;
            $instantDeploy = ($this->effectiveApplicationOptions($profile)['instant_deploy'] ?? false) === true;
            if ($pullRequestId === '') {
                $this->startApplicationResource($applicationUuid, $forceDeploy === true, $instantDeploy);
            } else {
                $this->deployResource($client, $applicationUuid, $pullRequestId, $dockerTag, $forceDeploy === true);
            }
            $previewDomains = $this->profileOptions($profile)['preview_domains'] ?? $this->config['preview_domains'] ?? null;
            if ($pullRequestId !== '' && \is_array($previewDomains)) {
                try {
                    $this->updatePreviewDeploymentDomains(
                        $applicationUuid,
                        (int) $pullRequestId,
                        $this->previewDomainSettingsForSsl($project, $profile, $previewDomains),
                    );
                } catch (\RuntimeException $exception) {
                    if (\str_contains($exception->getMessage(), '(HTTP 404)')) {
                        throw new \RuntimeException(
                            "Coolify preview #{$pullRequestId} does not exist yet. For Git applications, open or reopen the pull request and let Coolify's configured webhook create the preview before applying preview_domains.",
                            0,
                            $exception,
                        );
                    }
                    throw $exception;
                }
            }
            if ($serviceUuid !== null) {
                if ($forceDeploy === true) {
                    $this->deployResource($client, $serviceUuid, '', '', true);
                } else {
                    $client->request('POST', '/services/'.\rawurlencode($serviceUuid).'/start');
                }
            }
            foreach ($this->ownedApplications($client, $project, $profile, $this->workerResourceTag($project, $profile)) as $worker) {
                $uuid = $this->resourceUuid($worker);
                $workerName = \is_string($worker['name'] ?? null) ? $worker['name'] : '';
                $restartOnDeploy = $desiredWorkerNames[$workerName] ?? false;
                if ($uuid !== $applicationUuid && \array_key_exists($workerName, $desiredWorkerNames)
                    && $this->workerNeedsDeployment($client, $uuid, $restartOnDeploy)) {
                    $this->deployResource($client, $uuid, '', '', $forceDeploy === true);
                }
            }

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'Coolify deployment failed: '.$exception->getMessage();

            return false;
        }
    }

    public function destroy(object $project, object $profile): bool
    {
        $this->lastError = '';
        $pullRequestId = $this->pullRequestId($profile);
        if ($pullRequestId !== '') {
            if (! \ctype_digit($pullRequestId) || (int) $pullRequestId < 1) {
                $this->lastError = 'Coolify pull_request_id must be a positive integer.';

                return false;
            }

            return $this->deletePreviewByPullRequest($project, $profile, (int) $pullRequestId);
        }
        try {
            $client = $this->getClient();
            $tag = $this->resourceTag($project, $profile);
            $apps = $this->list($client->request('GET', '/applications?tag='.\rawurlencode($tag)));
            foreach ($apps as $app) {
                if (! $this->hasTag($app, $tag) || ! $this->hasTag($app, self::MARKER)) {
                    continue;
                }
                $uuid = $this->resourceUuid($app);
                if ($uuid !== '') {
                    $client->request('DELETE', '/applications/'.\rawurlencode($uuid));
                }
            }

            foreach ($this->ownedApplications($client, $project, $profile, $this->workerResourceTag($project, $profile)) as $worker) {
                $uuid = $this->resourceUuid($worker);
                if ($uuid !== '') {
                    $client->request('DELETE', '/applications/'.\rawurlencode($uuid));
                }
            }

            $previewTagPrefix = $tag.'-pr-';
            foreach ($this->list($client->request('GET', '/applications')) as $app) {
                if (! $this->hasTag($app, self::MARKER) || ! \is_string($app['name'] ?? null)) {
                    continue;
                }
                foreach ($this->tagNames($app['tags'] ?? []) as $resourceTag) {
                    if (! \str_starts_with($resourceTag, $previewTagPrefix)) {
                        continue;
                    }
                    $pullRequestId = \substr($resourceTag, \strlen($previewTagPrefix));
                    if (\ctype_digit($pullRequestId) && (int) $pullRequestId > 0) {
                        $workerPrefix = $this->workerResourceName($project, $profile, $pullRequestId).'-';
                        $workerType = \substr($app['name'], \strlen($workerPrefix));
                        if (\str_starts_with($app['name'], $workerPrefix)
                            && (\str_starts_with($workerType, 'queue-') || \str_starts_with($workerType, 'daemon-'))) {
                            $uuid = $this->resourceUuid($app);
                            if ($uuid !== '') {
                                $client->request('DELETE', '/applications/'.\rawurlencode($uuid));
                            }
                        }
                    }
                    break;
                }
            }

            foreach ($this->ownedDatabasesForProfile($client, $tag) as $database) {
                $uuid = $this->resourceUuid($database);
                if ($uuid !== '') {
                    $client->request('DELETE', '/databases/'.\rawurlencode($uuid));
                }
            }

            foreach ($this->ownedServices($client, $tag) as $service) {
                $uuid = $this->resourceUuid($service);
                if ($uuid !== '') {
                    $client->request('DELETE', '/services/'.\rawurlencode($uuid));
                }
            }

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'Coolify cleanup failed: '.$exception->getMessage();

            return false;
        }
    }

    /** @return array<int, string> */
    public function logs(object $project, object $profile, int $lines = 100): array
    {
        if ($lines < 1 || $lines > 5000) {
            throw new \InvalidArgumentException('Coolify log lines must be between 1 and 5000.');
        }
        $showTimestamps = ($this->profileOptions($profile)['show_log_timestamps']
            ?? $this->config['show_log_timestamps']
            ?? false) === true;
        $options = $this->profileOptions($profile);
        $workloadLogName = $options['workload_log_name'] ?? $this->config['workload_log_name'] ?? null;
        if ($workloadLogName !== null) {
            if (! \is_string($workloadLogName) || \trim($workloadLogName) === '') {
                throw new \InvalidArgumentException('Coolify workload_log_name must be a non-empty generated Shipper worker application name.');
            }
            if (isset($options['database_log_name']) || isset($this->config['database_log_name'])
                || isset($options['sub_service_name']) || isset($this->config['sub_service_name'])) {
                throw new \InvalidArgumentException('Coolify workload_log_name cannot be combined with database_log_name or sub_service_name.');
            }
            $workerUuid = $this->findManagedWorkloadUuid($project, $profile, $workloadLogName);
            $response = $this->getClient()->request(
                'GET',
                '/applications/'.\rawurlencode($workerUuid).'/logs?'.\http_build_query([
                    'lines' => $lines,
                    'show_timestamps' => $showTimestamps,
                ]),
            );
            $logs = $response['logs'] ?? '';
            if (! \is_string($logs) || $logs === '') {
                return [];
            }

            return \array_values(\array_filter(\preg_split('/\r?\n/', $logs) ?: [], static fn (string $line): bool => $line !== ''));
        }
        $databaseLogName = $options['database_log_name'] ?? $this->config['database_log_name'] ?? null;
        if ($databaseLogName !== null) {
            if (! \is_string($databaseLogName) || \trim($databaseLogName) === '') {
                throw new \InvalidArgumentException('Coolify database_log_name must be a non-empty Shipper database declaration name.');
            }
            if (isset($options['sub_service_name']) || isset($this->config['sub_service_name'])) {
                throw new \InvalidArgumentException('Coolify database_log_name and sub_service_name cannot be used together.');
            }
            $databaseUuid = $this->findManagedDatabaseUuid($project, $profile, $databaseLogName);
            $response = $this->databaseLogs($databaseUuid, $lines, $showTimestamps);
            $logs = $response['logs'] ?? '';
            if (! \is_string($logs) || $logs === '') {
                return [];
            }

            return \array_values(\array_filter(\preg_split('/\r?\n/', $logs) ?: [], static fn (string $line): bool => $line !== ''));
        }
        $subServiceName = $options['sub_service_name'] ?? null;
        if ($subServiceName !== null) {
            if ($this->pullRequestId($profile) !== '') {
                throw new \InvalidArgumentException('Coolify PR preview logs cannot target sub_service_name because the separately managed Compose service remains shared with production.');
            }
            if (! \is_string($subServiceName) || \trim($subServiceName) === '') {
                throw new \InvalidArgumentException('Coolify service logs require a non-empty sub_service_name.');
            }
            $serviceUuid = $this->findManagedServiceUuid($project, $profile);
            $response = $this->serviceLogs($serviceUuid, $subServiceName, $lines, $showTimestamps);
            $logs = $response['logs'] ?? '';
            if (! \is_string($logs) || $logs === '') {
                return [];
            }

            return \array_values(\array_filter(\preg_split('/\r?\n/', $logs) ?: [], static fn (string $line): bool => $line !== ''));
        }

        $app = $this->findApplication($this->getClient(), $project, $profile);
        if ($app === null) {
            throw new \RuntimeException('The Shipper-managed Coolify application was not found.');
        }
        $uuid = $this->resourceUuid($app);
        $pullRequestId = $this->pullRequestId($profile);
        $logPath = $pullRequestId !== ''
            ? '/applications/'.\rawurlencode($uuid).'/previews/'.\rawurlencode($pullRequestId).'/logs'
            : '/applications/'.\rawurlencode($uuid).'/logs';
        $response = $pullRequestId !== ''
            ? $this->previewApplicationLogs($uuid, (int) $pullRequestId, $lines, $showTimestamps)
            : $this->getClient()->request('GET', $logPath.'?'.\http_build_query([
                'lines' => $lines,
                'show_timestamps' => $showTimestamps,
            ]));
        $logs = $response['logs'] ?? '';
        if (! \is_string($logs) || $logs === '') {
            return [];
        }

        return \array_values(\array_filter(\preg_split('/\r?\n/', $logs) ?: [], static fn (string $line): bool => $line !== ''));
    }

    private function findManagedServiceUuid(object $project, object $profile): string
    {
        $tag = $this->resourceTag($project, $profile);
        foreach ($this->ownedServices($this->getClient(), $tag) as $service) {
            $uuid = $this->resourceUuid($service);
            if ($uuid !== '') {
                return $uuid;
            }
        }

        throw new \RuntimeException('The Shipper-managed Coolify Compose service was not found for service logs.');
    }

    private function findManagedDatabaseUuid(object $project, object $profile, string $databaseLogName): string
    {
        $declaration = null;
        foreach ($this->databaseDeclarations($project, $profile) as $database) {
            if ($database['name'] === $databaseLogName) {
                $declaration = $database;
                break;
            }
        }
        if ($declaration === null) {
            throw new \InvalidArgumentException("Coolify database_log_name '{$databaseLogName}' does not match a declared Shipper database.");
        }
        $resourceName = $this->slug($this->interpolate($declaration['name'], $project, $profile));
        $tag = $this->resourceTag($project, $profile);
        if (($pullRequestId = $this->pullRequestId($profile)) !== '') {
            $suffix = '-pr-'.$pullRequestId;
            $resourceName = \substr($resourceName, 0, 50 - \strlen($suffix)).$suffix;
            $tag .= $suffix;
        }
        foreach ($this->ownedDatabases($this->getClient(), $tag) as $database) {
            if (($database['name'] ?? null) === $resourceName) {
                $uuid = $this->resourceUuid($database);
                if ($uuid !== '') {
                    return $uuid;
                }
            }
        }

        throw new \RuntimeException("The Shipper-managed Coolify database '{$databaseLogName}' was not found in the current deployment scope.");
    }

    private function findManagedWorkloadUuid(object $project, object $profile, string $workloadName): string
    {
        $prefix = $this->workerResourceName($project, $profile).'-';
        if (! \str_starts_with($workloadName, $prefix)
            || (! \str_starts_with($workloadName, $prefix.'queue-') && ! \str_starts_with($workloadName, $prefix.'daemon-'))) {
            throw new \InvalidArgumentException("Coolify workload_log_name must identify a generated queue or daemon application for the current deployment scope.");
        }
        foreach ($this->ownedApplications($this->getClient(), $project, $profile, $this->workerResourceTag($project, $profile)) as $worker) {
            if (($worker['name'] ?? null) === $workloadName) {
                $uuid = $this->resourceUuid($worker);
                if ($uuid !== '') {
                    return $uuid;
                }
            }
        }

        throw new \RuntimeException("The Shipper-managed Coolify workload '{$workloadName}' was not found in the current deployment scope.");
    }

    /** @return array<string, mixed> */
    /** @return list<array<string, mixed>> */
    public function applicationScheduledTaskExecutions(string $applicationUuid, string $taskUuid): array
    {
        if (\trim($applicationUuid) === '' || \trim($taskUuid) === '') {
            throw new \InvalidArgumentException('Application UUID and scheduled-task UUID are required.');
        }

        return $this->getClient()->request(
            'GET',
            '/applications/'.\rawurlencode($applicationUuid).'/scheduled-tasks/'.\rawurlencode($taskUuid).'/executions',
        );
    }

    /** @return list<array<string, mixed>> */
    public function serviceScheduledTaskExecutions(string $serviceUuid, string $taskUuid): array
    {
        if (\trim($serviceUuid) === '' || \trim($taskUuid) === '') {
            throw new \InvalidArgumentException('Service UUID and scheduled-task UUID are required.');
        }

        return $this->getClient()->request(
            'GET',
            '/services/'.\rawurlencode($serviceUuid).'/scheduled-tasks/'.\rawurlencode($taskUuid).'/executions',
        );
    }

    /** @return array<string, mixed> */
    public function executeApplicationScheduledTask(string $applicationUuid, string $taskUuid): array
    {
        if (\trim($applicationUuid) === '' || \trim($taskUuid) === '') {
            throw new \InvalidArgumentException('Application UUID and scheduled-task UUID are required.');
        }

        return $this->getClient()->request(
            'POST',
            '/applications/'.\rawurlencode($applicationUuid).'/scheduled-tasks/'.\rawurlencode($taskUuid).'/execute',
        );
    }

    /** @return array<string, mixed> */
    public function executeServiceScheduledTask(string $serviceUuid, string $taskUuid): array
    {
        if (\trim($serviceUuid) === '' || \trim($taskUuid) === '') {
            throw new \InvalidArgumentException('Service UUID and scheduled-task UUID are required.');
        }

        return $this->getClient()->request(
            'POST',
            '/services/'.\rawurlencode($serviceUuid).'/scheduled-tasks/'.\rawurlencode($taskUuid).'/execute',
        );
    }

    /** @return array<string, mixed> */
    public function startApplicationResource(string $applicationUuid, bool $force = false, bool $instantDeploy = false): array
    {
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Application UUID is required.');
        }

        $query = [];
        if ($force) {
            $query['force'] = 'true';
        }
        if ($instantDeploy) {
            $query['instant_deploy'] = 'true';
        }
        $path = '/applications/'.\rawurlencode($applicationUuid).'/start';

        return $this->getClient()->request('POST', $path.($query === [] ? '' : '?'.\http_build_query($query)));
    }

    /** @return array<string, mixed> */
    public function restartApplicationResource(string $applicationUuid): array
    {
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Application UUID is required.');
        }

        return $this->getClient()->request('POST', '/applications/'.\rawurlencode($applicationUuid).'/restart');
    }

    /** @return array<string, mixed> */
    public function stopApplicationResource(string $applicationUuid, bool $dockerCleanup = true): array
    {
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Application UUID is required.');
        }

        $path = '/applications/'.\rawurlencode($applicationUuid).'/stop';

        return $this->getClient()->request('POST', $path.($dockerCleanup ? '' : '?docker_cleanup=false'));
    }

    /** @return array<string, mixed> */
    public function startServiceResource(string $serviceUuid): array
    {
        if (\trim($serviceUuid) === '') {
            throw new \InvalidArgumentException('Service UUID is required.');
        }

        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/start');
    }

    /** @return array<string, mixed> */
    public function restartServiceResource(string $serviceUuid, bool $latest = false): array
    {
        if (\trim($serviceUuid) === '') {
            throw new \InvalidArgumentException('Service UUID is required.');
        }

        $path = '/services/'.\rawurlencode($serviceUuid).'/restart';

        return $this->getClient()->request('POST', $path.($latest ? '?latest=true' : ''));
    }

    /** @return array<string, mixed> */
    public function stopServiceResource(string $serviceUuid, bool $dockerCleanup = true): array
    {
        if (\trim($serviceUuid) === '') {
            throw new \InvalidArgumentException('Service UUID is required.');
        }

        $path = '/services/'.\rawurlencode($serviceUuid).'/stop';

        return $this->getClient()->request('POST', $path.($dockerCleanup ? '' : '?docker_cleanup=false'));
    }

    /** @return array<string, mixed> */
    /** @return list<array<string, mixed>> */
    public function deployments(): array
    {
        return $this->list($this->getClient()->request('GET', '/deployments'));
    }

    /** @return array<string, mixed> */
    public function deployment(string $deploymentUuid): array
    {
        return $this->getClient()->request('GET', '/deployments/'.\rawurlencode($deploymentUuid));
    }

    /** @return list<array<string, mixed>> */
    public function applicationDeployments(string $applicationUuid, int $skip = 0, int $take = 10): array
    {
        if ($skip < 0 || $take < 1) {
            throw new \InvalidArgumentException('Coolify deployment history requires skip >= 0 and take >= 1.');
        }

        $path = '/deployments/applications/'.\rawurlencode($applicationUuid);

        return $this->list($this->getClient()->request('GET', $path.'?'.\http_build_query(['skip' => $skip, 'take' => $take])));
    }

    /** @return array{current?: string, images?: list<array{tag: string, created_at?: string, is_current?: bool}>} */
    public function applicationRollbackImages(string $applicationUuid): array
    {
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Coolify application UUID is required to list rollback images.');
        }

        return $this->getClient()->request('GET', '/applications/'.\rawurlencode($applicationUuid).'/rollback-images');
    }

    public function cancelDeployment(string $deploymentUuid): array
    {
        if (\trim($deploymentUuid) === '') {
            throw new \InvalidArgumentException('Deployment UUID is required.');
        }

        return $this->getClient()->request('POST', '/deployments/'.\rawurlencode($deploymentUuid).'/cancel');
    }

    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    /**
     * Read runtime logs for one Coolify pull-request preview.
     * @return array<string, mixed>
     */
    public function previewApplicationLogs(string $applicationUuid, int $pullRequestId, int|string $lines = 100, bool $showTimestamps = false): array
    {
        if ($pullRequestId < 1) {
            throw new \InvalidArgumentException('Coolify preview pull request ID must be positive.');
        }
        if ($lines !== 'all' && ($lines < -1 || $lines > 10000)) {
            throw new \InvalidArgumentException('Coolify preview log lines must be -1, between 0 and 10000, or all.');
        }

        $path = '/applications/'.\rawurlencode($applicationUuid).'/previews/'.$pullRequestId.'/logs';
        $query = \http_build_query(['lines' => $lines, 'show_timestamps' => $showTimestamps]);

        return $this->getClient()->request('GET', $path.'?'.$query);
    }

    public function updatePreviewDeploymentDomains(string $applicationUuid, int $pullRequestId, array $settings): array
    {
        if (\trim($applicationUuid) === '' || $pullRequestId < 1) {
            throw new \InvalidArgumentException('Application UUID and a positive pull request ID are required.');
        }

        $allowed = ['domains', 'docker_compose_domains', 'force_domain_override'];
        foreach ($settings as $key => $value) {
            if (! \in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported preview domain setting '{$key}'.");
            }
            if ($key === 'domains' && $value !== null && ! \is_string($value)) {
                throw new \InvalidArgumentException('Preview domains must be a string or null.');
            }
            if ($key === 'docker_compose_domains' && $value !== null && ! \is_array($value)) {
                throw new \InvalidArgumentException('Preview Docker Compose domains must be an array or null.');
            }
            if ($key === 'force_domain_override' && ! \is_bool($value)) {
                throw new \InvalidArgumentException('Preview force_domain_override must be a boolean.');
            }
        }
        if (! \array_key_exists('domains', $settings) && ! \array_key_exists('docker_compose_domains', $settings)) {
            throw new \InvalidArgumentException('Preview domain settings must include domains or docker_compose_domains.');
        }

        return $this->getClient()->request(
            'PATCH',
            '/applications/'.\rawurlencode($applicationUuid).'/previews/'.$pullRequestId,
            $settings,
        );
    }

    public function status(object $project, object $profile): array
    {
        $app = $this->findApplication($this->getClient(), $project, $profile);
        $pullRequestId = $this->pullRequestId($profile);
        if ($app === null) {
            $resources = $this->orphanedManagedResourceStatuses($project, $profile, $pullRequestId);

            return [
                'provider' => 'coolify',
                'status' => 'not_found',
                'application' => null,
                ...$resources,
                'pull_request_id' => $pullRequestId !== '' ? (int) $pullRequestId : null,
            ];
        }
        $uuid = $this->resourceUuid($app);
        $application = $this->getClient()->request('GET', '/applications/'.\rawurlencode($uuid));
        $runningDeployments = $this->list($this->getClient()->request('GET', '/deployments'));
        $applicationId = $application['id'] ?? $app['id'] ?? null;
        $deployments = \array_values(\array_filter($runningDeployments, static function (array $deployment) use ($applicationId, $pullRequestId): bool {
            if ($applicationId === null || ! isset($deployment['application_id'])
                || (string) $deployment['application_id'] !== (string) $applicationId) {
                return false;
            }
            $deploymentPullRequestId = (string) ($deployment['pull_request_id'] ?? '');

            return $pullRequestId !== ''
                ? $deploymentPullRequestId === $pullRequestId
                : ($deploymentPullRequestId === '' || $deploymentPullRequestId === '0');
        }));
        $deploymentHistory = \array_values(\array_filter(
            $this->applicationDeployments($uuid, 0, 20),
            static function (array $deployment) use ($pullRequestId): bool {
                $deploymentPullRequestId = (string) ($deployment['pull_request_id'] ?? '');

                return $pullRequestId !== ''
                    ? $deploymentPullRequestId === $pullRequestId
                    : ($deploymentPullRequestId === '' || $deploymentPullRequestId === '0');
            },
        ));
        if (\count(\array_filter($deploymentHistory, static fn (array $deployment): bool => \is_string($deployment['created_at'] ?? null))) > 0) {
            \usort($deploymentHistory, static fn (array $left, array $right): int =>
                \strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
        }
        $workerPrefix = $this->workerResourceName($project, $profile).'-';
        $workloads = [];
        foreach ($this->ownedApplications($this->getClient(), $project, $profile, $this->workerResourceTag($project, $profile)) as $worker) {
            $name = $worker['name'] ?? null;
            if (! \is_string($name)
                || ! \str_starts_with($name, $workerPrefix)
                || (! \str_starts_with($name, $workerPrefix.'queue-') && ! \str_starts_with($name, $workerPrefix.'daemon-'))) {
                continue;
            }
            $workerUuid = $this->resourceUuid($worker);
            $workerDeployments = $workerUuid === '' ? [] : $this->applicationDeployments($workerUuid, 0, 20);
            if (\count(\array_filter($workerDeployments, static fn (array $deployment): bool => \is_string($deployment['created_at'] ?? null))) > 0) {
                \usort($workerDeployments, static fn (array $left, array $right): int =>
                    \strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
            }
            $workloads[] = [
                'uuid' => $workerUuid,
                'name' => $name,
                'status' => $worker['status'] ?? $worker['application_status'] ?? null,
                'latest_deployment' => $workerDeployments[0] ?? null,
            ];
        }
        $serviceStatus = null;
        foreach ($pullRequestId === '' ? $this->ownedServices($this->getClient(), $this->resourceTag($project, $profile)) : [] as $managedService) {
            $serviceUuid = $this->resourceUuid($managedService);
            if ($serviceUuid === '') {
                break;
            }
            $service = $this->serviceResource($serviceUuid);
            $serviceStatus = [
                'uuid' => $serviceUuid,
                'name' => $service['name'] ?? $managedService['name'],
                'status' => $service['status'] ?? $service['application_status'] ?? null,
                'service_type' => $service['service_type'] ?? $managedService['service_type'] ?? null,
                'applications' => $this->statusComponents($this->serviceApplications($serviceUuid)),
                'databases' => $this->statusComponents($this->serviceDatabases($serviceUuid)),
                'scheduled_tasks' => $this->scheduledTaskStatuses('services', $serviceUuid, $project, $profile),
            ];
            break;
        }
        $databaseStatuses = $this->standaloneDatabaseStatuses($project, $profile, $pullRequestId);

        return [
            'provider' => 'coolify',
            'application_uuid' => $uuid,
            'name' => $application['name'] ?? $app['name'] ?? null,
            'status' => $application['status'] ?? $application['application_status'] ?? null,
            'domains' => $application['fqdn'] ?? $application['domains'] ?? [],
            'running_deployments' => $deployments,
            'latest_deployment' => $deploymentHistory[0] ?? null,
            'deployment_history' => $deploymentHistory,
            'rollback_images' => $this->applicationRollbackImages($uuid),
            'scheduled_tasks' => $this->scheduledTaskStatuses('applications', $uuid, $project, $profile),
            'workloads' => $workloads,
            'previews' => $this->applicationPreviews($uuid),
            'service' => $serviceStatus,
            'databases' => $databaseStatuses,
            'pull_request_id' => $pullRequestId !== '' ? (int) $pullRequestId : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function scheduledTaskStatuses(string $resourceType, string $resourceUuid, object $project, object $profile): array
    {
        if ($this->pullRequestId($profile) !== '') {
            return [];
        }
        $prefix = 'shipper-'.$this->projectProfileHash($project, $profile).'-';
        $tasks = [];
        foreach ($this->scheduledTasks($resourceType, $resourceUuid) as $task) {
            $name = $task['name'] ?? null;
            $uuid = $this->resourceUuid($task);
            if (! \is_string($name) || ! \str_starts_with($name, $prefix) || $uuid === '') {
                continue;
            }
            $executions = $this->scheduledTaskExecutions($resourceType, $resourceUuid, $uuid);
            if (\count(\array_filter($executions, static fn (array $execution): bool =>
                \is_string($execution['started_at'] ?? $execution['created_at'] ?? $execution['updated_at'] ?? null))) > 0) {
                \usort($executions, static function (array $left, array $right): int {
                    $leftTimestamp = $left['started_at'] ?? $left['created_at'] ?? $left['updated_at'] ?? '';
                    $rightTimestamp = $right['started_at'] ?? $right['created_at'] ?? $right['updated_at'] ?? '';

                    return \strcmp((string) $rightTimestamp, (string) $leftTimestamp);
                });
            }
            $tasks[] = [
                'uuid' => $uuid,
                'name' => $name,
                'command' => $task['command'] ?? null,
                'frequency' => $task['frequency'] ?? null,
                'enabled' => $task['enabled'] ?? null,
                'container' => $task['container'] ?? null,
                'executions' => $executions,
                'latest_execution' => $executions[0] ?? null,
            ];
        }

        return $tasks;
    }

    /** @return list<array{uuid: string, name: mixed, type: mixed, status: mixed}> */
    private function standaloneDatabaseStatuses(object $project, object $profile, string $pullRequestId): array
    {
        $databaseTag = $this->resourceTag($project, $profile);
        if ($pullRequestId !== '') {
            $databaseTag .= '-pr-'.$pullRequestId;
        }
        $databaseStatuses = [];
        $backupOptions = $this->profileOptions($profile)['database_backups'] ?? $this->config['database_backups'] ?? [];
        $declaredDatabases = [];
        if ($pullRequestId === '' && \is_array($backupOptions)) {
            foreach ($this->databaseDeclarations($project, $profile) as $declaration) {
                $logicalName = $this->interpolate($declaration['name'], $project, $profile);
                $databaseName = $this->slug($logicalName);
                $backup = $backupOptions[$declaration['name']] ?? null;
                if (\is_array($backup) && \is_string($backup['uuid'] ?? null) && $backup['uuid'] !== '') {
                    $declaredDatabases[$databaseName] = $backup['uuid'];
                }
            }
        }
        foreach ($this->ownedDatabases($this->getClient(), $databaseTag) as $database) {
            $databaseUuid = $this->resourceUuid($database);
            if ($databaseUuid === '') {
                continue;
            }
            $databaseDetail = $this->databaseResource($databaseUuid);
            $databaseName = $databaseDetail['name'] ?? $database['name'] ?? null;
            $status = [
                'uuid' => $databaseUuid,
                'name' => $databaseName,
                'type' => $databaseDetail['type'] ?? $database['type'] ?? null,
                'status' => $databaseDetail['status'] ?? $databaseDetail['database_status'] ?? $database['status'] ?? null,
            ];
            if (\is_string($databaseName) && isset($declaredDatabases[$databaseName])) {
                $backupUuid = $declaredDatabases[$databaseName];
                $executions = $this->databaseBackupExecutions($databaseUuid, $backupUuid);
                $status['backup_uuid'] = $backupUuid;
                $status['backup_executions'] = \is_array($executions['executions'] ?? null)
                    ? $executions['executions']
                    : $executions;
            }
            $databaseStatuses[] = $status;
        }

        return $databaseStatuses;
    }

    /** @return array{workloads: list<array<string, mixed>>, service: array<string, mixed>|null, databases: list<array<string, mixed>>} */
    private function orphanedManagedResourceStatuses(object $project, object $profile, string $pullRequestId): array
    {
        $workerPrefix = $this->workerResourceName($project, $profile).'-';
        $workloads = [];
        foreach ($this->ownedApplications($this->getClient(), $project, $profile, $this->workerResourceTag($project, $profile)) as $worker) {
            $name = $worker['name'] ?? null;
            if (! \is_string($name)
                || ! \str_starts_with($name, $workerPrefix)
                || (! \str_starts_with($name, $workerPrefix.'queue-') && ! \str_starts_with($name, $workerPrefix.'daemon-'))) {
                continue;
            }
            $uuid = $this->resourceUuid($worker);
            $history = $uuid === '' ? [] : $this->applicationDeployments($uuid, 0, 1);
            $workloads[] = [
                'uuid' => $uuid,
                'name' => $name,
                'status' => $worker['status'] ?? $worker['application_status'] ?? null,
                'latest_deployment' => $history[0] ?? null,
            ];
        }
        $serviceStatus = null;
        foreach ($pullRequestId === '' ? $this->ownedServices($this->getClient(), $this->resourceTag($project, $profile)) : [] as $managedService) {
            $uuid = $this->resourceUuid($managedService);
            if ($uuid !== '') {
                $service = $this->serviceResource($uuid);
                $serviceStatus = [
                    'uuid' => $uuid,
                    'name' => $service['name'] ?? $managedService['name'],
                    'status' => $service['status'] ?? $service['application_status'] ?? null,
                    'service_type' => $service['service_type'] ?? $managedService['service_type'] ?? null,
                    'applications' => $this->statusComponents($this->serviceApplications($uuid)),
                    'databases' => $this->statusComponents($this->serviceDatabases($uuid)),
                ];
            }
            break;
        }

        return [
            'workloads' => $workloads,
            'service' => $serviceStatus,
            'databases' => $this->standaloneDatabaseStatuses($project, $profile, $pullRequestId),
        ];
    }

    /** @param list<array<string, mixed>> $components
     *  @return list<array{uuid: string, name: mixed, status: mixed}>
     */
    private function statusComponents(array $components): array
    {
        return \array_map(fn (array $component): array => [
            'uuid' => $this->resourceUuid($component),
            'name' => $component['name'] ?? $component['human_name'] ?? null,
            'status' => $component['status'] ?? $component['application_status'] ?? null,
        ], $components);
    }

    public function rollback(object $project, object $profile, ?string $release = null): bool
    {
        $this->lastError = '';
        try {
            $client = $this->getClient();
            $app = $this->findApplication($client, $project, $profile);
            if ($app === null) {
                throw new \RuntimeException('The Shipper-managed Coolify application was not found.');
            }
            $uuid = $this->resourceUuid($app);
            $rollbackImages = $this->applicationRollbackImages($uuid);
            $images = $rollbackImages['images'] ?? [];
            $availableTags = [];
            $previousImages = [];
            if (\is_array($images)) {
                foreach ($images as $image) {
                    if (\is_array($image) && \is_string($image['tag'] ?? null) && $image['tag'] !== '') {
                        $isCurrent = ($image['is_current'] ?? false) === true
                            || $image['tag'] === ($rollbackImages['current'] ?? null);
                        $availableTags[$image['tag']] = $isCurrent;
                        if (! $isCurrent) {
                            $previousImages[] = $image;
                        }
                    }
                }
            }
            $commit = $release;
            if ($commit === null || $commit === '') {
                $hasCreationTimes = $previousImages !== []
                    && \array_filter($previousImages, static fn (array $image): bool => \is_string($image['created_at'] ?? null)) !== [];
                if ($hasCreationTimes) {
                    \usort($previousImages, static fn (array $left, array $right): int =>
                        \strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
                }
                $commit = $previousImages[0]['tag'] ?? null;
            } elseif (! \array_key_exists($commit, $availableTags)) {
                throw new \RuntimeException("Coolify rollback target '{$commit}' is not present in the application's retained rollback images.");
            } elseif ($availableTags[$commit]) {
                throw new \RuntimeException('Coolify rollback target is already the current application image.');
            }
            if ($commit === null || $commit === '') {
                throw new \RuntimeException('Coolify has no retained previous image to roll back to; pass an available image tag as release.');
            }

            // Workers are separate applications built from the same source. Preflight
            // every owned worker before queuing any rollback so a missing retained
            // image cannot leave the primary application and workers on different code.
            $rollbackTargets = [['uuid' => $uuid, 'commit' => $commit]];
            $workerPrefix = $this->workerResourceName($project, $profile);
            foreach ($this->ownedApplications($client, $project, $profile, $this->workerResourceTag($project, $profile)) as $worker) {
                $workerName = $worker['name'] ?? '';
                if (! \is_string($workerName)
                    || (! \str_starts_with($workerName, $workerPrefix.'-queue-')
                        && ! \str_starts_with($workerName, $workerPrefix.'-daemon-'))) {
                    continue;
                }
                $workerUuid = $this->resourceUuid($worker);
                if ($workerUuid === '') {
                    continue;
                }
                $workerImagesResponse = $this->applicationRollbackImages($workerUuid);
                $workerImages = $workerImagesResponse['images'] ?? [];
                $workerHasTarget = false;
                $workerTargetIsCurrent = false;
                if (\is_array($workerImages)) {
                    foreach ($workerImages as $workerImage) {
                        if (! \is_array($workerImage) || ($workerImage['tag'] ?? null) !== $commit) {
                            continue;
                        }
                        $workerHasTarget = true;
                        $workerTargetIsCurrent = ($workerImage['is_current'] ?? false) === true
                            || $commit === ($workerImagesResponse['current'] ?? null);
                        break;
                    }
                }
                if (! $workerHasTarget) {
                    throw new \RuntimeException("Coolify rollback target '{$commit}' is not retained for Shipper-managed worker '{$workerName}'.");
                }
                if (! $workerTargetIsCurrent) {
                    $rollbackTargets[] = ['uuid' => $workerUuid, 'commit' => $commit];
                }
            }
            foreach ($rollbackTargets as $target) {
                $client->request('POST', '/applications/'.\rawurlencode($target['uuid']).'/rollback', ['commit' => $target['commit']]);
            }

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'Coolify rollback failed: '.$exception->getMessage();

            return false;
        }
    }

    /** @return array<int, array{site_id: int, domain: string}> */
    public function listSites(object $project, object $profile): array
    {
        if (($this->config['mcp_enabled'] ?? false) === true) {
            return $this->listApplicationPreviewSites($project, $profile);
        }

        $sites = [];
        $client = $this->getClient();
        $app = $this->findApplicationIfPresent($client, $project, $profile);
        if ($app !== null) {
            $uuid = $this->resourceUuid($app);
            $detail = $client->request('GET', '/applications/'.\rawurlencode($uuid));
            $domains = $detail['domains'] ?? $detail['fqdn'] ?? [];
            if (\is_string($domains)) {
                $domains = \preg_split('/\s*,\s*/', $domains, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            if (\is_array($domains)) {
                $id = $this->siteId($uuid);
                foreach ($domains as $domain) {
                    if (\is_string($domain) && $domain !== '') {
                        $sites[] = ['site_id' => $id, 'domain' => $domain];
                    }
                }
            }
        }
        foreach ($this->ownedPreviewPullRequestIds($client, $project, $profile) as $pullRequestId) {
            foreach ($this->previewDomainsForPullRequest($profile, $pullRequestId) as $domain) {
                $sites[] = ['site_id' => -$pullRequestId, 'domain' => $domain];
            }
        }

        return $sites;
    }

    /** @return array<int, array{site_id: int, domain: string}> */
    private function listApplicationPreviewSites(object $project, object $profile): array
    {
        $app = $this->findApplicationIfPresent($this->getClient(), $project, $profile);
        $sites = [];
        $listedPullRequestIds = [];
        if ($app !== null) {
            $applicationUuid = $this->resourceUuid($app);
            foreach ($this->getMcpClient()->listApplicationPreviews($applicationUuid) as $preview) {
                $rawPullRequestId = $preview['pull_request_id'] ?? null;
                $pullRequestId = \is_int($rawPullRequestId)
                    ? $rawPullRequestId
                    : (\is_string($rawPullRequestId) && \ctype_digit($rawPullRequestId) ? (int) $rawPullRequestId : 0);
                $fqdn = $preview['fqdn'] ?? null;
                if ($pullRequestId < 1 || ! \is_string($fqdn) || \trim($fqdn) === '') {
                    continue;
                }
                $listedPullRequestIds[$pullRequestId] = true;

                foreach (\preg_split('/\s*,\s*/', \trim($fqdn)) ?: [] as $domain) {
                    $domain = \trim($domain);
                    if ($domain !== '') {
                        // Negative IDs are reserved for PR previews and route cleanup to the PR-specific delete path.
                        $sites[] = ['site_id' => -$pullRequestId, 'domain' => $domain];
                    }
                }
            }
        }

        foreach ($this->ownedPreviewPullRequestIds($this->getClient(), $project, $profile) as $pullRequestId) {
            if (isset($listedPullRequestIds[$pullRequestId])) {
                continue;
            }
            foreach ($this->previewDomainsForPullRequest($profile, $pullRequestId) as $domain) {
                $sites[] = ['site_id' => -$pullRequestId, 'domain' => $domain];
            }
        }

        return $sites;
    }

    /** @return array<int, int> */
    private function ownedPreviewPullRequestIds(CoolifyApiClient $client, object $project, object $profile): array
    {
        $tagPrefix = $this->resourceTag($project, $profile).'-pr-';
        $ids = [];
        foreach ([
            ...$this->list($client->request('GET', '/applications')),
            ...$this->list($client->request('GET', '/databases')),
        ] as $resource) {
            if (! $this->hasTag($resource, self::MARKER)) {
                continue;
            }
            foreach ($this->tagNames($resource['tags'] ?? []) as $tag) {
                if (! \str_starts_with($tag, $tagPrefix)) {
                    continue;
                }
                $rawPullRequestId = \substr($tag, \strlen($tagPrefix));
                if (\ctype_digit($rawPullRequestId) && (int) $rawPullRequestId > 0) {
                    $ids[(int) $rawPullRequestId] = (int) $rawPullRequestId;
                }
            }
        }

        return \array_values($ids);
    }

    private function previewDomainForPullRequest(object $profile, int $pullRequestId): string
    {
        $domain = $this->profileValue($profile, 'domain');
        if (! \str_contains($domain, '${GITHUB_PR_NUMBER}')) {
            return '';
        }

        return \str_replace('${GITHUB_PR_NUMBER}', (string) $pullRequestId, $domain);
    }

    /** @return list<string> */
    private function previewDomainsForPullRequest(object $profile, int $pullRequestId): array
    {
        $options = $this->profileOptions($profile);
        $settings = $options['preview_domains'] ?? $this->config['preview_domains'] ?? [];
        $domains = [];
        if (\is_array($settings)) {
            $configured = $settings['domains'] ?? null;
            if (\is_string($configured)) {
                $domains = [...$domains, ...(\preg_split('/\\s*,\\s*/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [])];
            }
            $composeDomains = $settings['docker_compose_domains'] ?? [];
            if (\is_array($composeDomains)) {
                foreach ($composeDomains as $entry) {
                    if (\is_array($entry) && \is_string($entry['domains'] ?? null)) {
                        $domains = [...$domains, ...(\preg_split('/\\s*,\\s*/', $entry['domains'], -1, PREG_SPLIT_NO_EMPTY) ?: [])];
                    }
                }
            }
        }
        if ($domains === []) {
            $fallback = $this->previewDomainForPullRequest($profile, $pullRequestId);
            if ($fallback !== '') {
                $domains[] = $fallback;
            }
        }

        $resolved = [];
        foreach ($domains as $domain) {
            $domain = \trim(\str_replace('${GITHUB_PR_NUMBER}', (string) $pullRequestId, $domain));
            if ($domain !== '') {
                $resolved[$domain] = $domain;
            }
        }

        return \array_values($resolved);
    }

    /** @return list<array<string, mixed>> */
    public function destinations(): array
    {
        return $this->list($this->getClient()->request('GET', '/destinations'));
    }

    /** @return list<array<string, mixed>> */
    public function serverDestinations(string $serverUuid): array
    {
        return $this->list($this->getClient()->request('GET', '/servers/'.\rawurlencode($serverUuid).'/destinations'));
    }

    /** @return array<string, mixed> */
    public function destination(string $destinationUuid): array
    {
        return $this->getClient()->request('GET', '/destinations/'.\rawurlencode($destinationUuid));
    }

    /** @param array<string, mixed> $destination @return array<string, mixed> */
    public function createServerDestination(string $serverUuid, array $destination): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverUuid).'/destinations', $destination);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateDestination(string $destinationUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/destinations/'.\rawurlencode($destinationUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function deleteDestination(string $destinationUuid): array
    {
        return $this->getClient()->request('DELETE', '/destinations/'.\rawurlencode($destinationUuid));
    }

    /** @return list<array<string, mixed>> */
    public function applications(?string $tag = null): array
    {
        if ($tag !== null && \trim($tag) === '') {
            throw new \InvalidArgumentException('Coolify application tag filter must not be empty.');
        }
        $path = '/applications'.($tag !== null ? '?'.\http_build_query(['tag' => $tag]) : '');

        return $this->list($this->getClient()->request('GET', $path));
    }

    /** @return array<string|int, mixed> */
    public function resources(): array
    {
        return $this->getClient()->request('GET', '/resources');
    }

    /** @return array<string, mixed> */
    public function applicationResource(string $applicationUuid): array
    {
        return $this->getClient()->request('GET', '/applications/'.\rawurlencode($applicationUuid));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateApplicationResource(string $applicationUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/applications/'.\rawurlencode($applicationUuid), $changes);
    }

    /** Delete a Coolify application with explicit configuration/data cleanup controls. @return array<string, mixed> */
    public function deleteApplicationResource(string $applicationUuid, bool $deleteConfigurations = true, bool $deleteVolumes = true, bool $dockerCleanup = true, bool $deleteConnectedNetworks = true): array
    {
        return $this->deleteResource('applications', $applicationUuid, $deleteConfigurations, $deleteVolumes, $dockerCleanup, $deleteConnectedNetworks);
    }

    /** @return list<array<string, mixed>> Primary and additional destinations for a standalone application. */
    public function applicationDestinations(string $applicationUuid): array
    {
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Coolify application UUID must not be empty.');
        }

        return $this->list($this->getClient()->request(
            'GET',
            '/applications/'.\rawurlencode($applicationUuid).'/destinations',
        ));
    }

    /** Attach an additional standalone Docker destination to an application. @return array<string, mixed> */
    public function attachApplicationDestination(string $applicationUuid, string $destinationUuid): array
    {
        if (\trim($applicationUuid) === '' || \trim($destinationUuid) === '') {
            throw new \InvalidArgumentException('Coolify application and destination UUIDs must not be empty.');
        }

        return $this->getClient()->request(
            'POST',
            '/applications/'.\rawurlencode($applicationUuid).'/destinations',
            ['destination_uuid' => $destinationUuid],
        );
    }

    /** Detach an additional destination without deleting the destination or its server. @return array<string, mixed> */
    public function detachApplicationDestination(string $applicationUuid, string $destinationUuid): array
    {
        if (\trim($applicationUuid) === '' || \trim($destinationUuid) === '') {
            throw new \InvalidArgumentException('Coolify application and destination UUIDs must not be empty.');
        }

        return $this->getClient()->request(
            'DELETE',
            '/applications/'.\rawurlencode($applicationUuid).'/destinations/'.\rawurlencode($destinationUuid),
        );
    }

    /** Clone an application to another Coolify destination. @return array<string, mixed> */
    public function cloneApplication(string $applicationUuid, string $destinationUuid, ?string $name = null, bool $cloneVolumes = false): array
    {
        $this->assertResourceIdentifier($applicationUuid, 'application');
        $this->assertResourceIdentifier($destinationUuid, 'destination');
        if ($name !== null && \trim($name) === '') {
            throw new \InvalidArgumentException('Coolify cloned application name must not be empty.');
        }
        $payload = ['destination_uuid' => $destinationUuid, 'clone_volumes' => $cloneVolumes];
        if ($name !== null) {
            $payload['name'] = $name;
        }

        return $this->getClient()->request('POST', '/applications/'.\rawurlencode($applicationUuid).'/clone', $payload);
    }

    /** Migrate an application to another Coolify destination/server. @return array<string, mixed> */
    public function migrateApplication(string $applicationUuid, string $destinationUuid, bool $migrateVolumes = true): array
    {
        $this->assertResourceIdentifier($applicationUuid, 'application');
        $this->assertResourceIdentifier($destinationUuid, 'destination');

        return $this->getClient()->request('POST', '/applications/'.\rawurlencode($applicationUuid).'/migrate', [
            'destination_uuid' => $destinationUuid,
            'migrate_volumes' => $migrateVolumes,
        ]);
    }

    /** Move an application to another Coolify environment without deploying it. @return array<string, mixed> */
    public function moveApplication(string $applicationUuid, string $environmentUuid): array
    {
        $this->assertResourceIdentifier($applicationUuid, 'application');
        $this->assertResourceIdentifier($environmentUuid, 'environment');

        return $this->getClient()->request('POST', '/applications/'.\rawurlencode($applicationUuid).'/move', [
            'environment_uuid' => $environmentUuid,
        ]);
    }

    private function assertResourceIdentifier(string $identifier, string $resource): void
    {
        if (\trim($identifier) === '') {
            throw new \InvalidArgumentException("Coolify {$resource} UUID must not be empty.");
        }
    }

    private function assertPositiveIntegrationAppId(int $appId, string $provider): void
    {
        if ($appId < 1) {
            throw new \InvalidArgumentException("Coolify {$provider} app ID must be a positive integer.");
        }
    }

    private function notificationChannel(string $channel): string
    {
        $channel = \strtolower($channel);
        if (! \in_array($channel, ['discord', 'email', 'pushover', 'slack', 'telegram', 'webhook'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify notification channel '{$channel}'.");
        }

        return $channel;
    }

    /** @param mixed $desiredDestinationUuids */
    private function reconcileAdditionalDestinations(string $applicationUuid, mixed $desiredDestinationUuids): void
    {
        if (! \is_array($desiredDestinationUuids) || $desiredDestinationUuids === []) {
            return;
        }
        $attached = [];
        foreach ($this->applicationDestinations($applicationUuid) as $destination) {
            $uuid = $destination['destination_uuid'] ?? $destination['uuid'] ?? $destination['destination']['uuid'] ?? null;
            if (\is_string($uuid) && $uuid !== '') {
                $attached[$uuid] = true;
            }
        }
        foreach ($desiredDestinationUuids as $uuid) {
            if (! \is_string($uuid) || \trim($uuid) === '' || isset($attached[$uuid])) {
                continue;
            }
            $this->attachApplicationDestination($applicationUuid, $uuid);
            $attached[$uuid] = true;
        }
    }

    public function deleteSiteWithDatabases(object $project, object $profile, int $siteId): bool
    {
        if ($siteId < 0) {
            return $this->deletePreviewByPullRequest($project, $profile, -$siteId);
        }

        $this->lastError = '';
        try {
            $app = $this->findApplication($this->getClient(), $project, $profile);
            if ($app !== null && $this->siteId($this->resourceUuid($app)) === $siteId) {
                $this->lastError = 'Refusing to remove the production Coolify application through preview-site cleanup.';

                return false;
            }
            $this->lastError = 'Coolify preview cleanup requires the pull request ID; the generic site ID does not identify a preview safely.';

            return false;
        } catch (\Throwable $exception) {
            $this->lastError = 'Coolify preview cleanup failed: '.$exception->getMessage();

            return false;
        }
    }

    public function deletePreviewByPullRequest(object $project, object $profile, int $pullRequestId): bool
    {
        $this->lastError = '';
        if ($pullRequestId < 1) {
            $this->lastError = 'Coolify preview cleanup requires a positive pull request ID.';

            return false;
        }
        try {
            $client = $this->getClient();
            $app = $this->findApplicationIfPresent($client, $project, $profile);
            if ($app !== null && (! $this->hasTag($app, self::MARKER)
                || ! $this->hasTag($app, $this->resourceTag($project, $profile)))) {
                throw new \RuntimeException('The Shipper-managed Coolify application was not found or is not owned.');
            }
            if ($app !== null) {
                $uuid = $this->resourceUuid($app);
                try {
                    $client->request('DELETE', '/applications/'.\rawurlencode($uuid).'/previews/'.$pullRequestId);
                } catch (\RuntimeException $exception) {
                    if (! \str_contains($exception->getMessage(), '(HTTP 404)')) {
                        throw $exception;
                    }
                }
            }
            $previewTag = $this->resourceTag($project, $profile).'-pr-'.$pullRequestId;
            foreach ($this->ownedApplications($client, $project, $profile, $previewTag) as $worker) {
                $workerUuid = $this->resourceUuid($worker);
                $workerName = $worker['name'] ?? '';
                $workerPrefix = $this->workerResourceName($project, $profile, (string) $pullRequestId);
                if ($workerUuid !== '' && \is_string($workerName)
                    && \str_starts_with($workerName, $workerPrefix.'-')
                    && ($worker['name'] ?? '') !== $this->resourceName($project, $profile)) {
                    $client->request('DELETE', '/applications/'.\rawurlencode($workerUuid));
                }
            }
            foreach ($this->ownedDatabases($client, $previewTag) as $database) {
                $databaseUuid = $this->resourceUuid($database);
                if ($databaseUuid !== '') {
                    $client->request('DELETE', '/databases/'.\rawurlencode($databaseUuid));
                }
            }

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'Coolify preview cleanup failed: '.$exception->getMessage();

            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public function servers(): array
    {
        return $this->list($this->getClient()->request('GET', '/servers'));
    }

    public function resolveServer(array $server): ?array
    {
        $uuid = $server['uuid'] ?? $server['server_uuid'] ?? null;
        if (\is_string($uuid) && $uuid !== '') {
            return $this->serverWithLifecycleCreationTime($this->serverWithOwnershipMetadata($this->server($uuid)));
        }
        $identifiers = [];
        foreach (['ip', 'name'] as $key) {
            if (\is_string($server[$key] ?? null) && \trim($server[$key]) !== '') {
                $identifiers[$key] = $server[$key];
            }
        }
        if ($identifiers === []) {
            return null;
        }
        $servers = $this->list($this->getClient()->request('GET', '/servers'));
        foreach ($servers as $candidate) {
            $matches = true;
            foreach ($identifiers as $key => $value) {
                $candidateValue = $candidate[$key] ?? null;
                $matchesValue = $candidateValue === $value;
                if ($key === 'name' && \is_string($candidateValue) && \is_string($value)) {
                    $matchesValue = $matchesValue
                        || $candidateValue === 'shippercli-'.\substr($this->slug($value), 0, 38);
                }
                if (! $matchesValue) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $this->serverWithLifecycleCreationTime($this->serverWithOwnershipMetadata($candidate));
            }
        }

        return null;
    }

    public function createServer(array $spec): array
    {
        $cloudProvider = \strtolower((string) ($spec['cloud_provider'] ?? ''));
        if ($cloudProvider !== '') {
            $created = $this->provisionCloudServer($cloudProvider, $spec);
            $uuid = $this->resourceUuid($created);
            if ($uuid === '') {
                throw new \RuntimeException("Coolify {$cloudProvider} did not return a server UUID.");
            }
            $ownershipToken = $this->serverOwnershipToken($uuid, $cloudProvider);
            $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
            $signature = \hash_hmac('sha256', $uuid.'|'.$createdAt, $ownershipToken);
            $description = $this->cloudOwnershipMarker($ownershipToken).' [shippercli-created-at='.$createdAt.';signature='.$signature.']';
            try {
                $updated = $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($uuid), ['description' => $description]);
            } catch (\Throwable $exception) {
                try {
                    $this->getClient()->request('DELETE', '/servers/'.\rawurlencode($uuid).'?delete_from_provider=true');
                } catch (\Throwable) {
                    // Preserve the original marker-write error; the failed cleanup is reported as a possible cloud-resource leak.
                }
                throw new \RuntimeException(
                    "Coolify {$cloudProvider} server was provisioned but its Shipper ownership marker could not be saved; cleanup was attempted.",
                    0,
                    $exception,
                );
            }

            return [...$created, ...$updated, 'ownership_token' => $ownershipToken, 'server_record_only' => false, 'created_at' => $createdAt];
        }
        $name = $spec['name'] ?? null;
        $ip = $spec['ip'] ?? null;
        if (! \is_string($name) || \trim($name) === '' || ! \is_string($ip) || \trim($ip) === '') {
            throw new \InvalidArgumentException('Coolify server registration requires a name and IP address for an existing SSH host.');
        }
        if (isset($spec['user']) && (! \is_string($spec['user']) || \trim($spec['user']) === '')) {
            throw new \InvalidArgumentException('Coolify server user must be a non-empty string.');
        }
        if (isset($spec['port']) && (! \is_int($spec['port']) || $spec['port'] < 1 || $spec['port'] > 65535)) {
            throw new \InvalidArgumentException('Coolify server port must be an integer between 1 and 65535.');
        }
        if (isset($spec['server_role']) && ! \in_array($spec['server_role'], ['deployment', 'build', 'both'], true)) {
            throw new \InvalidArgumentException('Coolify server_role must be deployment, build, or both.');
        }
        if (isset($spec['proxy_type']) && ! \in_array($spec['proxy_type'], ['traefik', 'caddy', 'none'], true)) {
            throw new \InvalidArgumentException('Coolify proxy_type must be traefik, caddy, or none.');
        }
        if (isset($spec['instant_validate']) && ! \is_bool($spec['instant_validate'])) {
            throw new \InvalidArgumentException('Coolify instant_validate must be boolean.');
        }
        foreach (['description', 'private_key_uuid'] as $field) {
            if (isset($spec[$field]) && ! \is_string($spec[$field])) {
                throw new \InvalidArgumentException("Coolify server {$field} must be a string.");
            }
        }
        $payload = [
            'name' => 'shippercli-'.\substr($this->slug($name), 0, 38),
            'ip' => $ip,
            'user' => \is_string($spec['user'] ?? null) ? $spec['user'] : 'root',
            'port' => \is_int($spec['port'] ?? null) ? $spec['port'] : 22,
            'server_role' => \in_array($spec['server_role'] ?? null, ['deployment', 'build', 'both'], true) ? $spec['server_role'] : 'deployment',
            'proxy_type' => \in_array($spec['proxy_type'] ?? null, ['traefik', 'caddy', 'none'], true) ? $spec['proxy_type'] : 'traefik',
            'instant_validate' => \is_bool($spec['instant_validate'] ?? null) ? $spec['instant_validate'] : true,
        ];
        foreach (['description', 'private_key_uuid'] as $key) {
            if (\is_string($spec[$key] ?? null) && $spec[$key] !== '') {
                $payload[$key] = $spec[$key];
            }
        }
        $created = $this->getClient()->request('POST', '/servers', $payload);
        $uuid = $this->resourceUuid($created);
        if ($uuid === '') {
            throw new \RuntimeException('Coolify did not return a UUID for the registered server.');
        }

        return [
            ...$created,
            'ownership_token' => $this->serverOwnershipToken($uuid),
            'managed_by' => 'shippercli',
            'server_record_only' => true,
        ];
    }

    /** @param array<string, mixed> $spec
     *  @return array<string, mixed>
     */
    private function provisionCloudServer(string $provider, array $spec): array
    {
        if (isset($spec['cloud_init_script_uuid'])) {
            if (! \is_string($spec['cloud_init_script_uuid']) || \trim($spec['cloud_init_script_uuid']) === '') {
                throw new \InvalidArgumentException('Coolify cloud_init_script_uuid must be a non-empty string.');
            }
            if (isset($spec['cloud_init_script']) && $spec['cloud_init_script'] !== '') {
                throw new \InvalidArgumentException('Specify either cloud_init_script or cloud_init_script_uuid, not both.');
            }
            $savedScript = $this->cloudInitScript($spec['cloud_init_script_uuid']);
            if (! \is_string($savedScript['script'] ?? null) || \trim($savedScript['script']) === '') {
                throw new \RuntimeException('Coolify cloud-init script lookup returned no script content.');
            }
            $spec['cloud_init_script'] = $savedScript['script'];
        }

        $fields = match ($provider) {
            'hetzner' => [
                'cloud_provider_token_uuid', 'location', 'server_type', 'image', 'name', 'private_key_uuid',
                'enable_ipv4', 'enable_ipv6', 'enable_backups', 'hetzner_ssh_key_ids',
                'hetzner_firewall_ids', 'hetzner_network_ids', 'cloud_init_script', 'instant_validate',
            ],
            'digitalocean' => [
                'cloud_provider_token_uuid', 'region', 'size', 'image', 'name', 'private_key_uuid',
                'enable_ipv6', 'monitoring', 'digitalocean_ssh_key_ids', 'cloud_init_script', 'instant_validate',
            ],
            'vultr' => [
                'cloud_provider_token_uuid', 'region', 'plan', 'os_id', 'name', 'private_key_uuid',
                'disable_public_ipv4', 'enable_ipv6', 'vultr_ssh_key_ids', 'cloud_init_script', 'instant_validate',
            ],
            default => throw new \InvalidArgumentException("Coolify cloud provisioning does not support '{$provider}'."),
        };
        $payload = [];
        foreach ($fields as $field) {
            if (\array_key_exists($field, $spec)) {
                $payload[$field] = $spec[$field];
            }
        }
        $stringFields = ['cloud_provider_token_uuid', 'location', 'server_type', 'region', 'size', 'plan', 'name', 'private_key_uuid', 'cloud_init_script'];
        foreach ($stringFields as $field) {
            if (isset($payload[$field]) && ! \is_string($payload[$field])) {
                throw new \InvalidArgumentException("Coolify {$provider} server field '{$field}' must be a string.");
            }
        }
        $booleanFields = ['enable_ipv4', 'enable_ipv6', 'enable_backups', 'monitoring', 'disable_public_ipv4', 'instant_validate'];
        foreach ($booleanFields as $field) {
            if (isset($payload[$field]) && ! \is_bool($payload[$field])) {
                throw new \InvalidArgumentException("Coolify {$provider} server field '{$field}' must be boolean.");
            }
        }
        foreach (['image', 'os_id'] as $field) {
            if (isset($payload[$field]) && ! (\is_int($payload[$field]) || ($provider === 'digitalocean' && \is_string($payload[$field])))) {
                throw new \InvalidArgumentException("Coolify {$provider} server field '{$field}' has an invalid type.");
            }
        }
        foreach (['hetzner_ssh_key_ids', 'hetzner_firewall_ids', 'hetzner_network_ids', 'digitalocean_ssh_key_ids', 'vultr_ssh_key_ids'] as $field) {
            if (isset($payload[$field]) && (! \is_array($payload[$field]) || \array_filter($payload[$field], static fn (mixed $id): bool => ! \is_int($id) && ! (\is_string($id) && \ctype_digit($id))) !== [])) {
                throw new \InvalidArgumentException("Coolify {$provider} server field '{$field}' must be a list of numeric IDs.");
            }
        }
        $required = match ($provider) {
            'hetzner' => ['location', 'server_type', 'image', 'private_key_uuid'],
            'digitalocean' => ['region', 'size', 'image', 'private_key_uuid'],
            'vultr' => ['region', 'plan', 'os_id', 'private_key_uuid'],
        };
        foreach ($required as $field) {
            if (! \array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) {
                throw new \InvalidArgumentException("Coolify {$provider} provisioning requires '{$field}'.");
            }
        }
        if (! isset($payload['name']) || ! \is_string($payload['name']) || \trim($payload['name']) === '') {
            throw new \InvalidArgumentException("Coolify {$provider} provisioning requires a stable 'name' for Shipper to resolve the server idempotently.");
        }
        $payload['name'] = 'shippercli-'.\substr($this->slug($payload['name']), 0, 38);
        $created = $this->getClient()->request('POST', '/servers/'.$provider, $payload);
        $uuid = $this->resourceUuid($created);
        if ($uuid === '') {
            throw new \RuntimeException("Coolify {$provider} did not return a server UUID.");
        }

        return [
            ...$created,
            'ownership_token' => $this->serverOwnershipToken($uuid, $provider),
            'managed_by' => 'shippercli',
            'cloud_provider' => $provider,
            'cloud_instance_id' => $created[$provider.'_server_id'] ?? $created['server_id'] ?? null,
        ];
    }

    public function server(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId));
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public function updateServer(string $serverId, array $changes): array
    {
        $allowed = [
            'name', 'description', 'ip', 'port', 'user', 'private_key_uuid', 'server_role', 'instant_validate',
            'proxy_type', 'concurrent_builds', 'dynamic_timeout', 'deployment_queue_limit',
            'server_disk_usage_notification_threshold', 'server_disk_usage_check_frequency',
            'server_disk_usage_notification_interval_hours', 'connection_timeout',
        ];
        $stringFields = ['name', 'description', 'ip', 'user', 'private_key_uuid', 'server_disk_usage_check_frequency'];
        $integerFields = [
            'port', 'concurrent_builds', 'dynamic_timeout', 'deployment_queue_limit',
            'server_disk_usage_notification_threshold', 'server_disk_usage_notification_interval_hours', 'connection_timeout',
        ];
        foreach ($changes as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify server update field '{$field}'.");
            }
            if (\in_array($field, $stringFields, true) && ! \is_string($value)) {
                throw new \InvalidArgumentException("Coolify server field '{$field}' must be a string.");
            }
            if (\in_array($field, $integerFields, true) && ! \is_int($value)) {
                throw new \InvalidArgumentException("Coolify server field '{$field}' must be an integer.");
            }
            if ($field === 'instant_validate' && ! \is_bool($value)) {
                throw new \InvalidArgumentException('Coolify instant_validate must be boolean.');
            }
            if ($field === 'name' && \trim($value) === '') {
                throw new \InvalidArgumentException('Coolify server name must not be empty.');
            }
            if ($field === 'ip' && \trim($value) === '') {
                throw new \InvalidArgumentException('Coolify server IP must not be empty.');
            }
            if ($field === 'user' && \trim($value) === '') {
                throw new \InvalidArgumentException('Coolify server user must not be empty.');
            }
            if ($field === 'private_key_uuid' && \trim($value) === '') {
                throw new \InvalidArgumentException('Coolify private_key_uuid must not be empty.');
            }
            if ($field === 'server_role' && ! \in_array($value, ['deployment', 'build', 'both'], true)) {
                throw new \InvalidArgumentException('Coolify server_role must be deployment, build, or both.');
            }
            if ($field === 'proxy_type' && ! \in_array($value, ['traefik', 'caddy', 'none'], true)) {
                throw new \InvalidArgumentException('Coolify proxy_type must be traefik, caddy, or none.');
            }
            if ($field === 'port' && ($value < 1 || $value > 65535)) {
                throw new \InvalidArgumentException('Coolify server port must be between 1 and 65535.');
            }
            if ($field === 'connection_timeout' && ($value < 1 || $value > 300)) {
                throw new \InvalidArgumentException('Coolify connection_timeout must be between 1 and 300 seconds.');
            }
            if ($field === 'server_disk_usage_notification_threshold' && ($value < 1 || $value > 100)) {
                throw new \InvalidArgumentException('Coolify server disk usage notification threshold must be between 1 and 100 percent.');
            }
            if ($field === 'server_disk_usage_notification_interval_hours' && ($value < 1 || $value > 720)) {
                throw new \InvalidArgumentException('Coolify server disk usage notification interval must be between 1 and 720 hours.');
            }
            if (\in_array($field, ['concurrent_builds', 'dynamic_timeout', 'deployment_queue_limit'], true) && $value < 1) {
                throw new \InvalidArgumentException("Coolify server field '{$field}' must be at least 1.");
            }
        }
        if ($changes === []) {
            throw new \InvalidArgumentException('At least one Coolify server update field is required.');
        }

        return $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($serverId), $changes);
    }

    /** Start server validation; installation is opt-in because it can restart Docker.
     *  @return array<string, mixed>
     */
    public function validateServer(string $serverId, bool $install = false): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/validate', ['install' => $install]);
    }

    /** @return array<string, mixed> */
    public function restartServerProxy(string $serverId): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/proxy/restart');
    }

    /** @return list<array<string, mixed>> */
    public function serverResources(string $serverId): array
    {
        return $this->list($this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/resources'));
    }

    /** @return list<array{ip?: string, domains?: list<string>}> */
    public function serverDomains(string $serverId): array
    {
        return $this->list($this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/domains'));
    }

    /** @return array<string, mixed> */
    public function serverProxySettings(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/proxy');
    }

    /** @param array{redirect_enabled?: bool, redirect_url?: string|null, generate_exact_labels?: bool, proxy_type?: string} $settings
     *  @return array<string, mixed>
     */
    public function updateServerProxySettings(string $serverId, array $settings): array
    {
        $allowed = ['redirect_enabled', 'redirect_url', 'generate_exact_labels', 'proxy_type'];
        foreach ($settings as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify proxy setting '{$field}'.");
            }
            if (\in_array($field, ['redirect_enabled', 'generate_exact_labels'], true) && ! \is_bool($value)) {
                throw new \InvalidArgumentException("Coolify proxy setting '{$field}' must be boolean.");
            }
            if ($field === 'redirect_url' && $value !== null
                && (! \is_string($value) || \filter_var($value, FILTER_VALIDATE_URL) === false
                    || ! \in_array(\strtolower((string) \parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                throw new \InvalidArgumentException('Coolify redirect_url must be an absolute HTTP(S) URL or null.');
            }
            if ($field === 'proxy_type' && ! \in_array($value, ['traefik', 'caddy', 'nginx', 'none'], true)) {
                throw new \InvalidArgumentException('Coolify proxy_type must be traefik, caddy, nginx, or none.');
            }
        }
        if ($settings === []) {
            throw new \InvalidArgumentException('At least one Coolify proxy setting is required.');
        }

        return $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($serverId).'/proxy', $settings);
    }

    /** @return array<string, mixed> */
    public function saveServerProxyConfiguration(string $serverId, string $configuration): array
    {
        if (\trim($configuration) === '') {
            throw new \InvalidArgumentException('Coolify proxy configuration must not be empty.');
        }
        if (\str_contains($configuration, "\n") || \str_contains($configuration, "\r")) {
            $configuration = \base64_encode($configuration);
        }

        return $this->getClient()->request(
            'PUT',
            '/servers/'.\rawurlencode($serverId).'/proxy/configuration',
            ['configuration' => $configuration],
        );
    }

    /** @return array<string, mixed> May include sensitive settings, subject to token permissions. */
    public function serverSentinelSettings(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/sentinel');
    }

    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    public function updateServerSentinelSettings(string $serverId, array $settings): array
    {
        $booleans = ['is_metrics_enabled', 'is_sentinel_debug_enabled', 'is_geoip_enabled'];
        $strings = ['sentinel_token', 'sentinel_custom_url', 'geoip_maxmind_license_key'];
        $ranges = [
            'sentinel_metrics_refresh_rate_seconds' => [1, PHP_INT_MAX],
            'sentinel_metrics_history_days' => [1, PHP_INT_MAX],
            'sentinel_push_interval_seconds' => [10, PHP_INT_MAX],
            'traffic_topn' => [1, PHP_INT_MAX],
            'traffic_sample_threshold' => [0, PHP_INT_MAX],
            'traffic_retention_1h_days' => [1, PHP_INT_MAX],
            'traffic_retention_1d_days' => [1, PHP_INT_MAX],
            'geoip_refresh_days' => [1, PHP_INT_MAX],
        ];
        $allowed = [...$booleans, ...$strings, ...\array_keys($ranges)];
        foreach ($settings as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify Sentinel setting '{$field}'.");
            }
            if (\in_array($field, $booleans, true) && ! \is_bool($value)) {
                throw new \InvalidArgumentException("Coolify Sentinel setting '{$field}' must be boolean.");
            }
            if (\in_array($field, $strings, true) && $value !== null && ! \is_string($value)) {
                throw new \InvalidArgumentException("Coolify Sentinel setting '{$field}' must be a string or null.");
            }
            if (isset($ranges[$field]) && (! \is_int($value) || $value < $ranges[$field][0] || $value > $ranges[$field][1])) {
                throw new \InvalidArgumentException("Coolify Sentinel setting '{$field}' is outside its supported integer range.");
            }
        }
        if ($settings === []) {
            throw new \InvalidArgumentException('At least one Coolify Sentinel setting is required.');
        }

        return $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($serverId).'/sentinel', $settings);
    }

    /** Sensitive destination credentials may be redacted unless the token can read sensitive data. @return array<string, mixed> */
    public function serverLogDrainSettings(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/log-drains');
    }

    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    public function updateServerLogDrainSettings(string $serverId, array $settings): array
    {
        $booleans = ['is_logdrain_newrelic_enabled', 'is_logdrain_axiom_enabled', 'is_logdrain_custom_enabled'];
        $strings = [
            'logdrain_newrelic_license_key', 'logdrain_newrelic_base_uri', 'logdrain_axiom_dataset_name',
            'logdrain_axiom_api_key', 'logdrain_custom_config', 'logdrain_custom_config_parser',
        ];
        $allowed = [...$booleans, ...$strings];
        $enabledDestinations = 0;
        foreach ($settings as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify log drain setting '{$field}'.");
            }
            if (\in_array($field, $booleans, true)) {
                if (! \is_bool($value)) {
                    throw new \InvalidArgumentException("Coolify log drain setting '{$field}' must be boolean.");
                }
                $enabledDestinations += $value ? 1 : 0;
            } elseif (! \is_string($value)) {
                throw new \InvalidArgumentException("Coolify log drain setting '{$field}' must be a string.");
            }
        }
        if ($enabledDestinations > 1) {
            throw new \InvalidArgumentException('Coolify supports only one enabled server log drain destination at a time.');
        }
        if ($settings === []) {
            throw new \InvalidArgumentException('At least one Coolify log drain setting is required.');
        }
        if ($enabledDestinations === 1) {
            foreach ($booleans as $field) {
                if (($settings[$field] ?? false) !== true) {
                    $settings[$field] = false;
                }
            }
        }

        return $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($serverId).'/log-drains', $settings);
    }

    /** @return array<string, mixed> */
    public function serverCloudflareTunnelSettings(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/cloudflare-tunnel');
    }

    /** Update the stored tunnel flag only; this does not configure a remote cloudflared process. */
    public function updateServerCloudflareTunnelSetting(string $serverId, bool $enabled): array
    {
        return $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($serverId).'/cloudflare-tunnel', [
            'is_cloudflare_tunnel' => $enabled,
        ]);
    }

    /** Manually mark the tunnel enabled in Coolify; this does not deploy cloudflared remotely. */
    public function enableServerCloudflareTunnel(string $serverId): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/cloudflare-tunnel/enable');
    }

    /** Mark the tunnel disabled and restore the previous server IP when available; does not remove cloudflared. */
    public function disableServerCloudflareTunnel(string $serverId): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/cloudflare-tunnel/disable');
    }

    /** Export server and hosted resources; the returned bundle may contain sensitive configuration. @return array<string, mixed> */
    public function exportServerTransferBundle(string $serverId, bool $encrypt = false, ?string $passphrase = null): array
    {
        if ($encrypt && ($passphrase === null || $passphrase === '')) {
            throw new \InvalidArgumentException('Coolify encrypted server export requires a non-empty passphrase.');
        }
        $query = ['encrypt' => $encrypt];
        if ($passphrase !== null && $passphrase !== '') {
            $query['passphrase'] = $passphrase;
        }

        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/export?'.\http_build_query($query));
    }

    /** Export a bundle directly to the server mailbox for air-gapped transfer. */
    public function writeServerTransferBundleToMailbox(string $serverId, ?string $passphrase = null): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/export/mailbox', [
            'passphrase' => $passphrase,
        ]);
    }

    /** @param array<string, mixed> $bundle Plain or encrypted Coolify transfer bundle. @return array<string, mixed> */
    public function importServerTransferBundle(array $bundle, ?string $passphrase = null, bool $dryRun = false, bool $preserveUuids = true, bool $adoptMode = true, bool $claim = true, bool $writeRemote = false, bool $rebindSentinel = true): array
    {
        if ($bundle === []) {
            throw new \InvalidArgumentException('Coolify server import requires a non-empty transfer bundle.');
        }
        $payload = [
            'bundle' => $bundle,
            'passphrase' => $passphrase,
            'dry_run' => $dryRun,
            'preserve_uuids' => $preserveUuids,
            'adopt_mode' => $adoptMode,
            'claim' => $claim,
            'write_remote' => $writeRemote,
            'rebind_sentinel' => $rebindSentinel,
        ];

        return $this->getClient()->request('POST', '/servers/import', $payload);
    }

    /** Claim an imported server for this instance by writing its ownership marker and rebinding Sentinel. @return array<string, mixed> */
    public function claimImportedServer(string $serverId, bool $writeRemote = true, bool $rebindSentinel = true): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/claim', [
            'write_remote' => $writeRemote,
            'rebind_sentinel' => $rebindSentinel,
        ]);
    }

    /** Mark a successful transfer on the source instance, disabling its automations. @return array<string, mixed> */
    public function completeServerTransfer(string $serverId, ?string $exportId = null, ?string $targetInstanceUrl = null): array
    {
        $payload = [];
        if ($exportId !== null) {
            $payload['export_id'] = $exportId;
        }
        if ($targetInstanceUrl !== null) {
            if (\filter_var($targetInstanceUrl, FILTER_VALIDATE_URL) === false
                || ! \in_array(\strtolower((string) \parse_url($targetInstanceUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Coolify transfer target_instance_url must be an absolute HTTP(S) URL.');
            }
            $payload['target_instance_url'] = $targetInstanceUrl;
        }

        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/transfer/complete', $payload);
    }

    /** One-shot cross-instance server migration. The target token is sent only in this explicit request. */
    public function migrateServer(string $serverId, string $targetUrl, string $targetToken, bool $writeRemote = false, bool $rebindSentinel = true, bool $preserveUuids = true, bool $adoptMode = true): array
    {
        if (\filter_var($targetUrl, FILTER_VALIDATE_URL) === false
            || ! \in_array(\strtolower((string) \parse_url($targetUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Coolify migration target_url must be an absolute HTTP(S) URL.');
        }
        if (\trim($targetToken) === '') {
            throw new \InvalidArgumentException('Coolify migration requires the target instance API token.');
        }

        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/migrate', [
            'target_url' => $targetUrl,
            'target_token' => $targetToken,
            'write_remote' => $writeRemote,
            'rebind_sentinel' => $rebindSentinel,
            'preserve_uuids' => $preserveUuids,
            'adopt_mode' => $adoptMode,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function cloudServerCatalog(string $provider, string $catalog, ?string $cloudProviderTokenUuid = null): array
    {
        $catalogs = [
            'hetzner' => ['locations', 'server-types', 'images', 'ssh-keys', 'firewalls', 'networks'],
            'digitalocean' => ['regions', 'sizes', 'images', 'ssh-keys'],
            'vultr' => ['regions', 'plans', 'operating-systems', 'ssh-keys'],
        ];
        $provider = \strtolower($provider);
        if (! \in_array($catalog, $catalogs[$provider] ?? [], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify cloud catalog '{$provider}/{$catalog}'.");
        }
        $query = '';
        if ($cloudProviderTokenUuid !== null) {
            if (\trim($cloudProviderTokenUuid) === '') {
                throw new \InvalidArgumentException('Coolify cloud provider token UUID must not be empty.');
            }
            $query = '?'.\http_build_query(['cloud_provider_token_uuid' => $cloudProviderTokenUuid]);
        }

        return $this->list($this->getClient()->request('GET', '/'.$provider.'/'.$catalog.$query));
    }

    /** @return list<array<string, mixed>> */
    public function cloudProviderTokens(): array
    {
        return $this->list($this->getClient()->request('GET', '/cloud-tokens'));
    }

    /** @return array<string, mixed> */
    public function cloudProviderToken(string $tokenUuid): array
    {
        return $this->getClient()->request('GET', '/cloud-tokens/'.\rawurlencode($tokenUuid));
    }

    /** Create and validate a provider credential in Coolify. */
    public function createCloudProviderToken(string $provider, string $name, string $providerToken): array
    {
        $provider = \strtolower($provider);
        if (! \in_array($provider, ['hetzner', 'digitalocean', 'vultr'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify cloud token provider '{$provider}'.");
        }
        if (\trim($name) === '' || \trim($providerToken) === '') {
            throw new \InvalidArgumentException('Coolify cloud token creation requires a name and provider API token.');
        }

        return $this->getClient()->request('POST', '/cloud-tokens', [
            'provider' => $provider,
            'name' => $name,
            'token' => $providerToken,
        ]);
    }

    /** @return array<string, mixed> */
    public function updateCloudProviderToken(string $tokenUuid, string $name): array
    {
        if (\trim($tokenUuid) === '' || \trim($name) === '') {
            throw new \InvalidArgumentException('Coolify cloud provider token UUID and name are required.');
        }

        return $this->getClient()->request('PATCH', '/cloud-tokens/'.\rawurlencode($tokenUuid), ['name' => $name]);
    }

    public function validateCloudProviderToken(string $tokenUuid): array
    {
        return $this->getClient()->request('POST', '/cloud-tokens/'.\rawurlencode($tokenUuid).'/validate');
    }

    /** Coolify refuses deletion while the credential is attached to any server. @return array<string, mixed> */
    public function deleteCloudProviderToken(string $tokenUuid): array
    {
        return $this->getClient()->request('DELETE', '/cloud-tokens/'.\rawurlencode($tokenUuid));
    }

    /** Source integration responses may include sensitive metadata; avoid logging these values. @return list<array<string, mixed>> */
    public function githubApps(): array
    {
        return $this->list($this->getClient()->request('GET', '/github-apps'));
    }

    /** @param array<string, mixed> $application @return array<string, mixed> */
    public function createGithubApp(array $application): array
    {
        return $this->getClient()->request('POST', '/github-apps', $application);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateGithubApp(int $appId, array $changes): array
    {
        $this->assertPositiveIntegrationAppId($appId, 'GitHub');
        if ($changes === []) {
            throw new \InvalidArgumentException('Coolify GitHub app updates require at least one change.');
        }

        return $this->getClient()->request('PATCH', '/github-apps/'.$appId, $changes);
    }

    /** @return array<string, mixed> */
    public function deleteGithubApp(int $appId): array
    {
        $this->assertPositiveIntegrationAppId($appId, 'GitHub');

        return $this->getClient()->request('DELETE', '/github-apps/'.$appId);
    }

    /** @return array<string, mixed> */
    public function githubAppRepositories(int $appId): array
    {
        $this->assertPositiveIntegrationAppId($appId, 'GitHub');

        return $this->getClient()->request('GET', '/github-apps/'.$appId.'/repositories');
    }

    /** @return array<string, mixed> */
    public function githubAppRepositoryBranches(int $appId, string $owner, string $repository): array
    {
        $this->assertPositiveIntegrationAppId($appId, 'GitHub');
        if (\trim($owner) === '' || \trim($repository) === '') {
            throw new \InvalidArgumentException('Coolify GitHub branch lookup requires a repository owner and name.');
        }

        return $this->getClient()->request(
            'GET',
            '/github-apps/'.$appId.'/repositories/'.\rawurlencode($owner).'/'.\rawurlencode($repository).'/branches',
        );
    }

    /** @return list<array<string, mixed>> */
    public function gitlabApps(): array
    {
        return $this->list($this->getClient()->request('GET', '/gitlab-apps'));
    }

    /** @param array<string, mixed> $application @return array<string, mixed> */
    public function createGitlabApp(array $application): array
    {
        return $this->getClient()->request('POST', '/gitlab-apps', $application);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateGitlabApp(int $appId, array $changes): array
    {
        $this->assertPositiveIntegrationAppId($appId, 'GitLab');
        if ($changes === []) {
            throw new \InvalidArgumentException('Coolify GitLab app updates require at least one change.');
        }

        return $this->getClient()->request('PATCH', '/gitlab-apps/'.$appId, $changes);
    }

    /** @return array<string, mixed> */
    public function deleteGitlabApp(int $appId): array
    {
        $this->assertPositiveIntegrationAppId($appId, 'GitLab');

        return $this->getClient()->request('DELETE', '/gitlab-apps/'.$appId);
    }

    /** Team responses include member details; avoid exposing them in logs. @return list<array<string, mixed>> */
    public function teams(): array
    {
        return $this->list($this->getClient()->request('GET', '/teams'));
    }

    /** @return array<string, mixed> */
    public function team(int $teamId): array
    {
        if ($teamId < 1) {
            throw new \InvalidArgumentException('Coolify team ID must be a positive integer.');
        }

        return $this->getClient()->request('GET', '/teams/'.$teamId);
    }

    /** @return array<string, mixed> */
    public function authenticatedTeam(): array
    {
        return $this->getClient()->request('GET', '/team');
    }

    /** @return array<string, mixed> */
    public function currentTeam(): array
    {
        return $this->getClient()->request('GET', '/teams/current');
    }

    /** @return list<array<string, mixed>> */
    public function teamMembers(int $teamId): array
    {
        if ($teamId < 1) {
            throw new \InvalidArgumentException('Coolify team ID must be a positive integer.');
        }

        return $this->list($this->getClient()->request('GET', '/teams/'.$teamId.'/members'));
    }

    /** @return list<array<string, mixed>> */
    public function authenticatedTeamMembers(): array
    {
        return $this->list($this->getClient()->request('GET', '/team/members'));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateAuthenticatedTeam(array $changes): array
    {
        if ($changes === []) {
            throw new \InvalidArgumentException('Coolify team updates require at least one change.');
        }

        return $this->getClient()->request('PATCH', '/team', $changes);
    }

    /** Notification responses may contain encrypted secrets; handle them as sensitive data. @return array<string, mixed> */
    public function notificationSettings(string $channel): array
    {
        $channel = $this->notificationChannel($channel);

        return $this->getClient()->request('GET', '/notifications/'.$channel);
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public function updateNotificationSettings(string $channel, array $settings): array
    {
        if ($settings === []) {
            throw new \InvalidArgumentException('Coolify notification updates require at least one setting.');
        }
        $channel = $this->notificationChannel($channel);

        return $this->getClient()->request('PATCH', '/notifications/'.$channel, $settings);
    }

    /** @return string Raw Coolify health response (normally "OK"). */
    public function coolifyHealth(): string
    {
        return $this->getClient()->requestText('GET', '/health');
    }

    /** @return string Coolify version string. */
    public function coolifyVersion(): string
    {
        return $this->getClient()->requestText('GET', '/version');
    }

    /** Requires a root-permission API token. @return array<string, mixed> */
    public function enableApiAccess(): array
    {
        return $this->getClient()->request('POST', '/enable');
    }

    /** This can immediately disable subsequent API requests; requires a root-permission API token. @return array<string, mixed> */
    public function disableApiAccess(): array
    {
        return $this->getClient()->request('POST', '/disable');
    }

    /** Requires a root-permission API token. @return array<string, mixed> */
    public function enableMcpServer(): array
    {
        return $this->getClient()->request('POST', '/mcp/enable');
    }

    /** Requires a root-permission API token. @return array<string, mixed> */
    public function disableMcpServer(): array
    {
        return $this->getClient()->request('POST', '/mcp/disable');
    }

    /**
     * Read instance-wide SMTP and Resend settings. Requires a root-team
     * admin/owner token; sensitive fields require read:sensitive or root.
     * @return array<string, mixed>
     */
    public function instanceEmailSettings(): array
    {
        return $this->getClient()->request('GET', '/settings/email');
    }

    /**
     * Update instance-wide SMTP and Resend settings. Requires write:sensitive.
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateInstanceEmailSettings(array $settings): array
    {
        return $this->getClient()->request('PATCH', '/settings/email', $settings);
    }

    /** Cloud-init content may contain sensitive provisioning details; restrict access to trusted team members. @return list<array<string, mixed>> */
    public function cloudInitScripts(): array
    {
        return $this->list($this->getClient()->request('GET', '/cloud-init-scripts'));
    }

    /** @return array<string, mixed> */
    public function cloudInitScript(string $scriptUuid): array
    {
        return $this->getClient()->request('GET', '/cloud-init-scripts/'.\rawurlencode($scriptUuid));
    }

    /** @return array<string, mixed> */
    public function createCloudInitScript(string $name, string $script): array
    {
        if (\trim($name) === '' || \trim($script) === '') {
            throw new \InvalidArgumentException('Coolify cloud-init script creation requires a name and script content.');
        }

        return $this->getClient()->request('POST', '/cloud-init-scripts', ['name' => $name, 'script' => $script]);
    }

    /** @param array{name?: string, script?: string} $changes @return array<string, mixed> */
    public function updateCloudInitScript(string $scriptUuid, array $changes): array
    {
        if ($changes === []) {
            throw new \InvalidArgumentException('Coolify cloud-init script updates require at least one change.');
        }
        foreach ($changes as $field => $value) {
            if (! \in_array($field, ['name', 'script'], true) || ! \is_string($value) || \trim($value) === '') {
                throw new \InvalidArgumentException('Coolify cloud-init script updates accept non-empty name and/or script strings only.');
            }
        }

        return $this->getClient()->request('PATCH', '/cloud-init-scripts/'.\rawurlencode($scriptUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function deleteCloudInitScript(string $scriptUuid): array
    {
        return $this->getClient()->request('DELETE', '/cloud-init-scripts/'.\rawurlencode($scriptUuid));
    }

    /** Private-key listing may return the private key material; call only with appropriately scoped access. @return list<array<string, mixed>> */
    public function privateKeys(): array
    {
        return $this->list($this->getClient()->request('GET', '/security/keys'));
    }

    /** Private-key details can include private key material; call only with appropriately scoped access. @return array<string, mixed> */
    public function privateKey(string $keyUuid): array
    {
        return $this->getClient()->request('GET', '/security/keys/'.\rawurlencode($keyUuid));
    }

    /** Create a private key in Coolify for SSH server access. @return array<string, mixed> */
    public function createPrivateKey(string $privateKey, string $name = '', string $description = ''): array
    {
        if (\trim($privateKey) === '') {
            throw new \InvalidArgumentException('Coolify private key content must not be empty.');
        }
        $payload = ['private_key' => $privateKey];
        if ($name !== '') {
            $payload['name'] = $name;
        }
        if ($description !== '') {
            $payload['description'] = $description;
        }

        return $this->getClient()->request('POST', '/security/keys', $payload);
    }

    /** Update a private key; Coolify selects the key by the uuid query parameter. @return array<string, mixed> */
    public function updatePrivateKey(string $keyUuid, string $privateKey, ?string $name = null, ?string $description = null): array
    {
        if (\trim($keyUuid) === '' || \trim($privateKey) === '') {
            throw new \InvalidArgumentException('Coolify private-key updates require a key UUID and non-empty private key content.');
        }

        $payload = ['private_key' => $privateKey];
        if ($name !== null) {
            $payload['name'] = $name;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->getClient()->request(
            'PATCH',
            '/security/keys?'.\http_build_query(['uuid' => $keyUuid]),
            $payload,
        );
    }

    /** Coolify refuses deletion when a private key is still in use. @return array<string, mixed> */
    public function deletePrivateKey(string $keyUuid): array
    {
        return $this->getClient()->request('DELETE', '/security/keys/'.\rawurlencode($keyUuid));
    }

    /** @return list<array<string, mixed>> */
    public function s3Storages(): array
    {
        return $this->list($this->getClient()->request('GET', '/s3-storages'));
    }

    /** S3 credentials are sensitive; Coolify's detail response may redact them depending on token permissions. @return array<string, mixed> */
    public function s3Storage(string $storageUuid): array
    {
        return $this->getClient()->request('GET', '/s3-storages/'.\rawurlencode($storageUuid));
    }

    /** @param array<string, mixed> $storage @return array<string, mixed> */
    public function createS3Storage(array $storage): array
    {
        return $this->getClient()->request('POST', '/s3-storages', $storage);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateS3Storage(string $storageUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/s3-storages/'.\rawurlencode($storageUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function deleteS3Storage(string $storageUuid): array
    {
        return $this->getClient()->request('DELETE', '/s3-storages/'.\rawurlencode($storageUuid));
    }

    /** @return array<string, mixed> */
    public function validateS3Storage(string $storageUuid): array
    {
        return $this->getClient()->request('POST', '/s3-storages/'.\rawurlencode($storageUuid).'/validate');
    }

    /** Registry names and usernames are returned; credentials are never returned and access requires read:sensitive. @return array<string, mixed> */
    public function serverRegistries(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/registries');
    }

    /** Run docker login on the server. The password must be supplied by the caller and is never retained here. */
    public function loginServerRegistry(string $serverId, string $registry, string $username, string $password): array
    {
        foreach (['registry' => $registry, 'username' => $username, 'password' => $password] as $field => $value) {
            if (\trim($value) === '') {
                throw new \InvalidArgumentException("Coolify registry {$field} must not be empty.");
            }
        }
        if (\str_contains($registry, '/') || \str_contains($registry, '://')) {
            throw new \InvalidArgumentException('Coolify registry must be a registry host, optionally with a port, not a URL or image path.');
        }

        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/registries', [
            'registry' => $registry,
            'username' => $username,
            'password' => $password,
        ]);
    }

    /** Check whether the saved Docker registry login still works. */
    public function checkServerRegistryLogin(string $serverId, string $registry): array
    {
        if (\trim($registry) === '' || \str_contains($registry, '/') || \str_contains($registry, '://')) {
            throw new \InvalidArgumentException('Coolify registry must be a non-empty host, optionally with a port.');
        }

        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/registries/'.\rawurlencode($registry).'/check');
    }

    /** Run docker logout for the selected registry on the server. */
    public function logoutServerRegistry(string $serverId, string $registry): array
    {
        if (\trim($registry) === '' || \str_contains($registry, '/') || \str_contains($registry, '://')) {
            throw new \InvalidArgumentException('Coolify registry must be a non-empty host, optionally with a port.');
        }

        return $this->getClient()->request('DELETE', '/servers/'.\rawurlencode($serverId).'/registries/'.\rawurlencode($registry));
    }

    /** @return list<array<string, mixed>> */
    public function scheduledTasks(string $resourceType, string $resourceUuid): array
    {
        return $this->list($this->getClient()->request('GET', $this->scheduledTasksPath($resourceType, $resourceUuid)));
    }

    /** @param array{name: string, command: string, frequency: string, container?: string, timeout?: int, enabled?: bool} $task
     *  @return array<string, mixed>
     */
    public function createScheduledTask(string $resourceType, string $resourceUuid, array $task): array
    {
        $this->validateScheduledTaskPayload($task, true);

        return $this->getClient()->request('POST', $this->scheduledTasksPath($resourceType, $resourceUuid), $task);
    }

    /** @param array{name?: string, command?: string, frequency?: string, container?: string, timeout?: int, enabled?: bool} $changes
     *  @return array<string, mixed>
     */
    public function updateScheduledTask(string $resourceType, string $resourceUuid, string $taskUuid, array $changes): array
    {
        $this->validateScheduledTaskPayload($changes, false);
        if ($changes === []) {
            throw new \InvalidArgumentException('At least one Coolify scheduled-task field is required.');
        }

        return $this->getClient()->request('PATCH', $this->scheduledTasksPath($resourceType, $resourceUuid).'/'.\rawurlencode($taskUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function executeScheduledTask(string $resourceType, string $resourceUuid, string $taskUuid): array
    {
        return $this->getClient()->request('POST', $this->scheduledTasksPath($resourceType, $resourceUuid).'/'.\rawurlencode($taskUuid).'/execute');
    }

    /** @return list<array<string, mixed>> */
    public function scheduledTaskExecutions(string $resourceType, string $resourceUuid, string $taskUuid): array
    {
        return $this->list($this->getClient()->request('GET', $this->scheduledTasksPath($resourceType, $resourceUuid).'/'.\rawurlencode($taskUuid).'/executions'));
    }

    /** @return array<string, mixed> */
    public function deleteScheduledTask(string $resourceType, string $resourceUuid, string $taskUuid): array
    {
        return $this->getClient()->request('DELETE', $this->scheduledTasksPath($resourceType, $resourceUuid).'/'.\rawurlencode($taskUuid));
    }

    /** @return array<string, mixed> */
    /** Clone a Coolify service to another destination. @return array<string, mixed> */
    public function cloneService(string $serviceUuid, string $destinationUuid, ?string $name = null, bool $cloneVolumes = false): array
    {
        $this->assertResourceIdentifier($serviceUuid, 'service');
        $this->assertResourceIdentifier($destinationUuid, 'destination');
        if ($name !== null && \trim($name) === '') {
            throw new \InvalidArgumentException('Coolify cloned service name must not be empty.');
        }
        $payload = ['destination_uuid' => $destinationUuid, 'clone_volumes' => $cloneVolumes];
        if ($name !== null) {
            $payload['name'] = $name;
        }

        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/clone', $payload);
    }

    /** Migrate a Coolify service to another destination/server. @return array<string, mixed> */
    public function migrateService(string $serviceUuid, string $destinationUuid, bool $migrateVolumes = true): array
    {
        $this->assertResourceIdentifier($serviceUuid, 'service');
        $this->assertResourceIdentifier($destinationUuid, 'destination');

        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/migrate', [
            'destination_uuid' => $destinationUuid,
            'migrate_volumes' => $migrateVolumes,
        ]);
    }

    /** Move a Coolify service to another environment without deploying it. @return array<string, mixed> */
    public function moveService(string $serviceUuid, string $environmentUuid): array
    {
        $this->assertResourceIdentifier($serviceUuid, 'service');
        $this->assertResourceIdentifier($environmentUuid, 'environment');

        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/move', [
            'environment_uuid' => $environmentUuid,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function services(): array
    {
        return $this->list($this->getClient()->request('GET', '/services'));
    }

    public function serviceResource(string $serviceUuid): array
    {
        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateServiceResource(string $serviceUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/services/'.\rawurlencode($serviceUuid), $changes);
    }

    /** Delete a Coolify service with explicit configuration/data cleanup controls. @return array<string, mixed> */
    public function deleteServiceResource(string $serviceUuid, bool $deleteConfigurations = true, bool $deleteVolumes = true, bool $dockerCleanup = true, bool $deleteConnectedNetworks = true): array
    {
        return $this->deleteResource('services', $serviceUuid, $deleteConfigurations, $deleteVolumes, $dockerCleanup, $deleteConnectedNetworks);
    }

    /** @return array<string, mixed> */
    public function startService(string $serviceUuid): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/start');
    }

    /** @return array<string, mixed> */
    public function stopService(string $serviceUuid, bool $dockerCleanup = false): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/stop?docker_cleanup='.($dockerCleanup ? 'true' : 'false'));
    }

    /** @return array<string, mixed> */
    public function restartService(string $serviceUuid, bool $pullLatest = false): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/restart?latest='.($pullLatest ? 'true' : 'false'));
    }

    /** @return array<string, mixed> */
    public function serviceLogs(string $serviceUuid, string $subServiceName, int|string $lines = 100, bool $showTimestamps = false): array
    {
        if (\trim($subServiceName) === '') {
            throw new \InvalidArgumentException('Coolify service log retrieval requires a sub_service_name.');
        }
        if (! (($lines === 'all') || (\is_int($lines) && ($lines === -1 || $lines >= 1)))) {
            throw new \InvalidArgumentException("Coolify service log lines must be a positive integer, -1, or 'all'.");
        }
        $query = \http_build_query([
            'sub_service_name' => $subServiceName,
            'lines' => $lines,
            'show_timestamps' => $showTimestamps,
        ]);

        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/logs?'.$query);
    }

    /** @return array<string, mixed> */
    /** Clone a database resource to another Coolify destination. @return array<string, mixed> */
    public function cloneDatabase(string $databaseUuid, string $destinationUuid, ?string $name = null, bool $cloneVolumes = false): array
    {
        $this->assertResourceIdentifier($databaseUuid, 'database');
        $this->assertResourceIdentifier($destinationUuid, 'destination');
        if ($name !== null && \trim($name) === '') {
            throw new \InvalidArgumentException('Coolify cloned database name must not be empty.');
        }
        $payload = ['destination_uuid' => $destinationUuid, 'clone_volumes' => $cloneVolumes];
        if ($name !== null) {
            $payload['name'] = $name;
        }

        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/clone', $payload);
    }

    /** Migrate a database to another Coolify destination/server. @return array<string, mixed> */
    public function migrateDatabase(string $databaseUuid, string $destinationUuid, bool $migrateVolumes = true): array
    {
        $this->assertResourceIdentifier($databaseUuid, 'database');
        $this->assertResourceIdentifier($destinationUuid, 'destination');

        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/migrate', [
            'destination_uuid' => $destinationUuid,
            'migrate_volumes' => $migrateVolumes,
        ]);
    }

    /** Move a database resource to another environment without deploying it. @return array<string, mixed> */
    public function moveDatabase(string $databaseUuid, string $environmentUuid): array
    {
        $this->assertResourceIdentifier($databaseUuid, 'database');
        $this->assertResourceIdentifier($environmentUuid, 'environment');

        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/move', [
            'environment_uuid' => $environmentUuid,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function databases(): array
    {
        return $this->list($this->getClient()->request('GET', '/databases'));
    }

    public function databaseResource(string $databaseUuid): array
    {
        return $this->getClient()->request('GET', '/databases/'.\rawurlencode($databaseUuid));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateDatabaseResource(string $databaseUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/databases/'.\rawurlencode($databaseUuid), $changes);
    }

    /** Delete a Coolify database with explicit configuration/data cleanup controls. @return array<string, mixed> */
    public function deleteDatabaseResource(string $databaseUuid, bool $deleteConfigurations = true, bool $deleteVolumes = true, bool $dockerCleanup = true, bool $deleteConnectedNetworks = true): array
    {
        return $this->deleteResource('databases', $databaseUuid, $deleteConfigurations, $deleteVolumes, $dockerCleanup, $deleteConnectedNetworks);
    }

    /** @return array<string, mixed> */
    public function startDatabase(string $databaseUuid): array
    {
        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/start');
    }

    /** @return array<string, mixed> */
    public function stopDatabase(string $databaseUuid, bool $dockerCleanup = false): array
    {
        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/stop?docker_cleanup='.($dockerCleanup ? 'true' : 'false'));
    }

    /** @return array<string, mixed> */
    public function restartDatabase(string $databaseUuid): array
    {
        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/restart');
    }

    /** @return array<string, mixed> */
    public function databaseLogs(string $databaseUuid, int $lines = 100, bool $showTimestamps = false): array
    {
        if ($lines < 1) {
            throw new \InvalidArgumentException('Coolify database log lines must be a positive integer.');
        }

        return $this->getClient()->request('GET', '/databases/'.\rawurlencode($databaseUuid).'/logs?'.\http_build_query([
            'lines' => $lines,
            'show_timestamps' => $showTimestamps,
        ]));
    }

    /** Database env responses may contain secrets; Coolify permissions control whether values are returned. @return list<array<string, mixed>> */
    public function databaseEnvironmentVariables(string $databaseUuid): array
    {
        return $this->list($this->getClient()->request('GET', '/databases/'.\rawurlencode($databaseUuid).'/envs'));
    }

    /** @param array<string, mixed> $variable @return array<string, mixed> */
    public function createDatabaseEnvironmentVariable(string $databaseUuid, array $variable): array
    {
        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/envs', $variable);
    }

    /** @param array<string, mixed> $variable @return array<string, mixed> */
    public function updateDatabaseEnvironmentVariable(string $databaseUuid, array $variable): array
    {
        return $this->getClient()->request('PATCH', '/databases/'.\rawurlencode($databaseUuid).'/envs', $variable);
    }

    /** @param list<array<string, mixed>> $variables @return array<string, mixed> */
    public function updateDatabaseEnvironmentVariables(string $databaseUuid, array $variables): array
    {
        return $this->getClient()->request('PATCH', '/databases/'.\rawurlencode($databaseUuid).'/envs/bulk', ['data' => $variables]);
    }

    /** @return array<string, mixed> */
    public function deleteDatabaseEnvironmentVariable(string $databaseUuid, string $variableUuid): array
    {
        return $this->getClient()->request(
            'DELETE',
            '/databases/'.\rawurlencode($databaseUuid).'/envs/'.\rawurlencode($variableUuid),
        );
    }

    /** @return array<string, mixed> */
    public function databaseStorages(string $databaseUuid): array
    {
        return $this->getClient()->request('GET', '/databases/'.\rawurlencode($databaseUuid).'/storages');
    }

    /** @return list<array<string, mixed>> */
    public function resourceStorages(string $resourceType, string $resourceUuid): array
    {
        return $this->list($this->getClient()->request('GET', $this->resourceStoragePath($resourceType, $resourceUuid)));
    }

    /** @param array<string, mixed> $storage @return array<string, mixed> */
    public function createResourceStorage(string $resourceType, string $resourceUuid, array $storage): array
    {
        return $this->getClient()->request('POST', $this->resourceStoragePath($resourceType, $resourceUuid), $storage);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateResourceStorage(string $resourceType, string $resourceUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', $this->resourceStoragePath($resourceType, $resourceUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function deleteResourceStorage(string $resourceType, string $resourceUuid, string $storageUuid): array
    {
        return $this->getClient()->request(
            'DELETE',
            $this->resourceStoragePath($resourceType, $resourceUuid).'/'.\rawurlencode($storageUuid),
        );
    }

    /** @param array<string, mixed> $schedule @return array<string, mixed> */
    public function setResourceStorageBackupSchedule(string $resourceType, string $resourceUuid, string $storageUuid, array $schedule): array
    {
        return $this->getClient()->request(
            'PUT',
            $this->resourceStoragePath($resourceType, $resourceUuid).'/'.\rawurlencode($storageUuid).'/backups',
            $schedule,
        );
    }

    /** @return array<string, mixed> */
    public function deleteResourceStorageBackupSchedule(string $resourceType, string $resourceUuid, string $storageUuid): array
    {
        return $this->getClient()->request(
            'DELETE',
            $this->resourceStoragePath($resourceType, $resourceUuid).'/'.\rawurlencode($storageUuid).'/backups',
        );
    }

    /** @return array<string, mixed> */
    public function runResourceStorageBackup(string $resourceType, string $resourceUuid, string $storageUuid): array
    {
        return $this->getClient()->request(
            'POST',
            $this->resourceStoragePath($resourceType, $resourceUuid).'/'.\rawurlencode($storageUuid).'/backups/run',
        );
    }

    /** @return array<string, mixed> */
    public function databaseBackups(string $databaseUuid): array
    {
        return $this->getClient()->request('GET', '/databases/'.\rawurlencode($databaseUuid).'/backups');
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public function createDatabaseBackup(string $databaseUuid, array $configuration): array
    {
        return $this->getClient()->request('POST', '/databases/'.\rawurlencode($databaseUuid).'/backups', $configuration);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateDatabaseBackup(string $databaseUuid, string $backupUuid, array $changes): array
    {
        return $this->getClient()->request(
            'PATCH',
            '/databases/'.\rawurlencode($databaseUuid).'/backups/'.\rawurlencode($backupUuid),
            $changes,
        );
    }

    /** @return array<string, mixed> */
    public function triggerDatabaseBackup(string $databaseUuid, string $backupUuid): array
    {
        return $this->updateDatabaseBackup($databaseUuid, $backupUuid, ['backup_now' => true]);
    }

    /** @return array<string, mixed> */
    public function deleteDatabaseBackup(string $databaseUuid, string $backupUuid, bool $deleteS3 = false): array
    {
        $path = '/databases/'.rawurlencode($databaseUuid).'/backups/'.rawurlencode($backupUuid);

        return $this->getClient()->request('DELETE', $path.'?delete_s3='.($deleteS3 ? 'true' : 'false'));
    }

    public function databaseBackupExecutions(string $databaseUuid, string $backupUuid): array
    {
        return $this->getClient()->request(
            'GET',
            '/databases/'.\rawurlencode($databaseUuid).'/backups/'.\rawurlencode($backupUuid).'/executions',
        );
    }

    /** @return array<string, mixed> */
    public function deleteDatabaseBackupExecution(string $databaseUuid, string $backupUuid, string $executionUuid, bool $deleteS3 = false): array
    {
        $path = '/databases/'.rawurlencode($databaseUuid).'/backups/'.rawurlencode($backupUuid).'/executions/'.rawurlencode($executionUuid);

        return $this->getClient()->request('DELETE', $path.'?delete_s3='.($deleteS3 ? 'true' : 'false'));
    }

    public function runDatabaseStorageBackup(string $databaseUuid, string $storageUuid): array
    {
        return $this->getClient()->request(
            'POST',
            '/databases/'.\rawurlencode($databaseUuid).'/storages/'.\rawurlencode($storageUuid).'/backups/run',
        );
    }

    /** @param array<string, mixed> $schedule @return array<string, mixed> */
    public function configureStorageBackup(string $resourceType, string $resourceUuid, string $storageUuid, array $schedule): array
    {
        $path = $this->storageBackupPath($resourceType, $resourceUuid, $storageUuid);
        $this->validateStorageBackupSchedule($schedule);

        return $this->getClient()->request('PUT', $path, $schedule);
    }

    /** @return array<string, mixed> */
    public function runStorageBackup(string $resourceType, string $resourceUuid, string $storageUuid): array
    {
        return $this->getClient()->request('POST', $this->storageBackupPath($resourceType, $resourceUuid, $storageUuid).'/run');
    }

    /** @return array<string, mixed> */
    public function deleteStorageBackupSchedule(string $resourceType, string $resourceUuid, string $storageUuid): array
    {
        return $this->getClient()->request('DELETE', $this->storageBackupPath($resourceType, $resourceUuid, $storageUuid));
    }

    private function storageBackupPath(string $resourceType, string $resourceUuid, string $storageUuid): string
    {
        if (! \in_array($resourceType, ['applications', 'services', 'databases'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify storage-backup resource type '{$resourceType}'.");
        }
        if (\trim($resourceUuid) === '' || \trim($storageUuid) === '') {
            throw new \InvalidArgumentException('Coolify storage-backup resource and storage UUIDs must not be empty.');
        }

        return '/'.$resourceType.'/'.\rawurlencode($resourceUuid).'/storages/'.\rawurlencode($storageUuid).'/backups';
    }

    /** @param array<string, mixed> $schedule */
    private function validateStorageBackupSchedule(array $schedule): void
    {
        $allowed = [
            'frequency', 'enabled', 'save_s3', 'disable_local_backup', 'stop_during_backup', 's3_storage_uuid',
            'retention_amount_locally', 'retention_days_locally', 'retention_max_storage_locally',
            'retention_amount_s3', 'retention_days_s3', 'retention_max_storage_s3', 'timeout',
        ];
        foreach ($schedule as $field => $value) {
            if (! \is_string($field) || ! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify storage-backup schedule option '{$field}'.");
            }
            if (\in_array($field, ['enabled', 'save_s3', 'disable_local_backup', 'stop_during_backup'], true) && ! \is_bool($value)) {
                throw new \InvalidArgumentException("Coolify storage-backup option '{$field}' must be boolean.");
            }
            if ($field === 'frequency' && (! \is_string($value) || \trim($value) === '' || \strlen($value) > 255)) {
                throw new \InvalidArgumentException('Coolify storage-backup frequency must be a non-empty string of at most 255 characters.');
            }
            if ($field === 's3_storage_uuid' && $value !== null && (! \is_string($value) || \trim($value) === '')) {
                throw new \InvalidArgumentException('Coolify storage-backup s3_storage_uuid must be a non-empty string or null.');
            }
            if (\str_starts_with($field, 'retention_')) {
                $isMaxStorage = \str_ends_with($field, '_max_storage_locally') || \str_ends_with($field, '_max_storage_s3');
                $valid = $isMaxStorage
                    ? (\is_int($value) || \is_float($value)) && $value >= 0 && $value <= 9999999999
                    : \is_int($value) && $value >= 0 && $value <= 2147483647;
                if (! $valid) {
                    throw new \InvalidArgumentException("Coolify storage-backup option '{$field}' must be a non-negative number within the API limit.");
                }
            }
            if ($field === 'timeout' && (! \is_int($value) || $value < 60 || $value > 36000)) {
                throw new \InvalidArgumentException('Coolify storage-backup timeout must be between 60 and 36000 seconds.');
            }
        }
        if (! isset($schedule['frequency']) || ! \is_string($schedule['frequency']) || \trim($schedule['frequency']) === '') {
            throw new \InvalidArgumentException('Coolify storage-backup schedule requires a frequency.');
        }
        if (($schedule['save_s3'] ?? false) === true
            && (! \is_string($schedule['s3_storage_uuid'] ?? null) || \trim($schedule['s3_storage_uuid']) === '')) {
            throw new \InvalidArgumentException('Coolify storage-backup schedules with save_s3 enabled require s3_storage_uuid.');
        }
    }

    /** @return array<string, mixed> */
    public function uploadDatabaseImport(string $databaseUuid, string $filePath): array
    {
        return $this->getClient()->requestMultipart(
            'POST',
            '/databases/'.\rawurlencode($databaseUuid).'/imports/uploads',
            [],
            ['file' => $filePath],
        );
    }

    /** @param array<string, mixed> $import @return array<string, mixed> */
    public function createDatabaseImport(string $databaseUuid, array $import): array
    {
        $this->validateDatabaseImportPayload($import);

        return $this->getClient()->request(
            'POST',
            '/databases/'.\rawurlencode($databaseUuid).'/imports',
            $import,
        );
    }

    /** @return array<string, mixed> */
    public function databaseImportStatus(string $databaseUuid, int $activityId): array
    {
        if ($activityId < 0) {
            throw new \InvalidArgumentException('Coolify database import activity IDs must be non-negative.');
        }

        return $this->getClient()->request(
            'GET',
            '/databases/'.\rawurlencode($databaseUuid).'/imports/'.$activityId,
        );
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public function configureApplicationSecretManager(string $applicationUuid, string $integrationTokenUuid, array $settings = []): array
    {
        $this->assertResourceIdentifier($applicationUuid, 'application');
        if ($settings !== [] && (\array_is_list($settings)
            || \array_filter(\array_keys($settings), static fn (mixed $key): bool => ! \is_string($key)) !== [])) {
            throw new \InvalidArgumentException('Coolify secret manager settings must be an object-like map.');
        }
        if (\trim($integrationTokenUuid) === '') {
            throw new \InvalidArgumentException('Coolify secret-manager configuration requires an integration token UUID.');
        }

        return $this->getClient()->request(
            'PATCH',
            '/applications/'.\rawurlencode($applicationUuid).'/secret-manager',
            ['integration_token_uuid' => $integrationTokenUuid, 'settings' => $settings],
        );
    }

    /** @return array<string, mixed> */
    public function applicationEnvironmentVariables(string $applicationUuid): array
    {
        return $this->getClient()->request('GET', '/applications/'.\rawurlencode($applicationUuid).'/envs');
    }

    /** @param array<string, mixed> $variable @return array<string, mixed> */
    public function upsertApplicationEnvironmentVariable(string $applicationUuid, array $variable): array
    {
        return $this->getClient()->request('PATCH', '/applications/'.\rawurlencode($applicationUuid).'/envs', $variable);
    }

    /** @param list<array<string, mixed>> $variables @return array<string, mixed> */
    public function upsertApplicationEnvironmentVariables(string $applicationUuid, array $variables): array
    {
        return $this->getClient()->request('PATCH', '/applications/'.\rawurlencode($applicationUuid).'/envs/bulk', [
            'data' => $variables,
        ]);
    }

    /** @return array<string, mixed> */
    public function deleteApplicationEnvironmentVariable(string $applicationUuid, string $key): array
    {
        return $this->getClient()->request(
            'DELETE',
            '/applications/'.\rawurlencode($applicationUuid).'/envs/'.\rawurlencode($key),
        );
    }

    /** @return array<string, mixed> */
    public function serviceEnvironmentVariables(string $serviceUuid): array
    {
        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/envs');
    }

    /** @param array<string, mixed> $variable @return array<string, mixed> */
    public function createServiceEnvironmentVariable(string $serviceUuid, array $variable): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/envs', $variable);
    }

    /** @param array<string, mixed> $variable @return array<string, mixed> */
    /** @param list<array<string, mixed>> $variables @return array<array<string, mixed>> */
    public function updateServiceEnvironmentVariables(string $serviceUuid, array $variables): array
    {
        if (! array_is_list($variables) || $variables === []) {
            throw new \InvalidArgumentException('Service environment variables must be a non-empty list.');
        }

        foreach ($variables as $index => $variable) {
            if (! is_array($variable) || ! is_string($variable['key'] ?? null) || trim($variable['key']) === ''
                || ! is_string($variable['value'] ?? null)) {
                throw new \InvalidArgumentException("Service environment variable at index {$index} requires a non-empty key and string value.");
            }
        }

        return $this->getClient()->request(
            'PATCH',
            '/services/'.rawurlencode($serviceUuid).'/envs/bulk',
            ['data' => $variables],
        );
    }

    public function updateServiceEnvironmentVariable(string $serviceUuid, array $variable): array
    {
        return $this->getClient()->request('PATCH', '/services/'.\rawurlencode($serviceUuid).'/envs', $variable);
    }

    /** @return array<string, mixed> */
    public function deleteServiceEnvironmentVariable(string $serviceUuid, string $variableUuid): array
    {
        return $this->getClient()->request(
            'DELETE',
            '/services/'.\rawurlencode($serviceUuid).'/envs/'.\rawurlencode($variableUuid),
        );
    }

    /** @return array<string, mixed> */
    public function sharedEnvironmentVariables(string $scope, ?string $resourceUuid = null, ?string $environment = null): array
    {
        return $this->getClient()->request('GET', $this->sharedEnvironmentPath($scope, $resourceUuid, $environment));
    }

    /** @param array<string, mixed> $variable @return array<string, mixed> */
    public function createSharedEnvironmentVariable(string $scope, array $variable, ?string $resourceUuid = null, ?string $environment = null): array
    {
        $this->validateSharedEnvironmentVariablePayload($variable, true);

        return $this->getClient()->request('POST', $this->sharedEnvironmentPath($scope, $resourceUuid, $environment), $variable);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateSharedEnvironmentVariable(string $scope, string $variableId, array $changes, ?string $resourceUuid = null, ?string $environment = null): array
    {
        $this->assertResourceIdentifier($variableId, 'shared environment variable');
        $this->validateSharedEnvironmentVariablePayload($changes, false);
        if ($changes === []) {
            throw new \InvalidArgumentException('At least one Coolify shared environment-variable field is required.');
        }
        $path = $this->sharedEnvironmentPath($scope, $resourceUuid, $environment).'/'.\rawurlencode($variableId);

        return $this->getClient()->request('PATCH', $path, $changes);
    }

    /** @return array<string, mixed> */
    public function deleteSharedEnvironmentVariable(string $scope, string $variableId, ?string $resourceUuid = null, ?string $environment = null): array
    {
        $this->assertResourceIdentifier($variableId, 'shared environment variable');
        $path = $this->sharedEnvironmentPath($scope, $resourceUuid, $environment).'/'.\rawurlencode($variableId);

        return $this->getClient()->request('DELETE', $path);
    }

    /** @param array<string, mixed> $payload */
    private function validateSharedEnvironmentVariablePayload(array $payload, bool $creating): void
    {
        $allowed = ['key', 'value', 'is_literal', 'is_multiline', 'is_shown_once', 'comment'];
        foreach ($payload as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify shared environment-variable field '{$field}'.");
            }
            if ($field === 'key' && (! \is_string($value) || \trim($value) === '')) {
                throw new \InvalidArgumentException('Coolify shared environment-variable key must be a non-empty string.');
            }
            if ($field === 'value' && $value !== null && ! \is_string($value)) {
                throw new \InvalidArgumentException('Coolify shared environment-variable value must be a string or null.');
            }
            if (\in_array($field, ['is_literal', 'is_multiline', 'is_shown_once'], true) && ! \is_bool($value)) {
                throw new \InvalidArgumentException("Coolify shared environment-variable field '{$field}' must be boolean.");
            }
            if ($field === 'comment' && $value !== null && ! \is_string($value)) {
                throw new \InvalidArgumentException('Coolify shared environment-variable comment must be a string or null.');
            }
        }
        if ($creating && (! \is_string($payload['key'] ?? null) || \trim($payload['key']) === '')) {
            throw new \InvalidArgumentException('Coolify shared environment-variable creation requires a non-empty key.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function tags(): array
    {
        return $this->list($this->getClient()->request('GET', '/tags'));
    }

    /** @return array<string, mixed> */
    public function tag(string $tagUuid): array
    {
        return $this->getClient()->request('GET', '/tags/'.\rawurlencode($tagUuid));
    }

    /** @param array<string, mixed> $tag @return array<string, mixed> */
    public function createTag(array $tag): array
    {
        return $this->getClient()->request('POST', '/tags', $tag);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateTag(string $tagUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/tags/'.\rawurlencode($tagUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function deleteTag(string $tagUuid): array
    {
        return $this->getClient()->request('DELETE', '/tags/'.\rawurlencode($tagUuid));
    }

    /** @return list<array<string, mixed>> */
    public function resourceTags(string $resourceType, string $resourceUuid): array
    {
        return $this->list($this->getClient()->request('GET', $this->taggableResourcePath($resourceType, $resourceUuid).'/tags'));
    }

    /** @param list<string> $tagNames @return list<array<string, mixed>> */
    public function addResourceTags(string $resourceType, string $resourceUuid, array $tagNames): array
    {
        return $this->list($this->getClient()->request(
            'POST',
            $this->taggableResourcePath($resourceType, $resourceUuid).'/tags',
            ['tag_names' => $tagNames],
        ));
    }

    /** @return array<string, mixed> */
    public function removeResourceTag(string $resourceType, string $resourceUuid, string $tagUuid): array
    {
        return $this->getClient()->request(
            'DELETE',
            $this->taggableResourcePath($resourceType, $resourceUuid).'/tags/'.\rawurlencode($tagUuid),
        );
    }

    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        return $this->list($this->getClient()->request('GET', '/projects'));
    }

    /** @return array<string, mixed> */
    public function project(string $projectUuid): array
    {
        return $this->getClient()->request('GET', '/projects/'.\rawurlencode($projectUuid));
    }

    /** @param array<string, mixed> $project @return array<string, mixed> */
    public function createProject(array $project): array
    {
        return $this->getClient()->request('POST', '/projects', $project);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateProject(string $projectUuid, array $changes): array
    {
        return $this->getClient()->request('PATCH', '/projects/'.\rawurlencode($projectUuid), $changes);
    }

    /** @return array<string, mixed> */
    public function deleteProjectResource(string $projectUuid): array
    {
        return $this->getClient()->request('DELETE', '/projects/'.\rawurlencode($projectUuid));
    }

    /** @return list<array<string, mixed>> */
    public function environments(string $projectUuid): array
    {
        return $this->list($this->getClient()->request('GET', '/projects/'.\rawurlencode($projectUuid).'/environments'));
    }

    /** @return array<string, mixed> */
    public function environment(string $projectUuid, string $environmentNameOrUuid): array
    {
        return $this->getClient()->request(
            'GET',
            '/projects/'.\rawurlencode($projectUuid).'/'.\rawurlencode($environmentNameOrUuid),
        );
    }

    /** @param array<string, mixed> $environment @return array<string, mixed> */
    public function createEnvironment(string $projectUuid, array $environment): array
    {
        return $this->getClient()->request('POST', '/projects/'.\rawurlencode($projectUuid).'/environments', $environment);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateEnvironment(string $projectUuid, string $environmentNameOrUuid, array $changes): array
    {
        return $this->getClient()->request(
            'PATCH',
            '/projects/'.\rawurlencode($projectUuid).'/environments/'.\rawurlencode($environmentNameOrUuid),
            $changes,
        );
    }

    /** @return array<string, mixed> */
    public function deleteEnvironment(string $projectUuid, string $environmentNameOrUuid): array
    {
        return $this->getClient()->request(
            'DELETE',
            '/projects/'.\rawurlencode($projectUuid).'/environments/'.\rawurlencode($environmentNameOrUuid),
        );
    }

    private function resourceStoragePath(string $resourceType, string $resourceUuid): string
    {
        if (! \in_array($resourceType, ['applications', 'services', 'databases'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify resource type '{$resourceType}' for storage management.");
        }
        if (\trim($resourceUuid) === '') {
            throw new \InvalidArgumentException('Coolify storage resource UUID must not be empty.');
        }

        return '/'.$resourceType.'/'.\rawurlencode($resourceUuid).'/storages';
    }

    private function deleteResource(string $resourceType, string $resourceUuid, bool $deleteConfigurations, bool $deleteVolumes, bool $dockerCleanup, bool $deleteConnectedNetworks): array
    {
        if (! \in_array($resourceType, ['applications', 'services', 'databases'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify resource type '{$resourceType}' for deletion.");
        }
        if (\trim($resourceUuid) === '') {
            throw new \InvalidArgumentException('Coolify resource UUID must not be empty.');
        }
        $query = \http_build_query([
            'delete_configurations' => $deleteConfigurations,
            'delete_volumes' => $deleteVolumes,
            'docker_cleanup' => $dockerCleanup,
            'delete_connected_networks' => $deleteConnectedNetworks,
        ]);

        return $this->getClient()->request('DELETE', '/'.$resourceType.'/'.\rawurlencode($resourceUuid).'?'.$query);
    }

    private function taggableResourcePath(string $resourceType, string $resourceUuid): string
    {
        if (! \in_array($resourceType, ['applications', 'services', 'databases'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify taggable resource type '{$resourceType}'.");
        }
        if (\trim($resourceUuid) === '') {
            throw new \InvalidArgumentException('Coolify taggable resource UUID must not be empty.');
        }

        return '/'.$resourceType.'/'.\rawurlencode($resourceUuid);
    }

    private function sharedEnvironmentPath(string $scope, ?string $resourceUuid, ?string $environment): string
    {
        return match ($scope) {
            'team' => '/team/envs',
            'project' => '/projects/'.\rawurlencode($this->requiredSharedEnvironmentIdentifier($resourceUuid, 'project UUID')).'/envs',
            'environment' => '/projects/'.\rawurlencode($this->requiredSharedEnvironmentIdentifier($resourceUuid, 'project UUID'))
                .'/environments/'.\rawurlencode($this->requiredSharedEnvironmentIdentifier($environment, 'environment name or UUID')).'/envs',
            'server' => '/servers/'.\rawurlencode($this->requiredSharedEnvironmentIdentifier($resourceUuid, 'server UUID')).'/envs',
            default => throw new \InvalidArgumentException("Unsupported Coolify shared environment variable scope '{$scope}'."),
        };
    }

    private function requiredSharedEnvironmentIdentifier(?string $identifier, string $label): string
    {
        if ($identifier === null || \trim($identifier) === '') {
            throw new \InvalidArgumentException("Coolify shared environment variables require a {$label}.");
        }

        return $identifier;
    }

    /** @return list<array<string, mixed>> */
    public function serviceDatabases(string $serviceUuid): array
    {
        return $this->list($this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/databases'));
    }

    /** @return array<string, mixed> */
    public function serviceDatabase(string $serviceUuid, string $databaseUuid): array
    {
        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid));
    }

    /** @return array<string, mixed> */
    public function startServiceDatabase(string $serviceUuid, string $databaseUuid, bool $force = false, bool $latest = false): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/start?'.\http_build_query([
            'force' => $force,
            'latest' => $latest,
        ]));
    }

    /** @return array<string, mixed> */
    public function stopServiceDatabase(string $serviceUuid, string $databaseUuid): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/stop');
    }

    /** @return array<string, mixed> */
    public function restartServiceDatabase(string $serviceUuid, string $databaseUuid): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/restart');
    }

    /** @return array<string, mixed> */
    public function serviceDatabaseLogs(string $serviceUuid, string $databaseUuid, int|string $lines = 100): array
    {
        if (! (($lines === 'all') || (\is_int($lines) && ($lines === -1 || $lines >= 1)))) {
            throw new \InvalidArgumentException("Coolify service database log lines must be a positive integer, -1, or 'all'.");
        }

        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/logs?'.\http_build_query(['lines' => $lines]));
    }

    /** @return array<string, mixed> */
    public function uploadServiceDatabaseImport(string $serviceUuid, string $databaseUuid, string $filePath): array
    {
        return $this->getClient()->requestMultipart(
            'POST',
            '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/imports/uploads',
            [],
            ['file' => $filePath],
        );
    }

    /** @param array<string, mixed> $import @return array<string, mixed> */
    public function createServiceDatabaseImport(string $serviceUuid, string $databaseUuid, array $import): array
    {
        $this->validateDatabaseImportPayload($import);

        return $this->getClient()->request(
            'POST',
            '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/imports',
            $import,
        );
    }

    /** @return array<string, mixed> */
    public function serviceDatabaseImportStatus(string $serviceUuid, string $databaseUuid, int $activityId): array
    {
        if ($activityId < 0) {
            throw new \InvalidArgumentException('Coolify service database import activity IDs must be non-negative.');
        }

        return $this->getClient()->request(
            'GET',
            '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/imports/'.$activityId,
        );
    }

    /** @return list<array<string, mixed>> */
    public function serviceApplications(string $serviceUuid): array
    {
        return $this->list($this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/applications'));
    }

    /** @return array<string, mixed> */
    public function serviceApplication(string $serviceUuid, string $applicationUuid): array
    {
        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/applications/'.\rawurlencode($applicationUuid));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateServiceApplication(string $serviceUuid, string $applicationUuid, array $changes): array
    {
        return $this->getClient()->request(
            'PATCH',
            '/services/'.\rawurlencode($serviceUuid).'/applications/'.\rawurlencode($applicationUuid),
            $changes,
        );
    }

    /** Start/redeploy a Compose application container, optionally forcing rebuild and image pull. @return array<string, mixed> */
    public function startServiceApplication(string $serviceUuid, string $applicationUuid, bool $force = false, bool $latest = false): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/applications/'.\rawurlencode($applicationUuid).'/start?'.\http_build_query([
            'force' => $force,
            'latest' => $latest,
        ]));
    }

    /** @return array<string, mixed> */
    public function stopServiceApplication(string $serviceUuid, string $applicationUuid): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/applications/'.\rawurlencode($applicationUuid).'/stop');
    }

    /** @return array<string, mixed> */
    public function restartServiceApplication(string $serviceUuid, string $applicationUuid): array
    {
        return $this->getClient()->request('POST', '/services/'.\rawurlencode($serviceUuid).'/applications/'.\rawurlencode($applicationUuid).'/restart');
    }

    /** @return array<string, mixed> */
    public function serviceApplicationLogs(string $serviceUuid, string $applicationUuid, int|string $lines = 100): array
    {
        if (! (($lines === 'all') || (\is_int($lines) && ($lines === -1 || $lines >= 1)))) {
            throw new \InvalidArgumentException("Coolify service application log lines must be a positive integer, -1, or 'all'.");
        }

        return $this->getClient()->request('GET', '/services/'.\rawurlencode($serviceUuid).'/applications/'.\rawurlencode($applicationUuid).'/logs?'.\http_build_query(['lines' => $lines]));
    }

    /** @return array<string, mixed> */
    public function serverDockerCleanupSettings(string $serverId): array
    {
        return $this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/docker-cleanup');
    }

    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    public function updateServerDockerCleanupSettings(string $serverId, array $settings): array
    {
        $booleans = ['force_docker_cleanup', 'delete_unused_volumes', 'delete_unused_networks', 'disable_application_image_retention'];
        $allowed = [...$booleans, 'docker_cleanup_frequency', 'docker_cleanup_threshold'];
        foreach ($settings as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify Docker cleanup setting '{$field}'.");
            }
            if (\in_array($field, $booleans, true) && ! \is_bool($value)) {
                throw new \InvalidArgumentException("Coolify Docker cleanup setting '{$field}' must be boolean.");
            }
            if ($field === 'docker_cleanup_frequency' && (! \is_string($value) || \trim($value) === '')) {
                throw new \InvalidArgumentException('Coolify docker_cleanup_frequency must be a non-empty cron or human-readable schedule.');
            }
            if ($field === 'docker_cleanup_threshold' && (! \is_int($value) || $value < 1 || $value > 99)) {
                throw new \InvalidArgumentException('Coolify docker_cleanup_threshold must be an integer between 1 and 99.');
            }
        }
        if ($settings === []) {
            throw new \InvalidArgumentException('At least one Coolify Docker cleanup setting is required.');
        }

        return $this->getClient()->request('PATCH', '/servers/'.\rawurlencode($serverId).'/docker-cleanup', $settings);
    }

    /** Explicitly queue server-wide Docker cleanup; deleting unused volumes can remove persistent data. */
    public function runServerDockerCleanup(string $serverId, bool $deleteUnusedVolumes = false, bool $deleteUnusedNetworks = false): array
    {
        return $this->getClient()->request('POST', '/servers/'.\rawurlencode($serverId).'/docker-cleanup/run', [
            'delete_unused_volumes' => $deleteUnusedVolumes,
            'delete_unused_networks' => $deleteUnusedNetworks,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function serverDockerCleanupExecutions(string $serverId): array
    {
        return $this->list($this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/docker-cleanup/executions'));
    }

    public function deleteServer(string $serverId, string $ownershipToken): bool
    {
        $this->lastError = '';
        try {
            $server = $this->server($serverId);
            $expectedNamePrefix = 'shippercli-';
            if (! \str_starts_with((string) ($server['name'] ?? ''), $expectedNamePrefix)) {
                $this->lastError = 'Refusing to remove a Coolify server record without a valid Shipper ownership token.';

                return false;
            }
            $cloudProvider = null;
            foreach (['hetzner', 'digitalocean', 'vultr'] as $provider) {
                if (\hash_equals($this->serverOwnershipToken($serverId, $provider), $ownershipToken)) {
                    $cloudProvider = $provider;
                    break;
                }
            }
            $cloudProvisioned = $cloudProvider !== null;
            $recordTokenMatches = \hash_equals($this->serverOwnershipToken($serverId), $ownershipToken);
            if (! $cloudProvisioned && ! $recordTokenMatches) {
                $this->lastError = 'Refusing to remove a Coolify server record without a valid Shipper ownership token.';

                return false;
            }
            $linkedCloudProvider = null;
            foreach ([
                'hetzner' => ['hetzner_server_id'],
                'digitalocean' => ['digitalocean_server_id', 'digitalocean_droplet_id'],
                'vultr' => ['vultr_server_id', 'vultr_instance_id'],
            ] as $provider => $fields) {
                if (\in_array($server['cloud_provider'] ?? null, [$provider], true)
                    || \array_filter($fields, static fn (string $field): bool => isset($server[$field])) !== []) {
                    $linkedCloudProvider = $provider;
                    break;
                }
            }
            $hasLinkedCloudInstance = $linkedCloudProvider !== null;
            if ($cloudProvisioned && $cloudProvider !== $linkedCloudProvider) {
                $this->lastError = 'Refusing cloud deletion because the ownership token does not match the server linked provider.';

                return false;
            }
            if ($hasLinkedCloudInstance) {
                if (! $cloudProvisioned) {
                    $this->lastError = 'Refusing to delete a linked cloud server without proof that Shipper provisioned it.';

                    return false;
                }
            }
            $resources = $this->list($this->getClient()->request('GET', '/servers/'.\rawurlencode($serverId).'/resources'));
            if ($resources !== []) {
                $descriptions = [];
                foreach ($resources as $resource) {
                    $type = \is_string($resource['type'] ?? null) ? $resource['type'] : 'resource';
                    $name = \is_string($resource['name'] ?? null) && $resource['name'] !== ''
                        ? $resource['name']
                        : ($this->resourceUuid($resource) ?: 'unknown');
                    $descriptions[] = $type.' "'.$name.'"';
                }
                $this->lastError = 'Refusing to remove a Coolify server that still hosts resources: '.\implode(', ', $descriptions).'. Move or remove them before retrying.';

                return false;
            }
            $path = '/servers/'.\rawurlencode($serverId);
            if ($cloudProvisioned) {
                $path .= '?delete_from_provider=true';
            }
            $this->getClient()->request('DELETE', $path);

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'Coolify server removal failed: '.$exception->getMessage();

            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function mcpTools(): array
    {
        return $this->getMcpClient()->tools();
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function callMcpTool(string $name, array $arguments = []): array
    {
        return $this->getMcpClient()->callTool($name, $arguments);
    }

    /** @return array<int, array<string, mixed>> */
    public function mcpResources(): array
    {
        return $this->getMcpClient()->resources();
    }

    /** @return array<string, mixed> */
    public function readMcpResource(string $uri): array
    {
        return $this->getMcpClient()->readResource($uri);
    }

    /** @return array<int, array<string, mixed>> */
    public function mcpPrompts(): array
    {
        return $this->getMcpClient()->prompts();
    }

    /** @param array<string, string> $arguments @return array<string, mixed> */
    public function getMcpPrompt(string $name, array $arguments = []): array
    {
        return $this->getMcpClient()->getPrompt($name, $arguments);
    }

    /** @return array<string, mixed> */
    public function coolifyInfrastructureOverview(): array
    {
        return $this->getMcpClient()->infrastructureOverview();
    }

    /** @return array<int, array<string, mixed>> */
    public function unhealthyResourcePages(bool $sampleOnly = false): array
    {
        return $this->getMcpClient()->unhealthyResourcePages($sampleOnly);
    }

    private function getMcpClient(): CoolifyMcpClient
    {
        if ($this->mcpClient === null) {
            $this->mcpClient = new CoolifyMcpClient(
                $this->stringConfig('url'),
                $this->stringConfig('api_token', $this->stringConfig('token')),
            );
        }

        return $this->mcpClient;
    }

    private function getClient(): CoolifyApiClient
    {
        if ($this->client === null) {
            $this->client = new CoolifyApiClient(
                $this->stringConfig('url'),
                $this->stringConfig('api_token', $this->stringConfig('token')),
                $this->integerConfig('timeout', 30),
                $this->booleanConfig('verify_ssl', true),
            );
        }

        return $this->client;
    }

    /** @return array{string, string, string} */
    private function resolveEnvironment(CoolifyApiClient $client, object $project, object $profile): array
    {
        $projectUuid = $this->profileOption($profile, 'project_uuid', $this->stringConfig('project_uuid'));
        if ($projectUuid === '') {
            $projectName = $this->profileOption($profile, 'project_name', $this->stringConfig('project_name', $this->projectName($project)));
            $projects = $this->list($client->request('GET', '/projects'));
            foreach ($projects as $candidate) {
                if (($candidate['name'] ?? null) === $projectName && \is_string($candidate['uuid'] ?? null)) {
                    $projectUuid = $candidate['uuid'];
                    break;
                }
            }
            if ($projectUuid === '') {
                $created = $client->request('POST', '/projects', ['name' => $projectName]);
                $projectUuid = $this->resourceUuid($created);
            }
        }
        if ($projectUuid === '') {
            throw new \RuntimeException('Coolify did not return a UUID for the selected project.');
        }

        $environmentName = $this->profileOption($profile, 'environment_name', $this->profileName($profile) ?: 'production');
        $environmentUuid = $this->profileOption($profile, 'environment_uuid', $this->stringConfig('environment_uuid'));
        if ($environmentUuid === '') {
            $environments = $this->list($client->request('GET', '/projects/'.\rawurlencode($projectUuid).'/environments'));
            $environmentFound = false;
            foreach ($environments as $environment) {
                if (($environment['name'] ?? null) === $environmentName) {
                    $environmentFound = true;
                    if (\is_string($environment['uuid'] ?? null)) {
                        $environmentUuid = $environment['uuid'];
                    }
                    break;
                }
            }
            if (! $environmentFound) {
                $created = $client->request('POST', '/projects/'.\rawurlencode($projectUuid).'/environments', ['name' => $environmentName]);
                $environmentUuid = $this->resourceUuid($created);
            }
        }
        if ($environmentUuid === '' && $environmentName === '') {
            throw new \RuntimeException('Coolify did not return a UUID for the selected environment.');
        }

        return [$projectUuid, $environmentUuid, $environmentName];
    }

    /** @return array{environment_uuid: string}|array{environment_name: string} */
    private function environmentPlacement(string $environmentUuid, string $environmentName): array
    {
        if ($environmentUuid !== '') {
            return ['environment_uuid' => $environmentUuid];
        }
        if ($environmentName !== '') {
            return ['environment_name' => $environmentName];
        }

        throw new \RuntimeException('Coolify resource placement requires an environment UUID or name.');
    }

    /** @return array<string, mixed> */
    private function ensureApplication(CoolifyApiClient $client, object $project, object $profile, string $projectUuid, string $environmentUuid, string $environmentName, string $serverUuid, string $name, ?string $startCommand, ?string $ownershipTag = null): array
    {
        $tag = $ownershipTag ?? $this->resourceTag($project, $profile);
        $appUuid = $startCommand === null
            ? $this->profileOption($profile, 'application_uuid', $this->stringConfig('application_uuid'))
            : '';
        $app = null;
        if ($appUuid !== '') {
            $app = $client->request('GET', '/applications/'.\rawurlencode($appUuid));
            $this->assertApplicationOwnership($app, $tag);
        } else {
            foreach ($this->list($client->request('GET', '/applications?tag='.\rawurlencode($tag))) as $candidate) {
                if (($candidate['name'] ?? null) === $name && $this->hasTag($candidate, $tag) && $this->hasTag($candidate, self::MARKER)) {
                    $app = $candidate;
                    break;
                }
            }
        }

        if ($app === null) {
            foreach ($this->list($client->request('GET', '/applications')) as $candidate) {
                if (($candidate['name'] ?? null) === $name) {
                    throw new \RuntimeException('Refusing to create a duplicate Coolify application: its name already exists without matching project/profile ownership tags. Review and migrate the existing application explicitly.');
                }
            }
        }

        $sourceType = $this->sourceType($profile);
        $source = $this->repositoryUrl($project);
        $branch = \method_exists($profile, 'branch') ? (string) $profile->branch() : 'main';
        $domains = $startCommand === null ? $this->desiredDomains($project, $profile) : [];
        $buildPack = $this->effectiveBuildPack($profile);
        $payload = [
            'name' => $name,
            'project_uuid' => $projectUuid,
            ...$this->environmentPlacement($environmentUuid, $environmentName),
            'server_uuid' => $serverUuid,
            'tags' => $this->applicationTags($profile, [], $tag),
            'is_auto_deploy_enabled' => $this->booleanConfig('auto_deploy', true),
            'is_force_https_enabled' => $this->sslEnabled($project),
            'domains' => \implode(',', $domains),
        ];
        if ($startCommand === null && $this->pullRequestId($profile) !== '') {
            $payload['is_preview_deployments_enabled'] = true;
        }
        if ($sourceType === 'git') {
            $payload['git_repository'] = $source;
            $payload['git_branch'] = $branch;
            $payload['build_pack'] = $buildPack;
            $payload['base_directory'] = \method_exists($project, 'projectRoot') ? $project->projectRoot() : '/';
        } elseif ($sourceType === 'docker_image') {
            $payload['docker_registry_image_name'] = $this->dockerImage($profile);
            $imageTag = $this->profileOption($profile, 'docker_registry_image_tag', $this->stringConfig('docker_registry_image_tag'));
            if ($startCommand !== null && $this->pullRequestId($profile) !== '') {
                $previewTag = $this->profileOption($profile, 'docker_tag', $this->stringConfig('docker_tag'));
                if ($previewTag !== '') {
                    $imageTag = $previewTag;
                }
            }
            if ($imageTag !== '') {
                $payload['docker_registry_image_tag'] = $imageTag;
            }
        } else {
            $payload['dockerfile'] = $this->dockerfileContent($profile);
        }
        if ($sourceType === 'git' && $buildPack === 'static') {
            $publishDirectory = $this->profileOption($profile, 'publish_directory');
            if ($publishDirectory === '' && \method_exists($project, 'webDirectory')) {
                $publishDirectory = \trim((string) $project->webDirectory());
            }
            if ($publishDirectory !== '') {
                $payload['publish_directory'] = $publishDirectory;
            }
        }
        if ($sourceType === 'git' && $this->supportsStaticNginxConfiguration($buildPack, $this->effectiveApplicationOptions($profile))
            && \method_exists($project, 'nginxConfig') && \trim($project->nginxConfig()) !== '') {
            $payload['custom_nginx_configuration'] = $project->nginxConfig();
        }
        if ($sourceType !== 'git') {
            // Coolify's Docker Image and standalone Dockerfile create endpoints
            // do not accept is_auto_deploy_enabled (it is Git-webhook behavior).
            unset($payload['is_auto_deploy_enabled']);
        }
        $deployScript = $this->profileValue($profile, 'deploy_script', '');
        if ($deployScript === '' && \method_exists($project, 'deployScript')) {
            $deployScript = $project->deployScript();
        }
        $explicitPostDeploymentCommand = $this->effectiveApplicationOptions($profile)['post_deployment_command'] ?? '';
        if ($deployScript !== '' && $startCommand === null
            && $explicitPostDeploymentCommand === '') {
            $payload['post_deployment_command'] = $deployScript;
        }
        if ($startCommand !== null) {
            $payload['start_command'] = $startCommand;
        }
        foreach (['install_command', 'build_command', 'ports_exposes', 'dockerfile_location', 'docker_compose_location', 'preview_url_template', 'redirect', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container'] as $option) {
            $value = $this->profileOption($profile, $option, $this->stringConfig($option));
            if ($value !== '') {
                $payload[$option] = $value;
            }
        }
        foreach ($this->applicationOptionFields() as $option) {
            if (\array_key_exists($option, $payload)) {
                continue;
            }
            $options = $this->profileOptions($profile);
            if (\array_key_exists($option, $options)) {
                $payload[$option] = $options[$option];
            } elseif (\array_key_exists($option, $this->config)) {
                $payload[$option] = $this->config[$option];
            }
        }
        $privateKeyUuid = $this->profileOption($profile, 'private_key_uuid', $this->stringConfig('private_key_uuid'));
        if ($privateKeyUuid !== '') {
            $payload['private_key_uuid'] = $privateKeyUuid;
        }
        if ($githubAppUuid = $this->profileOption($profile, 'github_app_uuid', $this->stringConfig('github_app_uuid'))) {
            $payload['github_app_uuid'] = $githubAppUuid;
        }
        $applicationSettings = $this->profileOptions($profile)['application'] ?? $this->config['application'] ?? [];
        if (\is_array($applicationSettings)) {
            $payload = \array_merge($payload, \array_intersect_key($applicationSettings, \array_flip($this->applicationOptionFields())));
        }
        if ($startCommand !== null && $sourceType === 'git') {
            $payload['is_auto_deploy_enabled'] = false;
        }
        if ($deployScript !== '' && $startCommand === null && $explicitPostDeploymentCommand === '') {
            $payload['post_deployment_command'] = $deployScript;
        }
        $pathRedirects = $startCommand === null ? $this->pathRedirects($project) : [];
        if ($pathRedirects !== []) {
            $server = $client->request('GET', '/servers/'.\rawurlencode($serverUuid));
            if (\strtolower((string) ($server['proxy_type'] ?? 'traefik')) !== 'traefik') {
                throw new \RuntimeException('Coolify Shipper path redirects require the Traefik proxy; custom redirect labels are not portable to the configured proxy.');
            }
            $labels = $payload['custom_labels'] ?? '';
            if (! \is_string($labels)) {
                throw new \InvalidArgumentException('Coolify custom_labels must be a string to combine with Shipper path redirects.');
            }
            $escapeLabels = ! \array_key_exists('is_container_label_escape_enabled', $payload)
                || $payload['is_container_label_escape_enabled'] === true;
            $payload['custom_labels'] = $this->appendPathRedirectLabels($labels, $pathRedirects, $project, $profile, $escapeLabels);
        }
        if (! isset($payload['redirect']) && ($direction = $this->configuredRedirectDirection($project)) !== null) {
            $payload['redirect'] = $direction;
        }
        $payload['tags'] = $this->applicationTags(
            $profile,
            \is_array($payload['tags'] ?? null) ? $payload['tags'] : [],
            $tag,
        );
        if (\in_array($sourceType, ['docker_image', 'dockerfile'], true) && \is_string($payload['start_command'] ?? null)) {
            $payload['custom_docker_run_options'] = $this->dockerImageEntrypointOptions(
                (string) ($payload['custom_docker_run_options'] ?? ''),
                $payload['start_command'],
            );
            unset($payload['start_command']);
        }
        if ($startCommand !== null) {
            if (! \in_array($sourceType, ['docker_image', 'dockerfile'], true)) {
                $payload['start_command'] = $startCommand;
            }
            $payload['domains'] = '';
            unset(
                $payload['redirect'],
                $payload['preview_url_template'],
                $payload['is_preview_deployments_enabled'],
                $payload['autogenerate_domain'],
                $payload['noindex_domains'],
                $payload['docker_compose_domains'],
                $payload['force_domain_override'],
            );
        }
        $githubAppUuid = $this->profileOption($profile, 'github_app_uuid', $this->stringConfig('github_app_uuid'));

        if ($app === null) {
            if ($sourceType === 'docker_image') {
                $endpoint = '/applications/dockerimage';
            } elseif ($sourceType === 'dockerfile') {
                $endpoint = '/applications/dockerfile';
            } elseif ($githubAppUuid !== '') {
                $endpoint = '/applications/private-github-app';
                $payload['github_app_uuid'] = $githubAppUuid;
            } elseif (($privateKeyUuid = $this->profileOption($profile, 'private_key_uuid', $this->stringConfig('private_key_uuid'))) !== '') {
                $endpoint = '/applications/private-deploy-key';
                $payload['private_key_uuid'] = $privateKeyUuid;
            } else {
                $endpoint = '/applications/public';
            }
            $app = $client->request('POST', $endpoint, $payload);
        } else {
            $uuid = $this->resourceUuid($app);
            if ($uuid === '') {
                throw new \RuntimeException('Coolify returned an existing application without a UUID.');
            }
            $this->assertApplicationOwnership($app, $tag);
            $existingTags = $app['tags'] ?? [];
            unset($payload['tags']);
            $existingSource = \is_string($app['docker_registry_image_name'] ?? null) && $app['docker_registry_image_name'] !== ''
                ? 'docker_image'
                : ((\is_string($app['dockerfile'] ?? null) && $app['dockerfile'] !== '' && empty($app['git_repository'])) ? 'dockerfile' : 'git');
            if ($existingSource !== $sourceType) {
                throw new \RuntimeException('Coolify application source type changed; refusing an in-place Git/Dockerfile/Docker Image conversion.');
            }
            // Coolify's application PATCH schema omits these fields even though
            // create accepts them (and GET returns them). Keep them on initial
            // creation, but do not send them during reconciliation updates.
            foreach (['autogenerate_domain', 'dockerfile_target_build', 'static_image', 'is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password'] as $createOnlyOption) {
                unset($payload[$createOnlyOption]);
            }
            $app = $client->request('PATCH', '/applications/'.\rawurlencode($uuid), $payload) + $app;
            $existingTagNames = \is_array($existingTags) ? $this->tagNames($existingTags) : [];
            $desiredTagNames = $this->applicationTags($profile, [], $tag);
            $missingTagNames = \array_values(\array_diff($desiredTagNames, $existingTagNames));
            if ($missingTagNames !== []) {
                $client->request('POST', '/applications/'.\rawurlencode($uuid).'/tags', ['tag_names' => $missingTagNames]);
            }
        }

        if ($this->resourceUuid($app) === '') {
            throw new \RuntimeException('Coolify did not return an application UUID.');
        }

        return $app;
    }

    private function applyEnvironment(CoolifyApiClient $client, string $uuid, object $project, object $profile, array $databaseEnvironment = []): void
    {
        $previewVariables = $this->profileOptions($profile)['preview_environment_variables'] ?? $this->config['preview_environment_variables'] ?? [];
        if (! \is_array($previewVariables)) {
            throw new \RuntimeException('Coolify preview_environment_variables must be a map of variable names to string values.');
        }
        $isPreview = $this->pullRequestId($profile) !== '';
        $variables = $isPreview
            ? \array_merge($databaseEnvironment, $previewVariables)
            : \array_merge($databaseEnvironment, $this->environmentVariables($project, $profile));
        $buildPack = $this->effectiveBuildPack($profile);
        if ($this->sourceType($profile) === 'git'
            && \method_exists($project, 'phpVersion') && \trim($project->phpVersion()) !== '') {
            if ($buildPack === 'nixpacks') {
                $variables['NIXPACKS_PHP_VERSION'] = \trim($project->phpVersion());
                if (! $isPreview) {
                    $previewVariables['NIXPACKS_PHP_VERSION'] = \trim($project->phpVersion());
                }
            }
        }
        $railpackExtensions = $this->profileOption($profile, 'railpack_php_extensions', $this->stringConfig('railpack_php_extensions'));
        if ($railpackExtensions !== '' && $this->sourceType($profile) === 'git' && $buildPack === 'railpack') {
            $variables['RAILPACK_PHP_EXTENSIONS'] = \implode(',', \array_map('trim', \explode(',', $railpackExtensions)));
            if (! $isPreview) {
                $previewVariables['RAILPACK_PHP_EXTENSIONS'] = $variables['RAILPACK_PHP_EXTENSIONS'];
            }
        }
        $scopedVariables = [];
        foreach ($variables as $key => $value) {
            $scopedVariables[] = [$key, $value, $isPreview];
        }
        if (! $isPreview) {
            foreach ($previewVariables as $key => $value) {
                $scopedVariables[] = [$key, $value, true];
            }
        }
        $desiredKeys = [];
        foreach ($scopedVariables as [$key, $value, $previewScope]) {
            if (is_string($key) && is_string($value) && $previewScope === $isPreview) {
                $desiredKeys[$key] = true;
            }
        }
        $existingVariables = $client->request('GET', '/applications/'.\rawurlencode($uuid).'/envs');
        $existingVariables = $existingVariables['data'] ?? $existingVariables;
        $existingVariablesByScopeAndKey = [];
        if (\is_array($existingVariables)) {
            foreach ($existingVariables as $existing) {
                if (! \is_array($existing)) {
                    continue;
                }
                $key = $existing['key'] ?? null;
                if (! \is_string($key)) {
                    continue;
                }
                $previewScope = (bool) ($existing['is_preview'] ?? false);
                $scopeAndKey = ($previewScope ? 'preview:' : 'production:').$key;
                $existingVariablesByScopeAndKey[$scopeAndKey] = $existing;
                if ($previewScope === $isPreview
                    && ($existing['comment'] ?? null) === self::ENVIRONMENT_MARKER
                    && ! isset($desiredKeys[$key])) {
                    $variableUuid = $this->resourceUuid($existing);
                    if ($variableUuid !== '') {
                        $client->request('DELETE', '/applications/'.\rawurlencode($uuid).'/envs/'.\rawurlencode($variableUuid));
                    }
                }
            }
        }
        foreach ($scopedVariables as [$key, $value, $previewScope]) {
            if (! \is_string($key) || ! \is_string($value)) {
                continue;
            }
            $scopeAndKey = ($previewScope ? 'preview:' : 'production:').$key;
            $method = isset($existingVariablesByScopeAndKey[$scopeAndKey]) ? 'PATCH' : 'POST';
            $client->request($method, '/applications/'.\rawurlencode($uuid).'/envs', [
                'key' => $key,
                'value' => $value,
                'is_preview' => $previewScope,
                'is_shown_once' => \in_array($key, $this->secretEnvironmentVariableNames($profile), true),
                ...$this->environmentVariableOptions($profile, $key),
                'comment' => self::ENVIRONMENT_MARKER,
            ]);
        }
    }

    /** @return list<string> */
    private function secretEnvironmentVariableNames(object $profile): array
    {
        $names = $this->profileOptions($profile)['secret_environment_variables'] ?? $this->config['secret_environment_variables'] ?? [];

        return \is_array($names) ? \array_values(\array_filter($names, '\is_string')) : [];
    }

    /** @return array{is_literal?: bool, is_multiline?: bool, is_buildtime?: bool, is_runtime?: bool} */
    private function environmentVariableOptions(object $profile, string $key, bool $supportsBuildRuntime = true): array
    {
        $options = $this->profileOptions($profile)['environment_variable_options'] ?? $this->config['environment_variable_options'] ?? [];
        $variableOptions = \is_array($options) && \is_array($options[$key] ?? null) ? $options[$key] : [];
        $allowed = ['is_literal' => true, 'is_multiline' => true];
        if ($supportsBuildRuntime) {
            $allowed['is_buildtime'] = true;
            $allowed['is_runtime'] = true;
        }

        return \array_intersect_key($variableOptions, $allowed);
    }

    /** @return array<string, string> */
    private function environmentVariables(object $project, object $profile): array
    {
        $variables = [];
        foreach ([$project, $profile] as $config) {
            if (! \method_exists($config, 'environment')) {
                continue;
            }
            $environment = $config->environment();
            if (\is_object($environment) && \method_exists($environment, 'variables')) {
                $variables = \array_merge($variables, $environment->variables());
            }
        }

        return $variables;
    }

    private function applyStorages(CoolifyApiClient $client, string $applicationUuid, object $profile, ?array $storages = null, bool $applyBackups = true): void
    {
        $options = $this->profileOptions($profile);
        $storages ??= $options['storages'] ?? $this->config['storages'] ?? [];
        if (! \is_array($storages)) {
            throw new \RuntimeException('Coolify storages must be an array of storage declarations.');
        }
        $existing = $this->storageList($client->request('GET', '/applications/'.\rawurlencode($applicationUuid).'/storages'));
        foreach ($storages as $storage) {
            if (! \is_array($storage) || ! \in_array($storage['type'] ?? null, ['persistent', 'file'], true)
                || ! \is_string($storage['mount_path'] ?? null) || $storage['mount_path'] === '') {
                throw new \RuntimeException('Each Coolify storage needs type=persistent|file and a non-empty mount_path.');
            }
            $storage = \array_intersect_key($storage, \array_flip([
                'type', 'name', 'mount_path', 'content', 'is_directory', 'fs_path', 'host_path', 'is_preview_suffix_enabled',
            ]));
            if ($this->previewDeploymentsEnabled($profile)) {
                $storage['is_preview_suffix_enabled'] ??= true;
            }
            $matching = null;
            foreach ($existing as $candidate) {
                if (($candidate['mount_path'] ?? null) === $storage['mount_path']) {
                    $matching = $candidate;
                    break;
                }
            }
            if ($matching === null) {
                $client->request('POST', '/applications/'.\rawurlencode($applicationUuid).'/storages', $storage);
                continue;
            }
            if (! \is_string($matching['uuid'] ?? null)) {
                throw new \RuntimeException('Coolify returned an existing storage without a UUID; refusing duplicate mount creation.');
            }
            $client->request('PATCH', '/applications/'.\rawurlencode($applicationUuid).'/storages', [
                ...$storage,
                'uuid' => $matching['uuid'],
            ]);
        }
        $existing = $this->storageList($client->request('GET', '/applications/'.\rawurlencode($applicationUuid).'/storages'));
        if ($applyBackups) {
            $backupSchedules = $options['storage_backups'] ?? $this->config['storage_backups'] ?? [];
            $this->applyStorageBackupSchedules($client, 'applications', $applicationUuid, $existing, $backupSchedules);
        }
    }

    private function applyServiceStorageFeatures(CoolifyApiClient $client, string $serviceUuid, object $profile, array $serviceSettings): void
    {
        $path = '/services/'.\rawurlencode($serviceUuid).'/storages';
        $storages = $this->storageList($client->request('GET', $path));
        $configuredStorages = $serviceSettings['storages'] ?? [];
        if (\is_array($configuredStorages) && $configuredStorages !== []) {
            $components = [];
            foreach (['applications', 'databases'] as $kind) {
                foreach ($this->list($client->request('GET', '/services/'.\rawurlencode($serviceUuid).'/'.$kind)) as $component) {
                    $componentUuid = $this->resourceUuid($component);
                    $componentName = $component['name'] ?? $component['service_name'] ?? $component['docker_compose_service_name'] ?? $component['container_name'] ?? null;
                    if (\is_string($componentName) && $componentName !== '' && $componentUuid !== '') {
                        $components[$componentName][] = $componentUuid;
                    }
                }
            }
            foreach ($configuredStorages as $storage) {
                if (! \is_array($storage)) {
                    continue;
                }
                $componentName = $storage['component'];
                $matches = $components[$componentName] ?? [];
                if (\count($matches) !== 1) {
                    throw new \RuntimeException("Coolify Compose component '{$componentName}' did not resolve to exactly one application/database sub-resource.");
                }
                $storage['resource_uuid'] = $matches[0];
                unset($storage['component']);
                $existing = null;
                $ambiguousMount = null;
                foreach ($storages as $candidate) {
                    if (($candidate['mount_path'] ?? null) === $storage['mount_path']) {
                        if (($candidate['resource_uuid'] ?? null) === $matches[0]) {
                            $existing = $candidate;
                            break;
                        }
                        if (! \is_string($candidate['resource_uuid'] ?? null)) {
                            $ambiguousMount = $candidate;
                        }
                    }
                }
                if ($existing === null && $ambiguousMount !== null) {
                    throw new \RuntimeException("Coolify storage '{$storage['mount_path']}' exists but its component ownership is not exposed; refusing to create a duplicate mount.");
                }
                if ($existing === null) {
                    $client->request('POST', $path, \array_diff_key($storage, ['is_preview_suffix_enabled' => true]));
                } elseif (($existing['is_readonly'] ?? false) === true) {
                    if ($this->previewDeploymentsEnabled($profile)) {
                        $client->request('PATCH', $path, [
                            'uuid' => $existing['uuid'],
                            'type' => $existing['type'] ?? $storage['type'],
                            'is_preview_suffix_enabled' => true,
                        ]);
                    }
                } elseif (\is_string($existing['uuid'] ?? null)) {
                    $client->request('PATCH', $path, [...$storage, 'uuid' => $existing['uuid']]);
                } else {
                    throw new \RuntimeException("Coolify returned storage '{$storage['mount_path']}' without a UUID; refusing duplicate creation.");
                }
            }
            $storages = $this->storageList($client->request('GET', $path));
        }
        if ($this->previewDeploymentsEnabled($profile)) {
            foreach ($storages as $storage) {
                if (! \is_string($storage['uuid'] ?? null) || ! \is_string($storage['type'] ?? null)) {
                    continue;
                }
                if (($storage['is_preview_suffix_enabled'] ?? false) !== true) {
                    $client->request('PATCH', $path, [
                        'uuid' => $storage['uuid'],
                        'type' => $storage['type'],
                        'is_preview_suffix_enabled' => true,
                    ]);
                }
            }
        }
        $this->applyStorageBackupSchedules($client, 'services', $serviceUuid, $storages, $serviceSettings['storage_backups'] ?? []);
    }

    private function applyServiceComponentOptions(CoolifyApiClient $client, string $serviceUuid, array $serviceSettings): void
    {
        foreach (['applications', 'databases'] as $kind) {
            $configured = $serviceSettings[$kind] ?? [];
            if (! \is_array($configured) || $configured === []) {
                continue;
            }
            $components = $this->list($client->request('GET', '/services/'.\rawurlencode($serviceUuid).'/'.$kind));
            foreach ($configured as $componentName => $settings) {
                $matches = \array_values(\array_filter(
                    $components,
                    static fn (array $component): bool => ($component['name'] ?? $component['service_name'] ?? $component['docker_compose_service_name'] ?? $component['container_name'] ?? null) === $componentName,
                ));
                if (\count($matches) !== 1 || $this->resourceUuid($matches[0]) === '') {
                    throw new \RuntimeException("Coolify Compose {$kind} component '{$componentName}' did not resolve to exactly one component with a UUID.");
                }
                $component = $matches[0];
                $changes = $settings;
                $forceDomainOverride = null;
                if ($kind === 'applications' && \is_array($changes) && \array_key_exists('force_domain_override', $changes)) {
                    $forceDomainOverride = $changes['force_domain_override'];
                    unset($changes['force_domain_override']);
                }
                $path = '/services/'.\rawurlencode($serviceUuid).'/'.$kind.'/'.\rawurlencode($this->resourceUuid($component));
                if ($forceDomainOverride !== null) {
                    $path .= '?'.\http_build_query(['force_domain_override' => $forceDomainOverride]);
                }
                $client->request('PATCH', $path, $changes);
            }
        }
    }

    /** @return list<string> */
    private function serviceComponentOptionErrors(mixed $components, string $kind): array
    {
        if (! \in_array($kind, ['applications', 'databases'], true) || ! \is_array($components) || ($components !== [] && \array_is_list($components))) {
            return ["Coolify service {$kind} overrides must be a map keyed by Compose component name."];
        }
        $fields = $kind === 'applications'
            ? ['url', 'noindex_domains', 'human_name', 'description', 'image', 'exclude_from_status', 'is_log_drain_enabled', 'is_gzip_enabled', 'is_stripprefix_enabled', 'is_force_https_enabled', 'max_restart_count', 'force_domain_override']
            : ['human_name', 'description', 'image', 'exclude_from_status', 'is_log_drain_enabled', 'is_public', 'public_port', 'public_port_timeout'];
        $errors = [];
        foreach ($components as $componentName => $settings) {
            if (! \is_string($componentName) || \trim($componentName) === '' || ! \is_array($settings)) {
                $errors[] = "Coolify service {$kind} overrides need non-empty component names and option maps.";
                continue;
            }
            foreach ($settings as $field => $value) {
                if (! \is_string($field) || ! \in_array($field, $fields, true)) {
                    $errors[] = "Unsupported Coolify service {$kind} option '{$field}'.";
                    continue;
                }
                $expected = match ($field) {
                    'url', 'human_name', 'description', 'image' => 'string|null',
                    'noindex_domains' => 'list<string>|null',
                    'exclude_from_status', 'is_log_drain_enabled', 'is_gzip_enabled', 'is_stripprefix_enabled', 'is_force_https_enabled', 'is_public', 'force_domain_override' => 'boolean',
                    'max_restart_count', 'public_port', 'public_port_timeout' => 'integer|null',
                    default => 'mixed',
                };
                $valid = match ($expected) {
                    'string|null' => $value === null || \is_string($value),
                    'list<string>|null' => $value === null || (\is_array($value) && \array_is_list($value)
                        && \array_filter($value, static fn (mixed $domain): bool => ! \is_string($domain)) === []),
                    'boolean' => \is_bool($value),
                    'integer|null' => $value === null || \is_int($value),
                    default => false,
                };
                if (! $valid) {
                    $errors[] = "Coolify service {$kind} option '{$field}' must be {$expected}.";
                    continue;
                }
                if (($field === 'max_restart_count' && \is_int($value) && $value < 0)
                    || ($field === 'public_port' && \is_int($value) && ($value < 1 || $value > 65535))
                    || ($field === 'public_port_timeout' && \is_int($value) && $value < 1)) {
                    $errors[] = "Coolify service {$kind} option '{$field}' is outside the supported range.";
                }
            }
        }

        return $errors;
    }

    private function applyStorageBackupSchedules(CoolifyApiClient $client, string $resourceType, string $resourceUuid, array $storages, mixed $schedules): void
    {
        if (! \is_array($schedules)) {
            return;
        }
        foreach ($schedules as $mountPath => $schedule) {
            $storage = null;
            foreach ($storages as $candidate) {
                if (($candidate['mount_path'] ?? null) === $mountPath) {
                    $storage = $candidate;
                    break;
                }
            }
            if (! \is_array($storage) || ! \is_string($storage['uuid'] ?? null)) {
                throw new \RuntimeException("Coolify {$resourceType} backup mount '{$mountPath}' was not found in the storage inventory.");
            }
            if (($storage['type'] ?? null) !== 'persistent'
                && !(($storage['type'] ?? null) === 'file' && ($storage['is_directory'] ?? false))) {
                throw new \RuntimeException("Coolify {$resourceType} storage '{$mountPath}' is not a backup-capable persistent or directory mount.");
            }
            if (! \is_array($schedule)) {
                throw new \RuntimeException("Coolify {$resourceType} backup settings for '{$mountPath}' must be a map.");
            }
            $client->request('PUT', '/'.$resourceType.'/'.\rawurlencode($resourceUuid).'/storages/'.\rawurlencode($storage['uuid']).'/backups', $schedule);
        }
    }

    /** @return list<array<string, mixed>> */
    private function storageList(array $response): array
    {
        $storages = [];
        $collect = static function (array $items) use (&$storages, &$collect): void {
            if (isset($items['mount_path']) && \is_string($items['mount_path'])) {
                $storages[] = $items;
                return;
            }
            foreach ($items as $item) {
                if (\is_array($item)) {
                    $collect($item);
                }
            }
        };
        $collect($response);
        $byIdentity = [];
        foreach ($storages as $storage) {
            $identity = \is_string($storage['uuid'] ?? null)
                ? $storage['uuid']
                : (string) ($storage['mount_path'] ?? \count($byIdentity));
            $byIdentity[$identity] = $storage;
        }

        return \array_values($byIdentity);
    }

    private function applyCustomService(CoolifyApiClient $client, object $project, object $profile, string $projectUuid, string $environmentUuid, string $environmentName, string $serverUuid, array $databaseEnvironment = []): ?string
    {
        $options = $this->profileOptions($profile);
        $compose = $options['docker_compose'] ?? $this->config['docker_compose'] ?? null;
        $serviceSettings = $options['service'] ?? $this->config['service'] ?? [];
        $catalogType = \is_array($serviceSettings) && \is_string($serviceSettings['type'] ?? null)
            ? $serviceSettings['type']
            : '';
        $hasCustomCompose = \is_string($compose) && \trim($compose) !== '';
        if (! $hasCustomCompose && $catalogType === '') {
            $tag = $this->resourceTag($project, $profile);
            foreach ($this->ownedServices($client, $tag) as $service) {
                $uuid = $this->resourceUuid($service);
                if ($uuid !== '') {
                    $client->request('DELETE', '/services/'.\rawurlencode($uuid));
                }
            }
            return null;
        }
        $tag = $this->resourceTag($project, $profile);
        $name = $this->slug($this->resourceName($project, $profile).'-stack');
        $configuredName = \is_array($serviceSettings) && \is_string($serviceSettings['name'] ?? null)
            ? $serviceSettings['name']
            : $name;
        $customTags = \is_array($serviceSettings) && \is_array($serviceSettings['tags'] ?? null)
            ? $this->tagNames($serviceSettings['tags'])
            : [];
        $service = null;
        foreach ($this->list($client->request('GET', '/services?tag='.\rawurlencode($tag))) as $candidate) {
            if ($this->hasTag($candidate, $tag) && $this->hasTag($candidate, self::MARKER)) {
                $service = $candidate;
                break;
            }
        }
        $payload = [
            'name' => $configuredName,
            'description' => \is_array($serviceSettings) && \is_string($serviceSettings['description'] ?? null)
                ? $serviceSettings['description']
                : 'Managed by Shipper CLI',
            'project_uuid' => $projectUuid,
            ...$this->environmentPlacement($environmentUuid, $environmentName),
            'server_uuid' => $serverUuid,
            'tags' => \array_values(\array_unique([
                ...$customTags,
                $tag,
                self::MARKER,
            ])),
        ];
        if ($hasCustomCompose) {
            $payload['docker_compose_raw'] = \base64_encode($compose);
        } else {
            $payload['type'] = $catalogType;
        }
        if (\is_array($serviceSettings)) {
            $payload = \array_merge($payload, \array_intersect_key($serviceSettings, \array_flip([
                'instant_deploy', 'connect_to_docker_network', 'urls', 'force_domain_override', 'is_container_label_escape_enabled',
            ])));
        }
        $isNewService = $service === null;
        if ($isNewService) {
            foreach ($this->list($client->request('GET', '/services')) as $candidate) {
                if (($candidate['name'] ?? null) === $configuredName) {
                    throw new \RuntimeException('Refusing to create a duplicate Coolify service without matching project/profile ownership tags. Review and migrate the existing service explicitly.');
                }
            }
            if (\is_string($serviceSettings['destination_uuid'] ?? null)) {
                $payload['destination_uuid'] = $serviceSettings['destination_uuid'];
            }
            $service = $client->request('POST', '/services', $payload);
        } else {
            $uuid = $this->resourceUuid($service);
            $existingType = \is_string($service['service_type'] ?? null) ? $service['service_type'] : '';
            if (($catalogType !== '' && $existingType !== $catalogType)
                || ($catalogType === '' && $existingType !== '')) {
                throw new \RuntimeException('Coolify service type changed; refusing to convert an existing managed service between catalog and custom Compose modes.');
            }
            $tags = $service['tags'] ?? [];
            $updatePayload = [
                'name' => $payload['name'],
                'description' => $payload['description'],
                ...\array_intersect_key($serviceSettings, \array_flip([
                    'instant_deploy', 'connect_to_docker_network', 'urls', 'force_domain_override', 'is_container_label_escape_enabled',
                ])),
            ];
            if ($hasCustomCompose) {
                $updatePayload['docker_compose_raw'] = $payload['docker_compose_raw'];
            }
            $service = $client->request('PATCH', '/services/'.\rawurlencode($uuid), $updatePayload) + $service;
            $existingTagNames = \is_array($tags) ? $this->tagNames($tags) : [];
            $missingTagNames = \array_values(\array_diff([...$customTags, $tag, self::MARKER], $existingTagNames));
            if ($missingTagNames !== []) {
                $client->request('POST', '/services/'.\rawurlencode($uuid).'/tags', ['tag_names' => $missingTagNames]);
            }
        }
        $uuid = $this->resourceUuid($service);
        if ($uuid === '') {
            throw new \RuntimeException('Coolify did not return a UUID for the Compose service.');
        }
        $this->reconcileResourceDestination(
            $client,
            'services',
            $uuid,
            $service,
            $serverUuid,
            \is_string($serviceSettings['destination_uuid'] ?? null) ? $serviceSettings['destination_uuid'] : '',
            ($serviceSettings['migrate_volumes'] ?? true) === true,
        );
        if ($environmentUuid !== '' && ($service['environment_uuid'] ?? null) !== $environmentUuid) {
            $client->request('POST', '/services/'.\rawurlencode($uuid).'/move', [
                'environment_uuid' => $environmentUuid,
            ]);
        }
        $this->applyServiceStorageFeatures($client, $uuid, $profile, \is_array($serviceSettings) ? $serviceSettings : []);
        $this->applyServiceComponentOptions($client, $uuid, \is_array($serviceSettings) ? $serviceSettings : []);
        $desiredEnvironment = \array_merge($databaseEnvironment, $this->environmentVariables($project, $profile));
        $existingEnvironment = $this->list($client->request('GET', '/services/'.\rawurlencode($uuid).'/envs'));
        $existingEnvironmentByKey = [];
        foreach ($existingEnvironment as $variable) {
            $key = $variable['key'] ?? null;
            $variableUuid = $this->resourceUuid($variable);
            if (\is_string($key)) {
                $existingEnvironmentByKey[$key] = $variable;
            }
            if (\is_string($key) && ($variable['comment'] ?? null) === self::ENVIRONMENT_MARKER
                && ! \array_key_exists($key, $desiredEnvironment) && $variableUuid !== '') {
                $this->deleteServiceEnvironmentVariable($uuid, $variableUuid);
            }
        }
        foreach ($desiredEnvironment as $key => $value) {
            if (! \is_string($key) || ! \is_string($value)) {
                continue;
            }
            $client->request(isset($existingEnvironmentByKey[$key]) ? 'PATCH' : 'POST', '/services/'.\rawurlencode($uuid).'/envs', [
                'key' => $key,
                'value' => $value,
                'is_shown_once' => \in_array($key, $this->secretEnvironmentVariableNames($profile), true),
                ...$this->environmentVariableOptions($profile, $key),
                'comment' => self::ENVIRONMENT_MARKER,
            ]);
        }

        return $uuid;
    }

    private function applyDatabases(CoolifyApiClient $client, object $project, object $profile, string $projectUuid, string $environmentUuid, string $environmentName, string $serverUuid): array
    {
        $databases = $this->databaseDeclarations($project, $profile);
        $applicationEnvironment = [];
        $baseTag = $this->resourceTag($project, $profile);
        $scopeTag = $this->pullRequestId($profile) !== '' ? $baseTag.'-pr-'.$this->pullRequestId($profile) : $baseTag;
        $inventory = $this->list($client->request('GET', '/databases'));
        $wantedTags = [];
        foreach ($databases as $database) {
            $logicalName = $this->interpolate($database['name'], $project, $profile);
            $name = $this->slug($logicalName);
            if (($pullRequestId = $this->pullRequestId($profile)) !== '') {
                $suffix = '-pr-'.$pullRequestId;
                $name = \substr($name, 0, 50 - \strlen($suffix)).$suffix;
            }
            $type = \strtolower($database['type']);
            $type = $type === 'postgresql' ? 'postgres' : $type;
            $user = $database['user'] !== '' ? $this->interpolate($database['user'], $project, $profile) : 'shipper';
            $configuredPassword = $database['password'] !== '' ? $this->interpolate($database['password'], $project, $profile) : null;
            $providerOptions = $this->databaseProviderOptions($project, $profile, $database);
            $configuredTags = $this->databaseTags($project, $profile, $database['name']);
            $credentialFields = match ($type) {
                'mysql' => ['mysql_database' => $name, 'mysql_user' => $user],
                'mariadb' => ['mariadb_database' => $name, 'mariadb_user' => $user],
                'postgres' => ['postgres_db' => $name, 'postgres_user' => $user],
                'mongodb' => ['mongo_initdb_root_username' => $user],
                'clickhouse' => ['clickhouse_admin_user' => $user],
                default => [],
            };
            $passwordField = match ($type) {
                'mysql' => 'mysql_password',
                'mariadb' => 'mariadb_password',
                'postgres' => 'postgres_password',
                'mongodb' => 'mongo_initdb_root_password',
                'redis' => 'redis_password',
                'clickhouse' => 'clickhouse_admin_password',
                'dragonfly' => 'dragonfly_password',
                'keydb' => 'keydb_password',
                default => null,
            };
            if ($configuredPassword !== null && $passwordField !== null) {
                $credentialFields[$passwordField] = $configuredPassword;
            }
            $effectiveCredentials = \array_merge($credentialFields, $providerOptions);
            $tag = $scopeTag.'-db-'.\substr(\hash('sha256', $name), 0, 10);
            $wantedTags[$tag] = true;
            $existing = null;
            foreach ($inventory as $candidate) {
                if ($this->hasTag($candidate, $tag) && $this->hasTag($candidate, self::MARKER)) {
                    $existing = $candidate;
                    break;
                }
            }
            if ($existing !== null) {
                $existingUuid = $this->resourceUuid($existing);
                if ($existingUuid !== '' && $environmentUuid !== ''
                    && ($existing['environment_uuid'] ?? null) !== $environmentUuid) {
                    $client->request('POST', '/databases/'.\rawurlencode($existingUuid).'/move', [
                        'environment_uuid' => $environmentUuid,
                    ]);
                }
                if ($existingUuid !== '') {
                    $this->reconcileResourceDestination(
                        $client,
                        'databases',
                        $existingUuid,
                        $existing,
                        $serverUuid,
                        \is_string($providerOptions['destination_uuid'] ?? null) ? $providerOptions['destination_uuid'] : '',
                        ($providerOptions['migrate_volumes'] ?? true) === true,
                    );
                }
                $updateOptions = $effectiveCredentials;
                unset($updateOptions['instant_deploy'], $updateOptions['destination_uuid'], $updateOptions['migrate_volumes']);
                if ($existingUuid !== '' && $updateOptions !== []) {
                    $client->request('PATCH', '/databases/'.\rawurlencode($existingUuid), $updateOptions);
                }
                $existingTagNames = $this->tagNames($existing['tags'] ?? []);
                $missingTags = \array_values(\array_diff($configuredTags, $existingTagNames));
                if ($existingUuid !== '' && $missingTags !== []) {
                    $client->request('POST', '/databases/'.\rawurlencode($existingUuid).'/tags', ['tag_names' => $missingTags]);
                }
                $details = $existing;
                if ($existingUuid !== '') {
                    try {
                        $details = $client->request('GET', '/databases/'.\rawurlencode($existingUuid)) + $existing;
                    } catch (\Throwable) {
                        // Sensitive-read permissions are optional; preserve the known resource data.
                    }
                }
                $details = \array_merge($details, $effectiveCredentials);
                if ($existingUuid !== '') {
                    $this->applyDatabaseEnvironmentVariables($client, $existingUuid, $project, $profile, $database['name']);
                }
                if ($existingUuid !== '') {
                    $this->applyDatabaseBackupOptions($client, $existingUuid, $project, $profile, $database['name']);
                    $this->applyDatabaseStorages($client, $existingUuid, $project, $profile, $database['name']);
                }
                $this->mergeDatabaseEnvironment($applicationEnvironment, $type, $name, $details, null, $user);
                continue;
            }
            foreach ($inventory as $candidate) {
                if (($candidate['name'] ?? null) === $name) {
                    throw new \RuntimeException("Coolify database '{$name}' already exists without matching Shipper project/profile ownership tags.");
                }
            }
            $password = $configuredPassword ?? \bin2hex(\random_bytes(24));
            $payload = [
                'name' => $name,
                'description' => 'Managed by Shipper CLI',
                'project_uuid' => $projectUuid,
                ...$this->environmentPlacement($environmentUuid, $environmentName),
                'server_uuid' => $serverUuid,
                'tags' => \array_values(\array_unique([...$configuredTags, $tag, self::MARKER])),
                'instant_deploy' => ($providerOptions['instant_deploy'] ?? true) === true,
            ];
            $createOptions = $effectiveCredentials;
            foreach (['health_check_enabled', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period'] as $option) {
                unset($createOptions[$option]);
            }
            unset($createOptions['migrate_volumes']);
            if ($type === 'mongodb') {
                unset($createOptions['mongo_initdb_root_password'], $createOptions['mongo_initdb_database']);
            }
            $payload = \array_merge($payload, $createOptions);
            $endpoint = match ($type) {
                'mysql' => '/databases/mysql',
                'mariadb' => '/databases/mariadb',
                'postgres' => '/databases/postgresql',
                'redis' => '/databases/redis',
                'mongodb' => '/databases/mongodb',
                'sqlite' => '/databases/sqlite',
                'clickhouse' => '/databases/clickhouse',
                'dragonfly' => '/databases/dragonfly',
                'keydb' => '/databases/keydb',
                default => throw new \RuntimeException("Unsupported Coolify database type '{$type}'."),
            };
            $payload += match ($type) {
                'mysql' => ['mysql_database' => $name, 'mysql_user' => $user, 'mysql_password' => $password],
                'mariadb' => ['mariadb_database' => $name, 'mariadb_user' => $user, 'mariadb_password' => $password],
                'postgres' => ['postgres_db' => $name, 'postgres_user' => $user, 'postgres_password' => $password],
                'redis' => ['redis_password' => $password],
                'mongodb' => ['mongo_initdb_root_username' => $user],
                'sqlite' => ['sqlite_databases' => $name],
                'clickhouse' => ['clickhouse_admin_user' => $user, 'clickhouse_admin_password' => $password],
                'dragonfly' => ['dragonfly_password' => $password],
                'keydb' => ['keydb_password' => $password],
            };
            $created = $client->request('POST', $endpoint, $payload);
            $details = $created;
            $createdUuid = $this->resourceUuid($created);
            if ($createdUuid !== '') {
                $updateOptions = $providerOptions;
                if ($type === 'mongodb') {
                    $updateOptions['mongo_initdb_root_password'] = $password;
                }
                unset($updateOptions['instant_deploy'], $updateOptions['destination_uuid'], $updateOptions['migrate_volumes']);
                if ($updateOptions !== []) {
                    $client->request('PATCH', '/databases/'.\rawurlencode($createdUuid), $updateOptions);
                }
                try {
                    $details = $client->request('GET', '/databases/'.\rawurlencode($createdUuid)) + $created;
                } catch (\Throwable) {
                    // Sensitive-read permissions are optional; retain the create response and generated secret.
                }
                $this->applyDatabaseBackupOptions($client, $createdUuid, $project, $profile, $database['name']);
                $this->applyDatabaseStorages($client, $createdUuid, $project, $profile, $database['name']);
                $this->applyDatabaseEnvironmentVariables($client, $createdUuid, $project, $profile, $database['name']);
            }
            $details = \array_merge($details, $effectiveCredentials);
            $effectivePassword = $passwordField !== null && \is_string($effectiveCredentials[$passwordField] ?? null)
                ? $effectiveCredentials[$passwordField]
                : $password;
            $this->mergeDatabaseEnvironment($applicationEnvironment, $type, $name, $details, $effectivePassword, $user);
        }
        foreach ($inventory as $database) {
            if ($this->hasTag($database, $scopeTag) && $this->hasTag($database, self::MARKER)) {
                foreach ($this->tagNames($database['tags'] ?? []) as $tag) {
                    if (\str_starts_with($tag, $scopeTag.'-db-') && ! isset($wantedTags[$tag])) {
                        $uuid = $this->resourceUuid($database);
                        if ($uuid !== '') {
                            $client->request('DELETE', '/databases/'.\rawurlencode($uuid));
                        }
                        break;
                    }
                }
            }
        }

        return $applicationEnvironment;
    }

    /** @return list<array{name: string, type: string, user: string, password: string}> */
    private function databaseDeclarations(object $project, object $profile): array
    {
        $declarations = [];
        if (\method_exists($project, 'databases')) {
            foreach ($project->databases() as $database) {
                if (! \is_object($database) || ! \method_exists($database, 'name')) {
                    continue;
                }
                $declarations[] = [
                    'name' => (string) $database->name(),
                    'type' => \method_exists($database, 'type') ? (string) $database->type() : 'mysql',
                    'user' => \method_exists($database, 'user') ? (string) $database->user() : '',
                    'password' => '',
                ];
            }
        }

        $profileDatabases = $this->profileOptions($profile)['databases'] ?? [];
        if (\is_array($profileDatabases)) {
            foreach ($profileDatabases as $key => $database) {
                if (! \is_array($database)) {
                    continue;
                }
                $name = $database['name'] ?? (\is_string($key) ? $key : '');
                if (! \is_string($name) || $name === '') {
                    continue;
                }
                $declarations[] = [
                    'name' => $name,
                    'type' => \is_string($database['type'] ?? null) ? $database['type'] : 'mysql',
                    'user' => \is_string($database['user'] ?? null) ? $database['user'] : '',
                    'password' => \is_string($database['password'] ?? null) ? $database['password'] : '',
                ];
            }
        }

        $byName = [];
        foreach ($declarations as $database) {
            $byName[$database['name']] = $database;
        }

        return \array_values($byName);
    }

    /** @param array{name: string, type: string, user: string, password: string} $database
     *  @return array<string, string|int|bool>
     */
    private function databaseProviderOptions(object $project, object $profile, array $database): array
    {
        $allOptions = $this->profileOptions($profile)['database_options'] ?? $this->config['database_options'] ?? [];
        $options = \is_array($allOptions) && \is_array($allOptions[$database['name']] ?? null)
            ? $allOptions[$database['name']]
            : [];
        foreach ($options as $key => $value) {
            if (\is_string($value)) {
                $options[$key] = $this->interpolate($value, $project, $profile);
            }
        }
        unset($options['storages'], $options['storage_backups'], $options['tags']);

        return $options;
    }

    /** @return list<string> */
    private function databaseTags(object $project, object $profile, string $databaseName): array
    {
        $allOptions = $this->profileOptions($profile)['database_options'] ?? $this->config['database_options'] ?? [];
        $tags = \is_array($allOptions) && \is_array($allOptions[$databaseName] ?? null)
            ? ($allOptions[$databaseName]['tags'] ?? [])
            : [];
        if (! \is_array($tags) || ! \array_is_list($tags)) {
            return [];
        }

        $resolved = [];
        foreach ($tags as $tag) {
            if (\is_string($tag) && \trim($tag) !== '') {
                $resolved[] = $this->interpolate($tag, $project, $profile);
            }
        }

        return \array_values(\array_unique($resolved));
    }

    /** @param array<string, mixed> $database */
    private function databaseDestinationUuid(array $database): ?string
    {
        $uuid = $database['destination_uuid'] ?? ($database['destination']['uuid'] ?? null);

        return \is_string($uuid) && \trim($uuid) !== '' ? $uuid : null;
    }

    /** @return list<string> */
    private function validateDatabaseStorages(mixed $storages, string $databaseName): array
    {
        if (! \is_array($storages) || ! \array_is_list($storages)) {
            return ["Coolify database '{$databaseName}' storages must be a list of storage declarations."];
        }
        $errors = [];
        $seen = [];
        foreach ($storages as $index => $storage) {
            if (! \is_array($storage) || ! \in_array($storage['type'] ?? null, ['persistent', 'file'], true)
                || ! \is_string($storage['mount_path'] ?? null) || \trim($storage['mount_path']) === '') {
                $errors[] = "Coolify database '{$databaseName}' storage '{$index}' needs type=persistent|file and a non-empty mount_path.";
                continue;
            }
            $mountPath = \trim($storage['mount_path']);
            if (isset($seen[$mountPath])) {
                $errors[] = "Coolify database '{$databaseName}' has duplicate storage mount_path '{$mountPath}'.";
            }
            $seen[$mountPath] = true;
            if ($storage['type'] === 'persistent' && (! \is_string($storage['name'] ?? null) || \trim($storage['name']) === '')) {
                $errors[] = "Coolify database '{$databaseName}' persistent storage '{$index}' requires a volume name.";
            }
            if (($storage['type'] === 'file') && ($storage['is_directory'] ?? false)
                && (! \is_string($storage['fs_path'] ?? null) || \trim($storage['fs_path']) === '')) {
                $errors[] = "Coolify database '{$databaseName}' directory storage '{$index}' requires fs_path.";
            }
            foreach (['is_directory', 'is_preview_suffix_enabled'] as $field) {
                if (isset($storage[$field]) && ! \is_bool($storage[$field])) {
                    $errors[] = "Coolify database storage field '{$field}' must be boolean.";
                }
            }
            foreach (['name', 'mount_path', 'content', 'fs_path', 'host_path'] as $field) {
                if (isset($storage[$field]) && ! \is_string($storage[$field])) {
                    $errors[] = "Coolify database storage field '{$field}' must be a string.";
                }
            }
            foreach ($storage as $field => $_value) {
                if (! \in_array($field, ['type', 'name', 'mount_path', 'content', 'is_directory', 'fs_path', 'host_path', 'is_preview_suffix_enabled'], true)) {
                    $errors[] = "Unsupported Coolify database storage setting '{$field}'.";
                }
            }
        }

        return $errors;
    }

    private function applyDatabaseStorages(CoolifyApiClient $client, string $databaseUuid, object $project, object $profile, string $databaseName): void
    {
        $allOptions = $this->profileOptions($profile)['database_options'] ?? $this->config['database_options'] ?? [];
        $options = \is_array($allOptions) && \is_array($allOptions[$databaseName] ?? null) ? $allOptions[$databaseName] : [];
        $storages = $options['storages'] ?? [];
        if (! \is_array($storages) || $storages === []) {
            return;
        }
        foreach ($storages as &$storage) {
            if (! \is_array($storage)) {
                continue;
            }
            foreach (['name', 'mount_path', 'content', 'fs_path', 'host_path'] as $field) {
                if (\is_string($storage[$field] ?? null)) {
                    $storage[$field] = $this->interpolate($storage[$field], $project, $profile);
                }
            }
            $storage = \array_intersect_key($storage, \array_flip(['type', 'name', 'mount_path', 'content', 'is_directory', 'fs_path', 'host_path', 'is_preview_suffix_enabled']));
        }
        unset($storage);
        $path = '/databases/'.\rawurlencode($databaseUuid).'/storages';
        $existing = $this->storageList($client->request('GET', $path));
        $byMountPath = [];
        foreach ($existing as $candidate) {
            if (\is_string($candidate['mount_path'] ?? null)) {
                $byMountPath[$candidate['mount_path']][] = $candidate;
            }
        }
        foreach ($storages as $storage) {
            if (! \is_array($storage) || ! \is_string($storage['mount_path'] ?? null)) {
                continue;
            }
            $matches = $byMountPath[$storage['mount_path']] ?? [];
            if ($matches === []) {
                $client->request('POST', $path, $storage);
                $refreshed = $this->storageList($client->request('GET', $path));
                $matches = \array_values(\array_filter($refreshed, static fn (array $candidate): bool => ($candidate['mount_path'] ?? null) === $storage['mount_path']));
                $uuid = \count($matches) === 1 ? $this->resourceUuid($matches[0]) : '';
                if ($uuid === '') {
                    throw new \RuntimeException("Coolify did not return a UUID for database storage '{$storage['mount_path']}'.");
                }
            } elseif (\count($matches) !== 1 || $this->resourceUuid($matches[0]) === '') {
                throw new \RuntimeException("Coolify database storage '{$storage['mount_path']}' did not resolve to exactly one mount with a UUID.");
            } else {
                $uuid = $this->resourceUuid($matches[0]);
                $client->request('PATCH', $path, [...$storage, 'uuid' => $uuid]);
            }
            $schedules = $options['storage_backups'] ?? [];
            if (\is_array($schedules) && \is_array($schedules[$storage['mount_path']] ?? null)) {
                $schedule = $schedules[$storage['mount_path']];
                foreach ($schedule as $key => $value) {
                    if (\is_string($value)) {
                        $schedule[$key] = $this->interpolate($value, $project, $profile);
                    }
                }
                $client->request('PUT', $path.'/'.$uuid.'/backups', $schedule);
            }
        }
    }

    private function applyDatabaseBackupOptions(CoolifyApiClient $client, string $databaseUuid, object $project, object $profile, string $databaseName): void
    {
        $allBackups = $this->profileOptions($profile)['database_backups'] ?? $this->config['database_backups'] ?? [];
        $backup = \is_array($allBackups) ? ($allBackups[$databaseName] ?? null) : null;
        if (! \is_array($backup)) {
            return;
        }
        $backupUuid = $backup['uuid'];
        unset($backup['uuid']);
        foreach ($backup as $key => $value) {
            if (\is_string($value)) {
                $backup[$key] = $this->interpolate($value, $project, $profile);
            }
        }
        $client->request(
            'PATCH',
            '/databases/'.\rawurlencode($databaseUuid).'/backups/'.\rawurlencode($backupUuid),
            $backup,
        );
    }

    private function applyDatabaseEnvironmentVariables(CoolifyApiClient $client, string $databaseUuid, object $project, object $profile, string $databaseName): void
    {
        $allEnvironments = $this->profileOptions($profile)['database_environment_variables'] ?? $this->config['database_environment_variables'] ?? [];
        $desiredEnvironment = \is_array($allEnvironments) && \is_array($allEnvironments[$databaseName] ?? null)
            ? $allEnvironments[$databaseName]
            : [];
        foreach ($desiredEnvironment as $key => $value) {
            if (\is_string($value)) {
                $desiredEnvironment[$key] = $this->interpolate($value, $project, $profile);
            }
        }
        $path = '/databases/'.\rawurlencode($databaseUuid).'/envs';
        $existingEnvironment = $this->list($client->request('GET', $path));
        $existingByKey = [];
        foreach ($existingEnvironment as $variable) {
            $key = $variable['key'] ?? null;
            $variableUuid = $this->resourceUuid($variable);
            if (\is_string($key)) {
                $existingByKey[$key] = $variable;
            }
            if (\is_string($key) && ($variable['comment'] ?? null) === self::ENVIRONMENT_MARKER
                && ! \array_key_exists($key, $desiredEnvironment) && $variableUuid !== '') {
                $client->request('DELETE', $path.'/'.\rawurlencode($variableUuid));
            }
        }
        foreach ($desiredEnvironment as $key => $value) {
            if (! \is_string($key) || ! \is_string($value)) {
                continue;
            }
            $payload = [
                'key' => $key,
                'value' => $value,
                'is_shown_once' => \in_array($key, $this->secretEnvironmentVariableNames($profile), true),
                ...$this->environmentVariableOptions($profile, $key, false),
                'comment' => self::ENVIRONMENT_MARKER,
            ];
            $client->request(isset($existingByKey[$key]) ? 'PATCH' : 'POST', $path, $payload);
        }
    }

    /** @return list<string> */
    private function databaseOptionFields(string $type): array
    {
        $shared = [
            'description', 'image', 'is_public', 'public_port', 'public_port_timeout', 'tags',
            'destination_uuid', 'migrate_volumes',
            'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation',
            'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'health_check_enabled',
            'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period',
            'instant_deploy',
        ];
        $specific = match (\strtolower($type)) {
            'mysql' => ['mysql_root_password', 'mysql_password', 'mysql_user', 'mysql_database', 'mysql_conf'],
            'mariadb' => ['mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database'],
            'postgres', 'postgresql' => ['postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf'],
            'redis' => ['redis_password', 'redis_conf'],
            'mongodb' => ['mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database'],
            'clickhouse' => ['clickhouse_admin_user', 'clickhouse_admin_password'],
            'dragonfly' => ['dragonfly_password'],
            'keydb' => ['keydb_password', 'keydb_conf'],
            'sqlite' => ['sqlite_databases'],
            default => [],
        };

        return [...$shared, ...$specific];
    }

    private function validDatabaseOptionType(string $field, mixed $value): bool
    {
        return match ($field) {
            'tags' => \is_array($value) && \array_is_list($value)
                && \array_filter($value, static fn (mixed $tag): bool => ! \is_string($tag) || \trim($tag) === '') === [],
            'is_public', 'health_check_enabled', 'instant_deploy', 'migrate_volumes' => \is_bool($value),
            'public_port', 'public_port_timeout', 'limits_memory_swappiness', 'limits_cpu_shares',
            'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period' => \is_int($value),
            default => \is_string($value),
        };
    }

    /** @param array<string, string> $environment */
    private function mergeDatabaseEnvironment(array &$environment, string $type, string $name, array $database, ?string $generatedPassword, string $fallbackUser): void
    {
        $url = $database['internal_db_url'] ?? $database['internal_db_url_public'] ?? null;
        $config = [];
        if (\is_string($url) && $url !== '') {
            $parts = \parse_url($url);
            if (\is_array($parts)) {
                $config = [
                    'host' => $parts['host'] ?? null,
                    'port' => isset($parts['port']) ? (string) $parts['port'] : null,
                    'database' => isset($parts['path']) ? \ltrim($parts['path'], '/') : null,
                    'username' => isset($parts['user']) ? \rawurldecode($parts['user']) : null,
                    'password' => isset($parts['pass']) ? \rawurldecode($parts['pass']) : null,
                ];
            }
        }
        $credentialKeys = match ($type) {
            'mysql' => ['mysql_user', 'mysql_password', 'mysql_database'],
            'mariadb' => ['mariadb_user', 'mariadb_password', 'mariadb_database'],
            'postgres' => ['postgres_user', 'postgres_password', 'postgres_db'],
            'mongodb' => ['mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database'],
            'clickhouse' => ['clickhouse_admin_user', 'clickhouse_admin_password', null],
            'redis' => [null, 'redis_password', null],
            'dragonfly' => [null, 'dragonfly_password', null],
            'keydb' => [null, 'keydb_password', null],
            default => [null, null, null],
        };
        [$userKey, $passwordKey, $databaseKey] = $credentialKeys;
        $generatedPassword ??= $passwordKey !== null && \is_string($database[$passwordKey] ?? null) ? $database[$passwordKey] : null;
        $config['username'] ??= $userKey === null
            ? null
            : (\is_string($database[$userKey] ?? null) ? $database[$userKey] : $fallbackUser);
        $config['password'] ??= $passwordKey !== null && \is_string($database[$passwordKey] ?? null) ? $database[$passwordKey] : $generatedPassword;
        $config['database'] ??= $databaseKey === null
            ? null
            : (\is_string($database[$databaseKey] ?? null) ? $database[$databaseKey] : $name);
        $config['host'] ??= $database['internal_db_host'] ?? $database['host'] ?? null;
        $config['port'] ??= isset($database['internal_db_port']) ? (string) $database['internal_db_port'] : null;
        if ($type === 'sqlite') {
            $environment['DB_CONNECTION'] ??= 'sqlite';
            $environment['DB_DATABASE'] ??= '/var/www/html/database/'.$name.'.sqlite';
        } elseif ($type === 'mysql' || $type === 'mariadb' || $type === 'postgres') {
            $environment['DB_CONNECTION'] ??= ($type === 'postgres' ? 'pgsql' : 'mysql');
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $variable) {
                if (! \array_key_exists($variable, $environment) && \is_string($config[$key] ?? null) && $config[$key] !== '') {
                    $environment[$variable] = $config[$key];
                }
            }
        }
        $prefix = 'SHIPPER_DB_'.\strtoupper(\preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?? $name).'_';
        foreach (['host' => 'HOST', 'port' => 'PORT', 'database' => 'DATABASE', 'username' => 'USERNAME', 'password' => 'PASSWORD'] as $key => $suffix) {
            if (! \array_key_exists($prefix.$suffix, $environment) && \is_string($config[$key] ?? null) && $config[$key] !== '') {
                $environment[$prefix.$suffix] = $config[$key];
            }
        }
    }

    private function scheduledTasksPath(string $resourceType, string $resourceUuid): string
    {
        if (! \in_array($resourceType, ['applications', 'services'], true)) {
            throw new \InvalidArgumentException("Unsupported Coolify scheduled-task resource type '{$resourceType}'.");
        }
        if (\trim($resourceUuid) === '') {
            throw new \InvalidArgumentException('Coolify scheduled-task resource UUID must not be empty.');
        }

        return '/'.$resourceType.'/'.\rawurlencode($resourceUuid).'/scheduled-tasks';
    }

    /** @param array<string, mixed> $payload */
    private function validateScheduledTaskPayload(array $payload, bool $creating): void
    {
        $allowed = ['name', 'command', 'frequency', 'container', 'timeout', 'enabled'];
        foreach ($payload as $field => $value) {
            if (! \in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify scheduled-task field '{$field}'.");
            }
            if (\in_array($field, ['name', 'command', 'frequency', 'container'], true)
                && (! \is_string($value) || \trim($value) === '')) {
                throw new \InvalidArgumentException("Coolify scheduled-task field '{$field}' must be a non-empty string.");
            }
            if ($field === 'timeout' && (! \is_int($value) || $value < 60 || $value > 36000)) {
                throw new \InvalidArgumentException('Coolify scheduled-task timeout must be between 60 and 36000 seconds.');
            }
            if ($field === 'enabled' && ! \is_bool($value)) {
                throw new \InvalidArgumentException('Coolify scheduled-task enabled must be boolean.');
            }
        }
        if ($creating) {
            foreach (['name', 'command', 'frequency'] as $required) {
                if (! isset($payload[$required]) || ! \is_string($payload[$required]) || \trim($payload[$required]) === '') {
                    throw new \InvalidArgumentException("Coolify scheduled-task creation requires '{$required}'.");
                }
            }
        }
    }

    /** @param array<string, mixed> $import */
    private function validateDatabaseImportPayload(array $import): void
    {
        $source = $import['source'] ?? null;
        if (! \in_array($source, ['upload', 's3', 'server'], true)) {
            throw new \InvalidArgumentException("Coolify database import source must be 'upload', 's3', or 'server'.");
        }
        $allowed = ['source', 'upload_id', 's3_storage_uuid', 'path', 'dump_all', 'replace_existing', 'keep_owners', 'restore_mysql_users', 'sqlite_database'];
        foreach ($import as $key => $value) {
            if (! \is_string($key) || ! \in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported Coolify database import option '{$key}'.");
            }
            if (\in_array($key, ['dump_all', 'replace_existing', 'keep_owners', 'restore_mysql_users'], true) && ! \is_bool($value)) {
                throw new \InvalidArgumentException("Coolify database import option '{$key}' must be boolean.");
            }
            if (\in_array($key, ['upload_id', 's3_storage_uuid', 'path', 'sqlite_database'], true)
                && (! \is_string($value) || \trim($value) === '')) {
                throw new \InvalidArgumentException("Coolify database import option '{$key}' must be a non-empty string.");
            }
        }
        $required = match ($source) {
            'upload' => ['upload_id'],
            's3' => ['s3_storage_uuid', 'path'],
            'server' => ['path'],
        };
        foreach ($required as $field) {
            if (! \is_string($import[$field] ?? null) || \trim($import[$field]) === '') {
                throw new \InvalidArgumentException("Coolify '{$source}' database imports require '{$field}'.");
            }
        }
    }

    private function applyScheduledTasks(CoolifyApiClient $client, string $applicationUuid, object $project, object $profile, string $resourceType = 'applications', string $taskTarget = 'application'): void
    {
        if (! \method_exists($project, 'cron')) {
            return;
        }
        $prefix = 'shipper-'.$this->projectProfileHash($project, $profile).'-';
        $tasksPath = '/'.$resourceType.'/'.\rawurlencode($applicationUuid).'/scheduled-tasks';
        $existing = $this->list($client->request('GET', $tasksPath));
        $byName = [];
        foreach ($existing as $task) {
            if (\is_string($task['name'] ?? null)) {
                $byName[$task['name']] = $task;
            }
        }
        $wanted = [];
        $taskContainers = $this->profileOptions($profile)['scheduled_task_containers'] ?? $this->config['scheduled_task_containers'] ?? [];
        foreach ($project->cron() as $taskName => $cron) {
            if (\method_exists($cron, 'enabled') && ! $cron->enabled()) {
                continue;
            }
            $targetsService = \is_array($taskContainers) && \array_key_exists($taskName, $taskContainers);
            if (($taskTarget === 'service') !== $targetsService) {
                continue;
            }
            $name = $prefix.$this->slug((string) $taskName);
            $wanted[$name] = true;
            $payload = [
                'name' => $name,
                'command' => \method_exists($cron, 'command') ? $cron->command() : '',
                'frequency' => \method_exists($cron, 'frequency') ? $cron->frequency() : 'daily',
                'timeout' => $this->scheduledTaskTimeout($profile),
                'enabled' => true,
            ];
            if (\is_array($taskContainers) && \is_string($taskContainers[$taskName] ?? null)) {
                $payload['container'] = $taskContainers[$taskName];
            }
            $task = $byName[$name] ?? null;
            if ($task === null) {
                $client->request('POST', $tasksPath, $payload);
            } elseif (\is_string($task['uuid'] ?? null)) {
                $client->request('PATCH', $tasksPath.'/'.\rawurlencode($task['uuid']), $payload);
            }
        }
        foreach ($byName as $name => $task) {
            if (\str_starts_with($name, $prefix) && ! isset($wanted[$name]) && \is_string($task['uuid'] ?? null)) {
                $client->request('DELETE', $tasksPath.'/'.\rawurlencode($task['uuid']));
            }
        }
    }

    private function scheduledTaskTimeout(object $profile): int
    {
        $timeout = $this->profileOptions($profile)['cron_timeout'] ?? $this->config['cron_timeout'] ?? 300;

        return \is_int($timeout) && $timeout >= 60 && $timeout <= 36000 ? $timeout : 300;
    }

    /** @param array<string, mixed> $application */
    private function reconcileResourceDestination(
        CoolifyApiClient $client,
        string $resourceType,
        string $resourceUuid,
        array $resource,
        string $targetServerUuid,
        string $explicitDestinationUuid = '',
        bool $migrateVolumes = true,
    ): void
    {
        $currentDestinationUuid = $resource['destination_uuid'] ?? ($resource['destination']['uuid'] ?? null);
        if (! \is_string($currentDestinationUuid) || $currentDestinationUuid === '') {
            $resource = $client->request('GET', '/'.$resourceType.'/'.\rawurlencode($resourceUuid));
            $currentDestinationUuid = $resource['destination_uuid'] ?? ($resource['destination']['uuid'] ?? null);
        }
        $currentServerUuid = $resource['server_uuid'] ?? null;
        $targetDestinationUuid = $explicitDestinationUuid;
        if ($targetDestinationUuid !== '') {
            $targetDestination = $client->request('GET', '/destinations/'.\rawurlencode($targetDestinationUuid));
            if (($targetDestination['server_uuid'] ?? null) !== $targetServerUuid) {
                throw new \RuntimeException("Configured Coolify destination '{$targetDestinationUuid}' does not belong to target server '{$targetServerUuid}'.");
            }
        }
        if (! \is_string($currentServerUuid) || $currentServerUuid === '') {
            $serverId = $resource['server_id'] ?? null;
            if (is_int($serverId) || (is_string($serverId) && ctype_digit($serverId))) {
                foreach ($this->list($client->request('GET', '/servers')) as $server) {
                    if ((string) ($server['id'] ?? '') === (string) $serverId && is_string($server['uuid'] ?? null)) {
                        $currentServerUuid = $server['uuid'];
                        break;
                    }
                }
            }
        }
        if (! is_string($currentServerUuid) || $currentServerUuid === '') {
            if ($targetDestinationUuid === '') {
                return;
            }
            $currentDestinationId = $resource['destination_id'] ?? null;
            $targetDestinationId = $targetDestination['id'] ?? null;
            if ((is_int($currentDestinationId) || (is_string($currentDestinationId) && ctype_digit($currentDestinationId)))
                && (is_int($targetDestinationId) || (is_string($targetDestinationId) && ctype_digit($targetDestinationId)))) {
                if ((string) $currentDestinationId === (string) $targetDestinationId) {
                    return;
                }
            } else {
                throw new \RuntimeException("Cannot safely migrate the Coolify {$resourceType}: its current server or destination identity is unavailable.");
            }
        }
        if ($targetDestinationUuid === '' && $currentServerUuid !== $targetServerUuid) {
            $targetDestinations = $this->serverDestinations($targetServerUuid);
            if (\count($targetDestinations) !== 1 || ! \is_string($targetDestinations[0]['uuid'] ?? null)) {
                throw new \RuntimeException("Coolify {$resourceType} is on server '{$currentServerUuid}', but target server '{$targetServerUuid}' has ".\count($targetDestinations).' destinations; configure an explicit destination_uuid to select one.');
            }
            $targetDestinationUuid = $targetDestinations[0]['uuid'];
        }

        $mustMigrate = $targetDestinationUuid !== ''
            && ((! \is_string($currentDestinationUuid) || $currentDestinationUuid !== $targetDestinationUuid)
                && ($currentServerUuid !== $targetServerUuid || ! \is_string($currentDestinationUuid) || $explicitDestinationUuid !== ''));
        if ($mustMigrate) {
            $client->request('POST', '/'.$resourceType.'/'.\rawurlencode($resourceUuid).'/migrate', [
                'destination_uuid' => $targetDestinationUuid,
                'migrate_volumes' => $migrateVolumes,
            ]);
            if ($resourceType === 'databases') {
                $client->request('POST', '/databases/'.\rawurlencode($resourceUuid).'/start');
            }
        }
    }

    private function applyWorkers(CoolifyApiClient $client, object $project, object $profile, string $projectUuid, string $environmentUuid, string $environmentName, string $serverUuid, array $databaseEnvironment = []): array
    {
        $desiredWorkerNames = [];
        foreach (\method_exists($project, 'queues') ? $project->queues() : [] as $name => $queue) {
            if (\method_exists($queue, 'enabled') && ! $queue->enabled()) {
                continue;
            }
            $connection = \method_exists($queue, 'connection') ? $queue->connection() : 'database';
            $worker = 'php artisan queue:work '.\escapeshellarg((string) $connection);
            if (\method_exists($queue, 'queue') && $queue->queue() !== '') {
                $worker .= ' --queue='.\escapeshellarg($queue->queue());
            }
            $worker .= ' --tries='.(\method_exists($queue, 'maxTries') ? $queue->maxTries() : 1);
            $worker .= ' --timeout='.(\method_exists($queue, 'timeout') ? $queue->timeout() : 60);
            $worker .= ' --sleep='.(\method_exists($queue, 'sleep') ? $queue->sleep() : 30);
            if (\method_exists($queue, 'maxSeconds') && $queue->maxSeconds() > 0) {
                $worker .= ' --max-time='.$queue->maxSeconds();
            }
            $count = \method_exists($queue, 'processes') ? $queue->processes() : 1;
            $restartOnDeploy = ! \method_exists($queue, 'restartOnDeploy') || $queue->restartOnDeploy();
            $this->ensureWorkerReplicas($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid, 'queue-'.$name, $worker, $count, $desiredWorkerNames, $databaseEnvironment, $restartOnDeploy);
        }
        foreach (\method_exists($project, 'daemons') ? $project->daemons() : [] as $name => $daemon) {
            if (\method_exists($daemon, 'enabled') && ! $daemon->enabled()) {
                continue;
            }
            $command = \method_exists($daemon, 'command') ? $daemon->command() : '';
            if ($command === '') {
                throw new \RuntimeException("Coolify daemon '{$name}' requires a command.");
            }
            $directory = \method_exists($daemon, 'directory') ? \trim($daemon->directory()) : '';
            if ($directory !== '') {
                $command = 'cd '.\escapeshellarg($directory).' && '.$command;
            }
            $restartDelay = \method_exists($daemon, 'restartDelay') ? \max(0, $daemon->restartDelay()) : 10;
            $command = 'while :; do ('.$command.'); sleep '.$restartDelay.'; done';
            $count = \method_exists($daemon, 'processes') ? $daemon->processes() : 1;
            $user = \method_exists($daemon, 'user') ? \trim((string) $daemon->user()) : 'ploi';
            $workerOptions = $user !== '' ? ['custom_docker_run_options' => '--user '.\escapeshellarg($user)] : [];
            $this->ensureWorkerReplicas($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid, 'daemon-'.$name, $command, $count, $desiredWorkerNames, $databaseEnvironment, true, $workerOptions);
        }
        $workerPrefix = $this->workerResourceName($project, $profile);
        $workerTag = $this->workerResourceTag($project, $profile);
        foreach ($this->ownedApplications($client, $project, $profile, $workerTag) as $app) {
            $name = $app['name'] ?? '';
            if (! \is_string($name)
                || (! \str_starts_with($name, $workerPrefix.'-queue-') && ! \str_starts_with($name, $workerPrefix.'-daemon-'))
                || isset($desiredWorkerNames[$name])) {
                continue;
            }
            $uuid = $this->resourceUuid($app);
            if ($uuid !== '' && $this->hasTag($app, self::MARKER)) {
                $client->request(
                    'DELETE',
                    '/applications/'.\rawurlencode($uuid).'?delete_configurations=true&delete_volumes=false&docker_cleanup=true&delete_connected_networks=true',
                );
            }
        }

        return $desiredWorkerNames;
    }

    /** @param array<string, bool> $desiredWorkerNames */
    private function ensureWorkerReplicas(CoolifyApiClient $client, object $project, object $profile, string $projectUuid, string $environmentUuid, string $environmentName, string $serverUuid, string $name, string $command, int $count, array &$desiredWorkerNames, array $databaseEnvironment = [], bool $restartOnDeploy = true, array $workerOptions = []): void
    {
        for ($replica = 1; $replica <= $count; $replica++) {
            $workerSlug = $this->slug($name).'-'.\substr(\hash('sha256', $name), 0, 8);
            $workerName = $this->workerResourceName($project, $profile).'-'.$workerSlug.'-'.$replica;
            $desiredWorkerNames[$workerName] = $restartOnDeploy;
            $worker = $this->ensureApplication($client, $project, $profile, $projectUuid, $environmentUuid, $environmentName, $serverUuid, $workerName, $command, $this->workerResourceTag($project, $profile));
            $workerUuid = $this->resourceUuid($worker);
            if ($workerUuid !== '') {
                $applicationDestination = $this->effectiveApplicationOptions($profile)['destination_uuid'] ?? '';
                $this->reconcileResourceDestination($client, 'applications', $workerUuid, $worker, $serverUuid,
                    \is_string($applicationDestination) ? $applicationDestination : '');
                if ($environmentUuid !== '' && ($worker['environment_uuid'] ?? null) !== $environmentUuid) {
                    $this->moveApplication($workerUuid, $environmentUuid);
                }
                $workerSettings = \array_intersect_key(
                    $this->effectiveApplicationOptions($profile),
                    \array_flip([
                        'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host',
                        'health_check_method', 'health_check_return_code', 'health_check_scheme',
                        'health_check_response_text', 'health_check_interval', 'health_check_timeout',
                        'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap',
                        'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset',
                        'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'stop_grace_period',
                        'docker_images_to_keep', 'is_log_drain_enabled', 'is_gpu_enabled', 'gpu_driver',
                        'gpu_count', 'gpu_device_ids', 'gpu_options', 'connect_to_docker_network',
                        'max_restart_count', 'is_container_label_escape_enabled',
                    ]),
                );
                if (\in_array($this->sourceType($profile), ['docker_image', 'dockerfile'], true)) {
                    $workerSettings['custom_docker_run_options'] = $this->dockerImageEntrypointOptions(
                        \is_string($workerSettings['custom_docker_run_options'] ?? null)
                            ? $workerSettings['custom_docker_run_options']
                            : '',
                        $command,
                    );
                }
                if (isset($workerOptions['custom_docker_run_options'])) {
                    $configuredOptions = $workerSettings['custom_docker_run_options'] ?? '';
                    $workerSettings['custom_docker_run_options'] = \trim($configuredOptions.' '.$workerOptions['custom_docker_run_options']);
                    unset($workerOptions['custom_docker_run_options']);
                }
                if ($workerSettings !== []) {
                    $client->request('PATCH', '/applications/'.\rawurlencode($workerUuid), $workerSettings);
                }
                if ($workerOptions !== []) {
                    $client->request('PATCH', '/applications/'.\rawurlencode($workerUuid), $workerOptions);
                }
                $secretManager = $this->profileOptions($profile)['secret_manager'] ?? $this->config['secret_manager'] ?? null;
                if (\is_array($secretManager)) {
                    $this->configureApplicationSecretManager(
                        $workerUuid,
                        $secretManager['integration_token_uuid'],
                        $secretManager['settings'] ?? [],
                    );
                }
                $this->applyEnvironment($client, $workerUuid, $project, $profile, $databaseEnvironment);
                $this->applyStorages($client, $workerUuid, $profile, $this->workerStorages($profile), false);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function workerStorages(object $profile): array
    {
        $options = $this->profileOptions($profile);
        $storages = $options['storages'] ?? $this->config['storages'] ?? [];
        if (! \is_array($storages)) {
            return [];
        }
        $pullRequestId = $this->pullRequestId($profile);
        if ($pullRequestId === '') {
            return $storages;
        }
        foreach ($storages as &$storage) {
            if (($storage['type'] ?? null) === 'persistent' && \is_string($storage['name'] ?? null)) {
                $storage['name'] .= '-pr-'.$pullRequestId;
                $storage['is_preview_suffix_enabled'] = false;
            }
        }
        unset($storage);

        return $storages;
    }

    private function workerNeedsDeployment(CoolifyApiClient $client, string $workerUuid, bool $restartOnDeploy): bool
    {
        if ($restartOnDeploy) {
            return true;
        }

        $history = $this->list($client->request(
            'GET',
            '/deployments/applications/'.\rawurlencode($workerUuid).'?skip=0&take=1',
        ));

        if ($history === []) {
            return true;
        }

        $status = \strtolower((string) ($history[0]['status'] ?? ''));

        return \in_array($status, ['failed', 'error', 'cancelled', 'cancelled-by-user', 'cancelled_by_user'], true);
    }

    private function dockerImageEntrypointOptions(string $configuredOptions, string $command): string
    {
        $entrypoint = 'sh -c '.\escapeshellarg($command);
        $entrypoint = \str_replace(['\\', '"'], ['\\\\', '\\"'], $entrypoint);

        return \trim($configuredOptions.' --entrypoint "'.$entrypoint.'"');
    }

    /** @return array<int, array<string, mixed>> */
    private function ownedApplications(CoolifyApiClient $client, object $project, object $profile, ?string $tag = null): array
    {
        $tag ??= $this->resourceTag($project, $profile);

        return \array_values(\array_filter(
            $this->list($client->request('GET', '/applications?tag='.\rawurlencode($tag))),
            fn (array $app): bool => $this->hasTag($app, $tag) && $this->hasTag($app, self::MARKER),
        ));
    }

    private function workerResourceName(object $project, object $profile, ?string $pullRequestId = null): string
    {
        $name = $this->resourceName($project, $profile);
        $pullRequestId ??= $this->pullRequestId($profile);

        return $pullRequestId === '' ? $name : $name.'-pr-'.$pullRequestId;
    }

    private function workerResourceTag(object $project, object $profile): string
    {
        $tag = $this->resourceTag($project, $profile);
        $pullRequestId = $this->pullRequestId($profile);

        return $pullRequestId === '' ? $tag : $tag.'-pr-'.$pullRequestId;
    }

    private function findApplication(CoolifyApiClient $client, object $project, object $profile): ?array
    {
        $uuid = $this->profileOption($profile, 'application_uuid', $this->stringConfig('application_uuid'));
        if ($uuid !== '') {
            $app = $client->request('GET', '/applications/'.\rawurlencode($uuid));
            $this->assertApplicationOwnership($app, $this->resourceTag($project, $profile));

            return $app;
        }
        foreach ($this->ownedApplications($client, $project, $profile) as $app) {
            if (($app['name'] ?? null) === $this->resourceName($project, $profile)) {
                return $app;
            }
        }

        return null;
    }

    private function findApplicationIfPresent(CoolifyApiClient $client, object $project, object $profile): ?array
    {
        try {
            return $this->findApplication($client, $project, $profile);
        } catch (\RuntimeException $exception) {
            if (\str_contains($exception->getMessage(), '(HTTP 404)')) {
                return null;
            }

            throw $exception;
        }
    }

    private function assertApplicationOwnership(array $app, string $tag): void
    {
        if (! $this->hasTag($app, self::MARKER) || ! $this->hasTag($app, $tag)) {
            throw new \RuntimeException('Refusing to modify a Coolify application without matching Shipper project/profile ownership tags. An application UUID does not establish ownership.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function ownedDatabases(CoolifyApiClient $client, string $tag): array
    {
        return \array_values(\array_filter(
            $this->list($client->request('GET', '/databases')),
            fn (array $database): bool => $this->hasDatabaseScopeTag($database, $tag, false) && $this->hasTag($database, self::MARKER),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function ownedDatabasesForProfile(CoolifyApiClient $client, string $resourceTag): array
    {
        return \array_values(\array_filter(
            $this->list($client->request('GET', '/databases')),
            function (array $database) use ($resourceTag): bool {
                if (! $this->hasTag($database, self::MARKER)) {
                    return false;
                }
                return $this->hasDatabaseScopeTag($database, $resourceTag, true);
            },
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function hasDatabaseScopeTag(array $database, string $scope, bool $includePreviews): bool
    {
        $preview = $includePreviews ? '(?:-pr-([0-9]+))?' : '';
        $pattern = '/\A'.preg_quote($scope, '/').$preview.'(?:-db-[a-f0-9]{10})?\z/';
        foreach ($this->tagNames($database['tags'] ?? []) as $tag) {
            if (preg_match($pattern, $tag, $matches) === 1
                && (! isset($matches[1]) || (int) $matches[1] > 0)) {
                return true;
            }
        }

        return false;
    }

    private function ownedServices(CoolifyApiClient $client, string $tag): array
    {
        return \array_values(\array_filter(
            $this->list($client->request('GET', '/services?tag='.\rawurlencode($tag))),
            fn (array $service): bool => $this->hasTag($service, $tag) && $this->hasTag($service, self::MARKER),
        ));
    }

    /** @return array<int, string> */
    private function desiredDomains(object $project, object $profile): array
    {
        $domains = [];
        $domain = $this->profileValue($profile, 'domain');
        if ($domain !== '') {
            $domains[] = $this->domainSchemeForSsl($project, $domain);
        }
        if (\method_exists($profile, 'aliases')) {
            foreach ($profile->aliases() as $alias) {
                if (\is_string($alias) && $alias !== '') {
                    $domains[] = $this->domainSchemeForSsl($project, $alias);
                }
            }
        }
        foreach ($this->redirects($project) as $redirect) {
            if (! \is_object($redirect) || (\method_exists($redirect, 'enabled') && ! $redirect->enabled()) || $this->redirectDirection($redirect) === null) {
                continue;
            }
            foreach (['from', 'to'] as $field) {
                $host = $this->redirectHost((string) $redirect->$field());
                if ($host !== null) {
                    $scheme = $this->sslCertificateEnabled($project) ? 'https://' : 'http://';
                    $domains[] = $scheme.$host;
                }
            }
        }

        return \array_values(\array_unique($domains));
    }

    private function domainSchemeForSsl(object $project, string $domain): string
    {
        if ($this->sslCertificateEnabled($project)) {
            return $domain;
        }

        return \preg_replace('/^https:\/\//i', 'http://', $domain) ?? $domain;
    }

    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    private function previewDomainSettingsForSsl(object $project, object $profile, array $settings): array
    {
        $pullRequestId = $this->pullRequestId($profile);
        $resolveDomain = fn (string $domain): string => $this->domainSchemeForSsl(
            $project,
            \str_replace('${GITHUB_PR_NUMBER}', $pullRequestId, \trim($domain)),
        );
        if (\is_string($settings['domains'] ?? null)) {
            $settings['domains'] = \implode(',', \array_map(
                $resolveDomain,
                \preg_split('/\s*,\s*/', $settings['domains'], -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ));
        }
        if (\is_array($settings['docker_compose_domains'] ?? null)) {
            foreach ($settings['docker_compose_domains'] as &$entry) {
                if (! \is_array($entry) || ! \is_string($entry['domains'] ?? null)) {
                    continue;
                }
                $entry['domains'] = \implode(',', \array_map(
                    $resolveDomain,
                    \preg_split('/\s*,\s*/', $entry['domains'], -1, PREG_SPLIT_NO_EMPTY) ?: [],
                ));
            }
            unset($entry);
        }

        return $settings;
    }

    private function sslCertificateEnabled(object $project): bool
    {
        return ! \method_exists($project, 'ssl')
            || ! \method_exists($project->ssl(), 'enabled')
            || $project->ssl()->enabled();
    }

    /** @return array<string|int, mixed> */
    private function redirects(object $project): array
    {
        return \method_exists($project, 'redirects') && \is_array($project->redirects()) ? $project->redirects() : [];
    }

    private function redirectDirection(object $redirect): ?string
    {
        if (! \method_exists($redirect, 'from') || ! \method_exists($redirect, 'to')) {
            return null;
        }
        $from = $this->redirectHost($redirect->from());
        $to = $this->redirectHost($redirect->to());
        if ($from === null || $to === null) {
            return null;
        }
        if (\str_starts_with($to, 'www.') && $from === \substr($to, 4)) {
            return 'www';
        }
        if (\str_starts_with($from, 'www.') && $to === \substr($from, 4)) {
            return 'non-www';
        }

        return null;
    }

    private function isPathRedirect(object $redirect): bool
    {
        if (! \method_exists($redirect, 'from') || ! \method_exists($redirect, 'to')) {
            return false;
        }

        foreach ([$redirect->from(), $redirect->to()] as $url) {
            if (! \is_string($url) || ! \str_starts_with($url, '/')) {
                return false;
            }
            $parts = \parse_url($url);
            if (! \is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
                || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
                return false;
            }
        }

        return true;
    }

    private function supportsRedirectType(object $redirect): bool
    {
        if (! \method_exists($redirect, 'type')) {
            return true;
        }

        return \in_array(\strtolower((string) $redirect->type()), ['301', '302', 'permanent', 'temporary'], true);
    }

    /** @return array<string|int, object> */
    private function pathRedirects(object $project): array
    {
        return \array_filter(
            $this->redirects($project),
            fn (mixed $redirect): bool => \is_object($redirect)
                && (! \method_exists($redirect, 'enabled') || $redirect->enabled())
                && $this->redirectDirection($redirect) === null
                && $this->isPathRedirect($redirect),
        );
    }

    /** @param array<string|int, object> $redirects */
    private function appendPathRedirectLabels(string $existing, array $redirects, object $project, object $profile, bool $escapeLabels): string
    {
        $labels = [];
        foreach (\preg_split('/\\r\\n|\\r|\\n/', $existing) ?: [] as $line) {
            if (\trim($line) === '') {
                continue;
            }
            $separator = \strpos($line, '=');
            if ($separator === false) {
                $labels[] = $line;
                continue;
            }
            $labels[\substr($line, 0, $separator)] = \substr($line, $separator + 1);
        }

        $middlewareNames = [];
        $prefix = 'shipper-'.$this->projectProfileHash($project, $profile).'-redirect-';
        foreach ($redirects as $name => $redirect) {
            $middleware = $prefix.$this->slug((string) $name);
            $from = \preg_quote((string) $redirect->from(), '~');
            $to = (string) $redirect->to();
            $regex = '^(https?://[^/]+)'.$from.'(/.*|\\?.*)?$';
            $replacement = '$1'.$to.'$2';
            if (! $escapeLabels) {
                $regex = \str_replace('$', '$$', $regex);
                $replacement = \str_replace('$', '$$', $replacement);
            }
            $permanent = \in_array(\strtolower((string) (\method_exists($redirect, 'type') ? $redirect->type() : '301')), ['301', 'permanent'], true);
            $middlewareNames[] = $middleware;
            $generatedLabels = [
                'traefik.http.middlewares.'.$middleware.'.redirectregex.regex' => $regex,
                'traefik.http.middlewares.'.$middleware.'.redirectregex.replacement' => $replacement,
                'traefik.http.middlewares.'.$middleware.'.redirectregex.permanent' => $permanent ? 'true' : 'false',
            ];
            foreach ($generatedLabels as $key => $value) {
                if (isset($labels[$key]) && $labels[$key] !== $value) {
                    throw new \InvalidArgumentException("Coolify custom label '{$key}' conflicts with Shipper redirect '{$name}'.");
                }
                $labels[$key] = $value;
            }
        }

        $attachmentKey = 'coolify.traefik.middlewares';
        $existingMiddlewares = isset($labels[$attachmentKey]) ? \array_filter(\explode(',', (string) $labels[$attachmentKey])) : [];
        $labels[$attachmentKey] = \implode(',', \array_values(\array_unique([...$existingMiddlewares, ...$middlewareNames])));

        $lines = [];
        foreach ($labels as $key => $value) {
            $lines[] = \is_int($key) ? $value : $key.'='.$value;
        }

        return \implode("\n", $lines);
    }

    private function redirectHost(string $url): ?string
    {
        $parts = \parse_url($url);
        if (! \is_array($parts) || ! \is_string($parts['host'] ?? null)
            || ! \in_array(\strtolower((string) ($parts['scheme'] ?? 'https')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return \strtolower($parts['host']);
    }

    private function configuredRedirectDirection(object $project): ?string
    {
        $directions = [];
        foreach ($this->redirects($project) as $redirect) {
            if (\is_object($redirect) && (! \method_exists($redirect, 'enabled') || $redirect->enabled())) {
                $candidate = $this->redirectDirection($redirect);
                if ($candidate !== null) {
                    $directions[$candidate] = true;
                }
            }
        }

        return \count($directions) === 1 ? (string) \array_key_first($directions) : null;
    }

    private function sslEnabled(object $project): bool
    {
        if (! \method_exists($project, 'ssl')) {
            return true;
        }
        $ssl = $project->ssl();

        return (! \method_exists($ssl, 'enabled') || $ssl->enabled())
            && (! \method_exists($ssl, 'forceHttps') || $ssl->forceHttps());
    }

    private function previewDeploymentsEnabled(object $profile): bool
    {
        $application = $this->effectiveApplicationOptions($profile);

        return $this->pullRequestId($profile) !== ''
            || (\is_array($application) && ($application['is_preview_deployments_enabled'] ?? false) === true);
    }

    private function isSupportedCronFrequency(string $frequency): bool
    {
        $frequency = \trim($frequency);
        if (\in_array(\strtolower($frequency), [
            'every_minute', 'hourly', '@hourly', 'daily', '@daily', '@midnight',
            'weekly', '@weekly', 'monthly', '@monthly', 'yearly', '@yearly', '@annually',
        ], true)) {
            return true;
        }

        return \preg_match('/^\S+\s+\S+\s+\S+\s+\S+\s+\S+$/D', $frequency) === 1;
    }

    /** @return list<string> */
    private function validateStorageBackupSchedules(mixed $schedules, string $resourceType, mixed $storages = []): array
    {
        if (! \is_array($schedules)) {
            return ["Coolify {$resourceType} storage_backups must map mount paths to backup settings."];
        }
        $errors = [];
        $allowed = [
            'frequency', 'enabled', 'save_s3', 'disable_local_backup', 'stop_during_backup', 's3_storage_uuid',
            'retention_amount_locally', 'retention_days_locally', 'retention_max_storage_locally',
            'retention_amount_s3', 'retention_days_s3', 'retention_max_storage_s3', 'timeout',
        ];
        $booleans = ['enabled', 'save_s3', 'disable_local_backup', 'stop_during_backup'];
        $integers = ['retention_amount_locally', 'retention_days_locally', 'retention_amount_s3', 'retention_days_s3', 'timeout'];
        $numbers = ['retention_max_storage_locally', 'retention_max_storage_s3'];
        foreach ($schedules as $mountPath => $schedule) {
            if (! \is_string($mountPath) || \trim($mountPath) === '' || ! \is_array($schedule)) {
                $errors[] = "Coolify {$resourceType} storage backup entries need a mount-path key and a settings map.";
                continue;
            }
            if (! \is_string($schedule['frequency'] ?? null) || ! $this->isSupportedCronFrequency($schedule['frequency'])) {
                $errors[] = "Coolify {$resourceType} storage backup for '{$mountPath}' needs a supported frequency.";
            } elseif (\strlen($schedule['frequency']) > 255) {
                $errors[] = "Coolify {$resourceType} storage backup frequency for '{$mountPath}' exceeds 255 characters.";
            }
            foreach ($schedule as $key => $value) {
                if (! \is_string($key) || ! \in_array($key, $allowed, true)) {
                    $errors[] = "Unsupported Coolify storage backup setting '{$key}'.";
                } elseif (\in_array($key, $booleans, true) && ! \is_bool($value)) {
                    $errors[] = "Coolify storage backup setting '{$key}' must be boolean.";
                } elseif (\in_array($key, $integers, true) && ! \is_int($value)) {
                    $errors[] = "Coolify storage backup setting '{$key}' must be an integer.";
                } elseif (\in_array($key, $numbers, true) && ! (\is_int($value) || \is_float($value))) {
                    $errors[] = "Coolify storage backup setting '{$key}' must be numeric.";
                } elseif ($key === 's3_storage_uuid' && $value !== null && ! \is_string($value)) {
                    $errors[] = "Coolify storage backup setting '{$key}' must be a string or null.";
                }
            }
            if (isset($schedule['timeout']) && ($schedule['timeout'] < 60 || $schedule['timeout'] > 36000)) {
                $errors[] = "Coolify storage backup timeout for '{$mountPath}' must be between 60 and 36000 seconds.";
            }
            if (($schedule['save_s3'] ?? false) === true
                && (! \is_string($schedule['s3_storage_uuid'] ?? null) || $schedule['s3_storage_uuid'] === '')) {
                $errors[] = "Coolify storage backup for '{$mountPath}' needs s3_storage_uuid when save_s3 is enabled.";
            }
            if (\is_array($storages)) {
                foreach ($storages as $storage) {
                    if (\is_array($storage) && ($storage['mount_path'] ?? null) === $mountPath
                        && ($storage['type'] ?? null) === 'file' && ($storage['is_directory'] ?? false) !== true) {
                        $errors[] = "Coolify {$resourceType} storage backup for '{$mountPath}' requires a directory file mount; host file mounts are not backup targets.";
                        break;
                    }
                }
            }
            foreach (['retention_amount_locally', 'retention_amount_s3'] as $field) {
                if (isset($schedule[$field]) && ($schedule[$field] < 0 || $schedule[$field] > 10000)) {
                    $errors[] = "Coolify storage backup setting '{$field}' must be between 0 and 10000.";
                }
            }
            foreach (['retention_days_locally', 'retention_days_s3'] as $field) {
                if (isset($schedule[$field]) && ($schedule[$field] < 0 || $schedule[$field] > 2147483647)) {
                    $errors[] = "Coolify storage backup setting '{$field}' is out of range.";
                }
            }
            foreach (['retention_max_storage_locally', 'retention_max_storage_s3'] as $field) {
                if (isset($schedule[$field]) && ($schedule[$field] < 0 || $schedule[$field] > 9999999999)) {
                    $errors[] = "Coolify storage backup setting '{$field}' is out of range.";
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateServiceStorages(mixed $storages): array
    {
        if (! \is_array($storages)) {
            return ['Coolify service storages must be a list of Compose component mounts.'];
        }
        $errors = [];
        $seen = [];
        foreach ($storages as $index => $storage) {
            if (! \is_array($storage)
                || ! \is_string($storage['component'] ?? null) || \trim($storage['component']) === ''
                || ! \in_array($storage['type'] ?? null, ['persistent', 'file'], true)
                || ! \is_string($storage['mount_path'] ?? null) || \trim($storage['mount_path']) === '') {
                $errors[] = "Coolify service storage '{$index}' needs component, type=persistent|file, and mount_path.";
                continue;
            }
            $identity = $storage['component'].'|'.$storage['mount_path'];
            if (isset($seen[$identity])) {
                $errors[] = "Coolify service storage '{$index}' duplicates a component and mount_path pair.";
            }
            $seen[$identity] = true;
            if ($storage['type'] === 'persistent'
                && (! \is_string($storage['name'] ?? null) || \trim($storage['name']) === '')) {
                $errors[] = "Coolify service persistent storage '{$index}' requires a volume name.";
            }
            if ($storage['type'] === 'file' && ($storage['is_directory'] ?? false)
                && (! \is_string($storage['fs_path'] ?? null) || \trim($storage['fs_path']) === '')) {
                $errors[] = "Coolify service directory mount '{$index}' requires fs_path.";
            }
            foreach (['is_directory', 'is_preview_suffix_enabled'] as $field) {
                if (isset($storage[$field]) && ! \is_bool($storage[$field])) {
                    $errors[] = "Coolify service storage field '{$field}' must be boolean.";
                }
            }
            foreach (['name', 'content', 'fs_path', 'host_path'] as $field) {
                if (isset($storage[$field]) && ! \is_string($storage[$field])) {
                    $errors[] = "Coolify service storage field '{$field}' must be a string.";
                }
            }
            foreach ($storage as $key => $_value) {
                if (! \in_array($key, ['component', 'type', 'name', 'mount_path', 'content', 'is_directory', 'fs_path', 'host_path', 'is_preview_suffix_enabled'], true)) {
                    $errors[] = "Unsupported Coolify service storage field '{$key}'.";
                }
            }
        }

        return $errors;
    }

    private function repositoryUrl(object $project): string
    {
        if (! \method_exists($project, 'repository')) {
            return '';
        }
        $repository = $project->repository();
        if (\is_object($repository)) {
            $url = \method_exists($repository, 'url') ? $repository->url() : '';

            return \is_string($url) ? \trim($url) : '';
        }
        if (! \is_array($repository)) {
            return '';
        }
        $url = $repository['url'] ?? $repository['git_repository'] ?? '';
        if (\is_string($url) && \trim($url) !== '') {
            return \trim($url);
        }
        $provider = \is_string($repository['provider'] ?? null) ? \strtolower(\trim($repository['provider'])) : '';
        $name = \is_string($repository['name'] ?? null) ? \trim($repository['name'], " \t\n\r\0\x0B/") : '';
        if ($name === '' || \preg_match('/[\r\n]/', $name) === 1) {
            return '';
        }
        $hosts = [
            'github' => 'https://github.com/',
            'gitlab' => 'https://gitlab.com/',
            'bitbucket' => 'https://bitbucket.org/',
            'gitea' => 'https://gitea.com/',
        ];
        if (! isset($hosts[$provider])) {
            return '';
        }

        return $hosts[$provider].$name.(\str_ends_with($name, '.git') ? '' : '.git');
    }

    private function sourceType(object $profile): string
    {
        $configured = \strtolower($this->profileOption($profile, 'source_type', $this->stringConfig('source_type')));
        if ($configured !== '') {
            return $configured === 'dockerimage' ? 'docker_image' : $configured;
        }
        if ($this->dockerImage($profile) !== '') {
            return 'docker_image';
        }
        if ($this->dockerfileContent($profile) !== '') {
            return 'dockerfile';
        }

        return 'git';
    }

    private function dockerImage(object $profile): string
    {
        return $this->profileOption($profile, 'docker_image', $this->profileOption(
            $profile,
            'docker_registry_image_name',
            $this->stringConfig('docker_image', $this->stringConfig('docker_registry_image_name')),
        ));
    }

    private function dockerfileContent(object $profile): string
    {
        return $this->profileOption($profile, 'dockerfile', $this->stringConfig('dockerfile'));
    }

    private function configuredServerUuid(object $profile): string
    {
        $configured = $this->profileOption($profile, 'server_uuid', $this->stringConfig('server_uuid'));
        if ($configured !== '') {
            return $configured;
        }
        if (! \method_exists($profile, 'server')) {
            return '';
        }
        $server = $profile->server();
        if (! \is_object($server) || ! \method_exists($server, 'isExisting') || ! $server->isExisting()
            || ! \method_exists($server, 'id') || ! \is_string($server->id())) {
            return '';
        }

        return $server->id();
    }

    /** @param array<string, mixed> $spec @return list<string> */
    private function validateServerLifecycleSpec(array $spec): array
    {
        $provider = \strtolower(\is_string($spec['cloud_provider'] ?? null) ? $spec['cloud_provider'] : '');
        if ($provider === '') {
            $errors = [];
            if (! \is_string($spec['name'] ?? null) || \trim($spec['name']) === '') {
                $errors[] = 'Coolify create server mode requires infrastructure.server.spec.name.';
            }
            if (! \is_string($spec['ip'] ?? null) || \trim($spec['ip']) === '') {
                $errors[] = 'Coolify create server mode for an existing SSH host requires infrastructure.server.spec.ip.';
            }
            if (isset($spec['user']) && (! \is_string($spec['user']) || \trim($spec['user']) === '')) {
                $errors[] = 'Coolify server user must be a non-empty string.';
            }
            if (isset($spec['port']) && (! \is_int($spec['port']) || $spec['port'] < 1 || $spec['port'] > 65535)) {
                $errors[] = 'Coolify server port must be an integer between 1 and 65535.';
            }
            if (isset($spec['server_role']) && ! \in_array($spec['server_role'], ['deployment', 'build', 'both'], true)) {
                $errors[] = 'Coolify server_role must be deployment, build, or both.';
            }
            if (isset($spec['proxy_type']) && ! \in_array($spec['proxy_type'], ['traefik', 'caddy', 'none'], true)) {
                $errors[] = 'Coolify proxy_type must be traefik, caddy, or none.';
            }
            if (isset($spec['instant_validate']) && ! \is_bool($spec['instant_validate'])) {
                $errors[] = 'Coolify instant_validate must be boolean.';
            }
            foreach (['description', 'private_key_uuid'] as $field) {
                if (isset($spec[$field]) && ! \is_string($spec[$field])) {
                    $errors[] = "Coolify server {$field} must be a string.";
                }
            }
            foreach (['cloud_provider', 'cloud_provider_token_uuid', 'location', 'server_type', 'image', 'region', 'size', 'plan', 'os_id', 'enable_ipv4', 'enable_ipv6', 'enable_backups', 'monitoring', 'disable_public_ipv4', 'hetzner_ssh_key_ids', 'hetzner_firewall_ids', 'hetzner_network_ids', 'digitalocean_ssh_key_ids', 'vultr_ssh_key_ids', 'cloud_init_script', 'cloud_init_script_uuid'] as $cloudField) {
                if (\array_key_exists($cloudField, $spec)) {
                    $errors[] = "Coolify existing SSH server registration does not accept '{$cloudField}'.";
                }
            }
            foreach ($spec as $field => $_value) {
                if (! \is_string($field) || ! \in_array($field, ['name', 'ip', 'user', 'port', 'server_role', 'proxy_type', 'instant_validate', 'description', 'private_key_uuid'], true)) {
                    $errors[] = "Unsupported Coolify existing server registration setting '{$field}'.";
                }
            }

            return $errors;
        }
        if (! \in_array($provider, ['hetzner', 'digitalocean', 'vultr'], true)) {
            return ["Coolify server provisioning does not support cloud_provider '{$provider}'."];
        }

        $errors = [];
        foreach (['cloud_provider_token_uuid', 'private_key_uuid'] as $field) {
            if (! \is_string($spec[$field] ?? null) || \trim($spec[$field]) === '') {
                $errors[] = "Coolify {$provider} create server mode requires infrastructure.server.spec.{$field}.";
            }
        }
        if (! \is_string($spec['name'] ?? null) || \trim($spec['name']) === '') {
            $errors[] = "Coolify {$provider} create server mode requires infrastructure.server.spec.name.";
        }
        $required = match ($provider) {
            'hetzner' => ['location', 'server_type', 'image'],
            'digitalocean' => ['region', 'size', 'image'],
            'vultr' => ['region', 'plan', 'os_id'],
        };
        foreach ($required as $field) {
            $valid = $field === 'image' || $field === 'os_id'
                ? (\is_int($spec[$field] ?? null) || ($provider === 'digitalocean' && \is_string($spec[$field] ?? null)))
                : (\is_string($spec[$field] ?? null) && \trim($spec[$field]) !== '');
            if (! $valid) {
                $errors[] = "Coolify {$provider} create server mode requires a valid infrastructure.server.spec.{$field}.";
            }
        }
        foreach (['enable_ipv4', 'enable_ipv6', 'enable_backups', 'monitoring', 'disable_public_ipv4', 'instant_validate'] as $field) {
            if (\array_key_exists($field, $spec) && ! \is_bool($spec[$field])) {
                $errors[] = "Coolify server setting '{$field}' must be boolean.";
            }
        }
        $stringFields = ['cloud_provider_token_uuid', 'location', 'server_type', 'region', 'size', 'plan', 'name', 'private_key_uuid', 'cloud_init_script', 'cloud_init_script_uuid'];
        foreach ($stringFields as $field) {
            if (\array_key_exists($field, $spec) && ! \is_string($spec[$field])) {
                $errors[] = "Coolify server setting '{$field}' must be a string.";
            }
        }
        $providerFields = match ($provider) {
            'hetzner' => ['cloud_provider_token_uuid', 'location', 'server_type', 'image', 'name', 'private_key_uuid', 'enable_ipv4', 'enable_ipv6', 'enable_backups', 'hetzner_ssh_key_ids', 'hetzner_firewall_ids', 'hetzner_network_ids', 'cloud_init_script', 'cloud_init_script_uuid', 'instant_validate'],
            'digitalocean' => ['cloud_provider_token_uuid', 'region', 'size', 'image', 'name', 'private_key_uuid', 'enable_ipv6', 'monitoring', 'digitalocean_ssh_key_ids', 'cloud_init_script', 'cloud_init_script_uuid', 'instant_validate'],
            'vultr' => ['cloud_provider_token_uuid', 'region', 'plan', 'os_id', 'name', 'private_key_uuid', 'disable_public_ipv4', 'enable_ipv6', 'vultr_ssh_key_ids', 'cloud_init_script', 'cloud_init_script_uuid', 'instant_validate'],
        };
        foreach (['image', 'os_id'] as $field) {
            if (\in_array($field, $providerFields, true) && \array_key_exists($field, $spec)
                && ! (\is_int($spec[$field]) || ($provider === 'digitalocean' && \is_string($spec[$field])))) {
                $errors[] = "Coolify {$provider} server setting '{$field}' has an invalid type.";
            }
        }
        $arrayFields = \array_values(\array_intersect(['hetzner_ssh_key_ids', 'hetzner_firewall_ids', 'hetzner_network_ids', 'digitalocean_ssh_key_ids', 'vultr_ssh_key_ids'], $providerFields));
        foreach ($arrayFields as $field) {
            if (\array_key_exists($field, $spec)
                && (! \is_array($spec[$field]) || ! \array_is_list($spec[$field])
                    || \array_filter($spec[$field], static fn (mixed $id): bool => ! \is_int($id) && ! (\is_string($id) && \ctype_digit($id))) !== [])) {
                $errors[] = "Coolify server setting '{$field}' must be a list of numeric IDs.";
            }
        }
        foreach (['cloud_provider', ...$providerFields] as $field) {
            if ($field === 'cloud_init_script_uuid' && isset($spec['cloud_init_script']) && $spec['cloud_init_script'] !== '') {
                $errors[] = 'Specify either cloud_init_script or cloud_init_script_uuid, not both.';
            }
        }
        foreach ($spec as $field => $_value) {
            if (! \is_string($field) || ! \in_array($field, ['cloud_provider', ...$providerFields], true)) {
                $errors[] = "Unsupported Coolify server provisioning setting '{$field}'.";
            }
        }

        return $errors;
    }

    private function profileOption(object $profile, string $key, string $default = ''): string
    {
        $config = $this->profileOptions($profile);
        $value = $config[$key] ?? $default;

        return \is_string($value) ? $value : $default;
    }

    private function pullRequestIdValue(object $profile): mixed
    {
        $options = $this->profileOptions($profile);

        return $options['pull_request_id'] ?? $this->config['pull_request_id'] ?? null;
    }

    private function pullRequestId(object $profile): string
    {
        $value = $this->pullRequestIdValue($profile);
        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) ? \trim($value) : '';
    }

    private function deployResource(CoolifyApiClient $client, string $resourceUuid, string $pullRequestId, string $dockerTag = '', bool $force = false): void
    {
        $path = '/deploy?uuid='.\rawurlencode($resourceUuid);
        if ($force) {
            $path .= '&force=true';
        }
        if ($pullRequestId !== '') {
            $path .= '&pr='.\rawurlencode($pullRequestId);
        }
        if ($dockerTag !== '') {
            $path .= '&docker_tag=' .\rawurlencode($dockerTag);
        }
        $response = $client->request('POST', $path);
        if ($pullRequestId !== '') {
            foreach ($response['deployments'] ?? [] as $deployment) {
                if (! \is_array($deployment)
                    || (string) ($deployment['resource_uuid'] ?? '') !== $resourceUuid) {
                    continue;
                }
                $message = (string) ($deployment['message'] ?? '');
                if (\stripos($message, 'pull request') !== false && \stripos($message, 'not found') !== false) {
                    throw new \RuntimeException("Coolify could not deploy pull request preview: {$message}");
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function effectiveApplicationOptions(object $profile): array
    {
        $fields = \array_flip($this->applicationOptionFields());
        $profileOptions = $this->profileOptions($profile);
        $options = \array_merge(
            \array_intersect_key($this->config, $fields),
            \array_intersect_key($profileOptions, $fields),
        );
        $nested = $profileOptions['application'] ?? $this->config['application'] ?? [];
        if (\is_array($nested)) {
            $options = \array_merge($options, $nested);
        }

        return $options;
    }

    private function effectiveBuildPack(object $profile): string
    {
        $value = $this->effectiveApplicationOptions($profile)['build_pack'] ?? 'nixpacks';

        return \is_string($value) ? $value : 'nixpacks';
    }

    /** @return list<string> */
    private function applicationOptionFields(): array
    {
        return [
            'description', 'destination_uuid', 'noindex_domains', 'railpack_php_extensions', 'git_commit_sha', 'build_pack', 'docker_registry_image_name',
            'docker_registry_image_tag', 'dockerfile', 'dockerfile_target_build', 'static_image', 'is_static', 'is_spa',
            'is_auto_deploy_enabled', 'is_force_https_enabled', 'is_preview_deployments_enabled', 'autogenerate_domain',
            'use_build_server',
            'is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password',
            'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings',
            'base_directory', 'publish_directory', 'dockerfile_location', 'docker_compose_location',
            'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host',
            'health_check_method', 'health_check_return_code', 'health_check_scheme',
            'health_check_response_text', 'health_check_interval', 'health_check_timeout',
            'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap',
            'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset',
            'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command',
            'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container',
            'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket',
            'manual_webhook_secret_gitea', 'redirect', 'instant_deploy', 'docker_compose_custom_start_command',
            'docker_compose_custom_build_command', 'docker_compose_domains', 'watch_paths',
            'use_build_secrets', 'is_git_submodules_enabled', 'is_git_lfs_enabled',
            'is_git_shallow_clone_enabled', 'disable_build_cache', 'inject_build_args_to_dockerfile',
            'include_source_commit_in_build', 'is_env_sorting_enabled', 'is_pr_deployments_public_enabled',
            'stop_grace_period', 'docker_images_to_keep', 'is_gzip_enabled', 'is_stripprefix_enabled',
            'is_raw_compose_deployment_enabled', 'is_log_drain_enabled', 'is_gpu_enabled', 'gpu_driver',
            'gpu_count', 'gpu_device_ids', 'gpu_options', 'is_consistent_container_name_enabled',
            'custom_internal_name', 'custom_container_name_prefix', 'preview_url_template', 'max_restart_count',
            'connect_to_docker_network', 'force_domain_override', 'is_container_label_escape_enabled',
            'swarm_replicas', 'swarm_placement_constraints',
            'is_preserve_repository_enabled',
            'custom_nginx_configuration', 'tags',
        ];
    }

    /** @return list<string> */
    private function validateApplicationOptions(mixed $options, string $sourceType, string $buildPack): array
    {
        if (! \is_array($options)) {
            return ['Coolify application settings must be a map.'];
        }
        $errors = [];
        $strings = [
            'description', 'destination_uuid', 'git_commit_sha', 'build_pack', 'docker_registry_image_name',
            'docker_registry_image_tag', 'dockerfile', 'dockerfile_target_build', 'static_image',
            'http_basic_auth_username', 'http_basic_auth_password', 'install_command',
            'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory',
            'publish_directory', 'dockerfile_location', 'docker_compose_location', 'health_check_path',
            'health_check_port', 'health_check_host', 'health_check_method', 'health_check_scheme',
            'health_check_response_text', 'limits_memory', 'limits_memory_swap', 'limits_memory_reservation',
            'limits_cpus', 'limits_cpuset', 'custom_labels', 'custom_docker_run_options',
            'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command',
            'pre_deployment_command_container', 'manual_webhook_secret_github', 'manual_webhook_secret_gitlab',
            'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'redirect',
            'docker_compose_custom_start_command', 'docker_compose_custom_build_command',
            'watch_paths', 'gpu_driver', 'gpu_count', 'gpu_device_ids', 'gpu_options', 'custom_internal_name',
            'custom_container_name_prefix', 'preview_url_template',
            'custom_nginx_configuration',
            'swarm_placement_constraints',
        ];
        $booleans = [
            'is_static', 'is_spa', 'is_auto_deploy_enabled', 'is_force_https_enabled',
            'is_preview_deployments_enabled', 'autogenerate_domain', 'is_http_basic_auth_enabled', 'health_check_enabled', 'instant_deploy', 'use_build_server',
            'use_build_secrets', 'is_git_submodules_enabled', 'is_git_lfs_enabled', 'is_git_shallow_clone_enabled',
            'disable_build_cache', 'inject_build_args_to_dockerfile', 'include_source_commit_in_build',
            'is_env_sorting_enabled', 'is_pr_deployments_public_enabled', 'is_gzip_enabled',
            'is_stripprefix_enabled', 'is_raw_compose_deployment_enabled', 'is_log_drain_enabled',
            'is_gpu_enabled', 'is_consistent_container_name_enabled', 'connect_to_docker_network',
            'force_domain_override', 'is_container_label_escape_enabled', 'is_preserve_repository_enabled',
        ];
        $integers = [
            'health_check_return_code', 'health_check_interval', 'health_check_timeout', 'health_check_retries',
            'health_check_start_period', 'limits_memory_swappiness', 'limits_cpu_shares', 'stop_grace_period',
            'docker_images_to_keep', 'max_restart_count',
            'swarm_replicas',
        ];
        $objectLists = ['docker_compose_domains'];
        foreach ($options as $key => $value) {
            if (! \is_string($key) || ! \in_array($key, $this->applicationOptionFields(), true)) {
                $errors[] = "Unsupported Coolify application setting '{$key}'.";
            } elseif (\in_array($key, $strings, true) && ! \is_string($value)) {
                $errors[] = "Coolify application setting '{$key}' must be a string.";
            } elseif (\in_array($key, $booleans, true) && ! \is_bool($value)) {
                $errors[] = "Coolify application setting '{$key}' must be boolean.";
            } elseif (\in_array($key, $integers, true) && ! \is_int($value)) {
                $errors[] = "Coolify application setting '{$key}' must be an integer.";
            } elseif (\in_array($key, $objectLists, true)
                && (! \is_array($value) || ! \array_is_list($value)
                    || \array_filter($value, static fn (mixed $item): bool => ! \is_array($item)) !== [])) {
                $errors[] = "Coolify application setting '{$key}' must be a list of objects.";
            } elseif ($key === 'noindex_domains'
                && (! \is_array($value) || ! \array_is_list($value)
                    || \array_filter($value, static fn (mixed $domain): bool => ! \is_string($domain) || \trim($domain) === '') !== [])) {
                $errors[] = "Coolify application setting '{$key}' must be a list of strings.";
            } elseif ($key === 'tags'
                && (! \is_array($value) || ! \array_is_list($value)
                    || \array_filter($value, static fn (mixed $tag): bool => ! \is_string($tag) || \mb_strlen(\trim($tag)) < 2) !== [])) {
                $errors[] = "Coolify application setting '{$key}' must be a list of strings at least two characters long.";
            } elseif ($key === 'destination_uuid' && \trim($value) === '') {
                $errors[] = "Coolify application setting '{$key}' must be a non-empty UUID.";
            }
            if ($key === 'custom_docker_run_options' && \is_string($value)) {
                $errors = [...$errors, ...$this->validateCustomDockerRunOptions($value)];
            }
        }
        if ($sourceType === 'docker_image') {
            $buildOnly = [
                'git_commit_sha', 'build_pack', 'dockerfile', 'dockerfile_target_build', 'static_image',
                'is_static', 'is_spa', 'install_command', 'build_command', 'start_command', 'base_directory',
                'publish_directory', 'dockerfile_location', 'docker_compose_location',
                'docker_compose_custom_start_command', 'docker_compose_custom_build_command',
                'docker_compose_domains', 'watch_paths',
            ];
            foreach ($buildOnly as $key) {
                if (\array_key_exists($key, $options)) {
                    $errors[] = "Coolify Docker Image source does not accept application setting '{$key}'.";
                }
            }
        }
        if ($sourceType !== 'git' && \array_key_exists('is_auto_deploy_enabled', $options)) {
            $errors[] = "Coolify {$sourceType} source does not accept application setting 'is_auto_deploy_enabled'; automatic deployment is available only for Git sources.";
        }
        if ($sourceType === 'dockerfile') {
            foreach (['git_commit_sha', 'build_pack', 'dockerfile_target_build', 'static_image', 'is_static', 'is_spa', 'install_command', 'build_command', 'start_command', 'publish_directory', 'dockerfile_location', 'docker_compose_location', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'watch_paths'] as $key) {
                if (\array_key_exists($key, $options)) {
                    $errors[] = "Standalone Coolify Dockerfile source does not accept application setting '{$key}'.";
                }
            }
        }
        if ($sourceType === 'git' && $buildPack !== 'static') {
            foreach (['static_image', 'is_static', 'is_spa', 'publish_directory'] as $key) {
                if (\array_key_exists($key, $options)) {
                    $errors[] = "Coolify application setting '{$key}' requires the Git static build pack.";
                }
            }
        }
        foreach (['stop_grace_period' => [1, 3600], 'docker_images_to_keep' => [0, 100], 'max_restart_count' => [0, PHP_INT_MAX], 'swarm_replicas' => [1, PHP_INT_MAX]] as $field => [$minimum, $maximum]) {
            if (isset($options[$field]) && \is_int($options[$field])
                && ($options[$field] < $minimum || $options[$field] > $maximum)) {
                $errors[] = "Coolify application setting '{$field}' must be between {$minimum} and {$maximum}.";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateCustomDockerRunOptions(string $options): array
    {
        $tokens = [];
        $token = '';
        $quote = null;
        $escaped = false;
        $started = false;
        for ($index = 0, $length = \strlen($options); $index < $length; $index++) {
            $character = $options[$index];
            if ($escaped) {
                $token .= $character;
                $escaped = false;
                $started = true;
                continue;
            }
            if ($character === '\\' && $quote !== "'") {
                $escaped = true;
                $started = true;
                continue;
            }
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } else {
                    $token .= $character;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $started = true;
            } elseif (\ctype_space($character)) {
                if ($started) {
                    $tokens[] = $token;
                    $token = '';
                    $started = false;
                }
            } else {
                $token .= $character;
                $started = true;
            }
        }
        if ($quote !== null || $escaped) {
            return ['Coolify custom_docker_run_options contains an unsupported or malformed Docker option.'];
        }
        if ($started) {
            $tokens[] = $token;
        }

        $valueOptions = [
            '--ip', '--ip6', '--shm-size', '--dns', '--cap-add', '--cap-drop', '--security-opt', '--user',
            '--sysctl', '--device', '--ulimit', '--gpus', '--hostname', '--entrypoint',
        ];
        $booleanOptions = ['--init', '--privileged'];
        for ($index = 0, $count = \count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            $equals = \strpos($token, '=');
            $option = $equals === false ? $token : \substr($token, 0, $equals);
            if (\in_array($option, $booleanOptions, true)) {
                if ($equals !== false) {
                    return ['Coolify custom_docker_run_options contains an unsupported or malformed Docker option.'];
                }
                continue;
            }
            if (! \in_array($option, $valueOptions, true)) {
                return ['Coolify custom_docker_run_options contains an unsupported or malformed Docker option.'];
            }
            if ($equals !== false) {
                if (\substr($token, $equals + 1) === '') {
                    return ['Coolify custom_docker_run_options contains an unsupported or malformed Docker option.'];
                }
                continue;
            }
            if (! isset($tokens[$index + 1]) || \str_starts_with($tokens[$index + 1], '-')) {
                return ['Coolify custom_docker_run_options contains an unsupported or malformed Docker option.'];
            }
            $index++;
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function profileOptions(object $profile): array
    {
        $config = \method_exists($profile, 'config') ? $profile->config() : [];
        $provider = \is_array($config['coolify'] ?? null) ? $config['coolify'] : [];

        return \array_merge($config, $provider);
    }

    private function profileValue(object $profile, string $key, string $default = ''): string
    {
        $value = \method_exists($profile, 'get') ? $profile->get($key, $default) : $default;

        return \is_string($value) ? $value : $default;
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return \is_string($value) ? $value : $default;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return \is_int($value) ? $value : $default;
    }

    private function booleanConfig(string $key, bool $default): bool
    {
        $value = $this->config[$key] ?? $default;

        return \is_bool($value) ? $value : $default;
    }

    private function resourceName(object $project, object $profile): string
    {
        return $this->slug($this->projectName($project).'-'.$this->profileName($profile));
    }

    private function resourceTag(object $project, ?object $profile): string
    {
        return 'shippercli-'.\substr(\hash('sha256', $this->encodedScope($project, $profile)), 0, 16);
    }

    private function projectProfileHash(object $project, ?object $profile): string
    {
        return \substr(\hash('sha256', $this->encodedScope($project, $profile)), 0, 10);
    }

    private function encodedScope(object $project, ?object $profile): string
    {
        return \json_encode([$this->projectName($project), $profile === null ? null : $this->profileName($profile)], JSON_THROW_ON_ERROR);
    }

    private function projectName(object $project): string
    {
        return \method_exists($project, 'name') ? (string) $project->name() : 'project';
    }

    private function profileName(object $profile): string
    {
        return \method_exists($profile, 'name') ? (string) $profile->name() : 'production';
    }

    private function interpolate(string $value, object $project, ?object $profile): string
    {
        return \str_replace(['${PROJECT_NAME}', '${PROFILE}'], [$this->projectName($project), $profile === null ? '' : $this->profileName($profile)], $value);
    }

    private function slug(string $value): string
    {
        $slug = \strtolower(\trim(\preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '', '-'));

        return \substr($slug !== '' ? $slug : 'shipper-resource', 0, 50);
    }

    private function resourceUuid(array $resource): string
    {
        $uuid = $resource['uuid'] ?? ($resource['data']['uuid'] ?? '');

        return \is_string($uuid) ? $uuid : '';
    }

    /** @return array<int, array<string, mixed>> */
    private function list(array $response): array
    {
        $items = $response['data'] ?? $response['items'] ?? $response;
        if (! \is_array($items)) {
            return [];
        }

        return \array_values(\array_filter($items, 'is_array'));
    }

    private function hasTag(array $resource, string $tag): bool
    {
        $tags = $resource['tags'] ?? [];
        foreach (\is_array($tags) ? $tags : [] as $candidate) {
            if (\is_string($candidate) && $candidate === $tag) {
                return true;
            }
            if (\is_array($candidate) && (($candidate['name'] ?? null) === $tag || ($candidate['tag'] ?? null) === $tag)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function tagNames(array $tags): array
    {
        $names = [];
        foreach ($tags as $candidate) {
            if (\is_string($candidate)) {
                $names[] = $candidate;
            } elseif (\is_array($candidate) && \is_string($candidate['name'] ?? null)) {
                $names[] = $candidate['name'];
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function applicationTags(object $profile, array $existingTags, string $ownershipTag): array
    {
        $configured = $this->effectiveApplicationOptions($profile)['tags'] ?? [];
        $configured = \is_array($configured) ? $this->tagNames($configured) : [];
        $managed = $this->tagNames($existingTags);

        return \array_values(\array_unique([...$managed, ...$configured, $ownershipTag, self::MARKER]));
    }

    private function siteId(string $uuid): int
    {
        return (int) (\hexdec(\substr(\hash('crc32b', $uuid), 0, 7)) ?: 1);
    }

    private function serverOwnershipToken(string $serverId, ?string $cloudProvider = null): string
    {
        $scope = $cloudProvider === null ? 'coolify-server:' : 'coolify-cloud-server:'.$cloudProvider.':';

        return \hash_hmac('sha256', $scope.$serverId, $this->stringConfig('api_token', $this->stringConfig('token')));
    }

    /** @param array<string, mixed> $server @return array<string, mixed> */
    private function serverWithOwnershipMetadata(array $server): array
    {
        $uuid = $this->resourceUuid($server);
        if ($uuid === '') {
            return $server;
        }
        $cloudProvider = $server['cloud_provider'] ?? null;
        $providerFields = [
            'hetzner' => ['hetzner_server_id'],
            'digitalocean' => ['digitalocean_server_id', 'digitalocean_droplet_id'],
            'vultr' => ['vultr_server_id', 'vultr_instance_id'],
        ];
        if (! \is_string($cloudProvider) || ! isset($providerFields[$cloudProvider])) {
            $cloudProvider = null;
            foreach ($providerFields as $provider => $fields) {
                if (\array_filter($fields, static fn (string $field): bool => isset($server[$field])) !== []) {
                    $cloudProvider = $provider;
                    break;
                }
            }
        }
        if ($cloudProvider !== null) {
            $cloudToken = $this->serverOwnershipToken($uuid, $cloudProvider);
            $description = $server['description'] ?? null;
            $isManaged = $description === $this->cloudOwnershipMarker($cloudToken);
            if (\is_string($description) && \preg_match('/^'.\preg_quote($this->cloudOwnershipMarker($cloudToken), '/').' \[shippercli-created-at=([^;\]]+);signature=([a-f0-9]{64})\]$/', $description, $matches) === 1) {
                $expectedSignature = \hash_hmac('sha256', $uuid.'|'.$matches[1], $cloudToken);
                if (\hash_equals($expectedSignature, $matches[2])) {
                    try {
                        new \DateTimeImmutable($matches[1]);
                        $isManaged = true;
                        $server['created_at'] = $matches[1];
                    } catch (\Throwable) {
                        $isManaged = false;
                    }
                }
            }
            $server['ownership_token'] = $isManaged ? $cloudToken : $this->serverOwnershipToken($uuid);
            $server['server_record_only'] = ! $isManaged;

            return $server;
        }

        $server['ownership_token'] = $this->serverOwnershipToken($uuid);

        return $server;
    }

    /** @param array<string, mixed> $applicationOptions */
    private function supportsStaticNginxConfiguration(string $buildPack, array $applicationOptions): bool
    {
        return $buildPack === 'static'
            || (\in_array($buildPack, ['nixpacks', 'railpack'], true)
                && ($applicationOptions['is_static'] ?? false) === true);
    }

    private function cloudOwnershipMarker(string $ownershipToken): string
    {
        return 'Managed by ShipperCLI; ownership='.$ownershipToken;
    }
}
