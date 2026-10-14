<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

/** Git provider source operations exposed by Coolify's REST API. */
trait CoolifySourceOperations
{
    /** @return array<array<string, mixed>> */
    public function coolifyListGitLabApps(): array
    {
        return $this->coolifySourceClient()->request('GET', '/gitlab-apps');
    }

    /** @param array<string, mixed> $configuration
     *  @return array<string, mixed>
     */
    public function coolifyCreateGitLabApp(array $configuration): array
    {
        return $this->coolifySourceClient()->request('POST', '/gitlab-apps', $this->validateGitLabAppPayload($configuration, true));
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public function coolifyUpdateGitLabApp(int $appId, array $changes): array
    {
        $this->coolifyRequirePositiveId($appId, 'GitLab app ID');

        return $this->coolifySourceClient()->request('PATCH', '/gitlab-apps/'.$appId, $this->validateGitLabAppPayload($changes, false));
    }

    public function coolifyDeleteGitLabApp(int $appId): void
    {
        $this->coolifyRequirePositiveId($appId, 'GitLab app ID');
        $this->coolifySourceClient()->request('DELETE', '/gitlab-apps/'.$appId);
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListGitHubApps(): array
    {
        return $this->coolifySourceClient()->request('GET', '/github-apps');
    }

    /** @param array<string, mixed> $configuration
     *  @return array<string, mixed>
     */
    public function coolifyCreateGitHubApp(array $configuration): array
    {
        return $this->coolifySourceClient()->request('POST', '/github-apps', $this->validateGitHubAppPayload($configuration, true));
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public function coolifyUpdateGitHubApp(int $appId, array $changes): array
    {
        $this->coolifyRequirePositiveId($appId, 'GitHub app ID');

        return $this->coolifySourceClient()->request('PATCH', '/github-apps/'.$appId, $this->validateGitHubAppPayload($changes, false));
    }

    /** @return array<string, mixed> */
    public function coolifyDeleteGitHubApp(int $appId): array
    {
        $this->coolifyRequirePositiveId($appId, 'GitHub app ID');

        return $this->coolifySourceClient()->request('DELETE', '/github-apps/'.$appId);
    }

    /** @return array<string, mixed> */
    public function coolifyGitHubAppRepositories(int $appId): array
    {
        $this->coolifyRequirePositiveId($appId, 'GitHub app ID');

        return $this->coolifySourceClient()->request('GET', '/github-apps/'.$appId.'/repositories');
    }

    /** @param list<array<string, mixed>> $variables
     *  @return array<array<string, mixed>>
     */
    public function coolifyBulkUpdateApplicationEnvironmentVariables(string $applicationUuid, array $variables): array
    {
        $applicationUuid = $this->coolifyRequireUuid($applicationUuid);
        if (! array_is_list($variables) || $variables === []) {
            throw new \InvalidArgumentException('Bulk environment variables must be a non-empty list.');
        }

        foreach ($variables as $index => $variable) {
            if (! is_array($variable) || ! is_string($variable['key'] ?? null) || trim($variable['key']) === ''
                || ! is_string($variable['value'] ?? null)) {
                throw new \InvalidArgumentException("Bulk environment variable at index {$index} requires a non-empty key and string value.");
            }
            foreach (['is_preview', 'is_literal', 'is_multiline', 'is_shown_once'] as $flag) {
                if (isset($variable[$flag]) && ! is_bool($variable[$flag])) {
                    throw new \InvalidArgumentException("Bulk environment variable '{$variable['key']}' field '{$flag}' must be a boolean.");
                }
            }
        }

        return $this->coolifySourceClient()->request(
            'PATCH',
            '/applications/'.rawurlencode($applicationUuid).'/envs/bulk',
            ['data' => $variables],
        );
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListProjects(): array
    {
        return $this->coolifySourceClient()->request('GET', '/projects');
    }

    public function coolifyHealth(): string
    {
        return $this->coolifySourceClient()->requestText('GET', '/health');
    }

    public function coolifyVersion(): string
    {
        return $this->coolifySourceClient()->requestText('GET', '/version');
    }

    /** @return array<string, mixed> */
    public function coolifyAuthenticatedTeam(): array
    {
        return $this->coolifySourceClient()->request('GET', '/team');
    }

    /** @return array<array<string, mixed>> */
    public function coolifyAuthenticatedTeamMembers(): array
    {
        return $this->coolifySourceClient()->request('GET', '/team/members');
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListTags(): array
    {
        return $this->coolifySourceClient()->request('GET', '/tags');
    }

    /** @return array<string, mixed> */
    public function coolifyGetProject(string $projectUuid): array
    {
        return $this->coolifySourceClient()->request(
            'GET',
            '/projects/'.rawurlencode($this->coolifyRequireUuid($projectUuid)),
        );
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListProjectEnvironments(string $projectUuid): array
    {
        return $this->coolifySourceClient()->request(
            'GET',
            '/projects/'.rawurlencode($this->coolifyRequireUuid($projectUuid)).'/environments',
        );
    }

    /** @return array<string, mixed> */
    public function coolifyGetProjectEnvironment(string $projectUuid, string $environmentNameOrUuid): array
    {
        $environmentNameOrUuid = trim($environmentNameOrUuid);
        if ($environmentNameOrUuid === '') {
            throw new \InvalidArgumentException('Coolify environment name or UUID must be non-empty.');
        }

        return $this->coolifySourceClient()->request(
            'GET',
            '/projects/'.rawurlencode($this->coolifyRequireUuid($projectUuid)).'/'.rawurlencode($environmentNameOrUuid),
        );
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListServers(): array
    {
        return $this->coolifySourceClient()->request('GET', '/servers');
    }

    /** @return array<string, mixed> */
    public function coolifyGetServer(string $serverUuid): array
    {
        return $this->coolifySourceClient()->request('GET', '/servers/'.\rawurlencode($this->coolifyRequireUuid($serverUuid)));
    }

    /** @return array<array<string, mixed>> */
    public function coolifyGetServerResources(string $serverUuid): array
    {
        return $this->coolifySourceClient()->request('GET', '/servers/'.\rawurlencode($this->coolifyRequireUuid($serverUuid)).'/resources');
    }

    /** @return array<string, mixed> */
    public function coolifyGetDeployment(string $deploymentUuid): array
    {
        return $this->coolifySourceClient()->request('GET', '/deployments/'.\rawurlencode($this->coolifyRequireUuid($deploymentUuid)));
    }

    /**
     * Read a catalog used to provision a Coolify-managed cloud server.
     *
     * @return array<mixed>
     */
    public function coolifyCloudProviderCatalog(string $provider, string $catalog, ?string $cloudTokenUuid = null): array
    {
        $catalogs = [
            'hetzner' => ['locations', 'server-types', 'images', 'ssh-keys', 'firewalls', 'networks'],
            'digitalocean' => ['images', 'sizes', 'ssh-keys'],
            'vultr' => ['os', 'plans', 'regions', 'ssh-keys'],
        ];
        if (! isset($catalogs[$provider]) || ! in_array($catalog, $catalogs[$provider], true)) {
            throw new \InvalidArgumentException('Unsupported Coolify cloud provider catalog.');
        }

        $path = '/'.$provider.'/'.$catalog;
        if ($cloudTokenUuid !== null) {
            $path .= '?cloud_provider_token_uuid='.rawurlencode($this->coolifyRequireUuid($cloudTokenUuid));
        }

        return $this->coolifySourceClient()->request('GET', $path);
    }

    /** @param array<string, mixed> $migration
     *  @return array<string, mixed>
     */
    public function coolifyMigrateServerToInstance(string $serverUuid, array $migration): array
    {
        $serverUuid = $this->coolifyRequireUuid($serverUuid);
        $allowed = ['target_url', 'target_token', 'write_remote', 'rebind_sentinel', 'preserve_uuids', 'adopt_mode'];
        $unknown = array_diff(array_keys($migration), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unsupported Coolify server migration fields: '.implode(', ', $unknown));
        }

        $targetUrl = $migration['target_url'] ?? null;
        if (! is_string($targetUrl) || filter_var($targetUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Coolify server migration requires an HTTP(S) target_url.');
        }
        if (! is_string($migration['target_token'] ?? null) || trim($migration['target_token']) === '') {
            throw new \InvalidArgumentException('Coolify server migration requires a non-empty target_token.');
        }
        foreach (['write_remote', 'rebind_sentinel', 'preserve_uuids', 'adopt_mode'] as $flag) {
            if (isset($migration[$flag]) && ! is_bool($migration[$flag])) {
                throw new \InvalidArgumentException("Coolify server migration field '{$flag}' must be a boolean.");
            }
        }

        return $this->coolifySourceClient()->request(
            'POST',
            '/servers/'.rawurlencode($serverUuid).'/migrate',
            $migration,
        );
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListDestinations(): array
    {
        return $this->coolifySourceClient()->request('GET', '/destinations');
    }

    /** @return array<string, mixed> */
    public function coolifyGetDestination(string $destinationUuid): array
    {
        return $this->coolifySourceClient()->request(
            'GET',
            '/destinations/'.rawurlencode($this->coolifyRequireUuid($destinationUuid)),
        );
    }

    /** @return array<array<string, mixed>> */
    public function coolifyListServerDestinations(string $serverUuid): array
    {
        return $this->coolifySourceClient()->request(
            'GET',
            '/servers/'.rawurlencode($this->coolifyRequireUuid($serverUuid)).'/destinations',
        );
    }

    /** @return array<string, mixed> */
    public function coolifyRenameDestination(string $destinationUuid, string $name): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 255) {
            throw new \InvalidArgumentException('Coolify destination name must contain 1 to 255 characters.');
        }

        return $this->coolifySourceClient()->request(
            'PATCH',
            '/destinations/'.rawurlencode($this->coolifyRequireUuid($destinationUuid)),
            ['name' => $name],
        );
    }

    /** @return array<string, mixed> */
    public function coolifyDeleteDestination(string $destinationUuid): array
    {
        return $this->coolifySourceClient()->request(
            'DELETE',
            '/destinations/'.rawurlencode($this->coolifyRequireUuid($destinationUuid)),
        );
    }

    private function coolifySourceClient(): CoolifyApiClient
    {
        return $this->getClient();
    }

    private function coolifyRequireUuid(string $uuid): string
    {
        $uuid = trim($uuid);
        if ($uuid === '' || preg_match('/^[A-Za-z0-9-]+$/', $uuid) !== 1) {
            throw new \InvalidArgumentException('A valid Coolify UUID is required.');
        }

        return $uuid;
    }

    private function coolifyRequirePositiveId(int $id, string $label): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException($label.' must be a positive integer.');
        }
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function validateGitLabAppPayload(array $payload, bool $creating): array
    {
        $allowed = [
            'name', 'html_url', 'api_url', 'custom_user', 'custom_port', 'group_name',
            'client_id', 'client_secret', 'webhook_token', 'redirect_uri', 'is_system_wide',
        ];
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unsupported GitLab app fields: '.implode(', ', $unknown));
        }
        if ($creating && (! isset($payload['name']) || ! isset($payload['html_url']))) {
            throw new \InvalidArgumentException('GitLab app creation requires name and html_url.');
        }

        foreach (['name', 'html_url', 'api_url', 'custom_user', 'group_name', 'client_id', 'client_secret', 'webhook_token', 'redirect_uri'] as $field) {
            if (array_key_exists($field, $payload) && (! is_string($payload[$field]) || trim($payload[$field]) === '')) {
                throw new \InvalidArgumentException("GitLab app field '{$field}' must be a non-empty string.");
            }
        }
        foreach (['html_url', 'api_url', 'redirect_uri'] as $field) {
            if (isset($payload[$field])) {
                $scheme = strtolower((string) parse_url($payload[$field], PHP_URL_SCHEME));
                if (filter_var($payload[$field], FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
                    throw new \InvalidArgumentException("GitLab app field '{$field}' must be an HTTP(S) URL.");
                }
            }
        }
        if (isset($payload['custom_port']) && (! is_int($payload['custom_port']) || $payload['custom_port'] < 0 || $payload['custom_port'] > 65535)) {
            throw new \InvalidArgumentException('GitLab app custom_port must be an integer from 0 through 65535.');
        }
        if (isset($payload['is_system_wide']) && ! is_bool($payload['is_system_wide'])) {
            throw new \InvalidArgumentException('GitLab app is_system_wide must be a boolean.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function validateGitHubAppPayload(array $payload, bool $creating): array
    {
        $allowed = [
            'name', 'organization', 'api_url', 'html_url', 'custom_user', 'custom_port',
            'app_id', 'installation_id', 'client_id', 'client_secret', 'webhook_secret',
            'private_key_uuid', 'is_system_wide',
        ];
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unsupported GitHub app fields: '.implode(', ', $unknown));
        }
        $required = ['name', 'html_url', 'app_id', 'installation_id', 'client_id', 'client_secret', 'private_key_uuid'];
        if ($creating) {
            foreach ($required as $field) {
                if (! array_key_exists($field, $payload)) {
                    throw new \InvalidArgumentException("GitHub app creation requires {$field}.");
                }
            }
        }
        foreach (['name', 'organization', 'api_url', 'html_url', 'custom_user', 'client_id', 'client_secret', 'webhook_secret', 'private_key_uuid'] as $field) {
            if (array_key_exists($field, $payload) && (! is_string($payload[$field]) || trim($payload[$field]) === '')) {
                throw new \InvalidArgumentException("GitHub app field '{$field}' must be a non-empty string.");
            }
        }
        foreach (['html_url', 'api_url'] as $field) {
            if (isset($payload[$field])) {
                $scheme = strtolower((string) parse_url($payload[$field], PHP_URL_SCHEME));
                if (filter_var($payload[$field], FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
                    throw new \InvalidArgumentException("GitHub app field '{$field}' must be an HTTP(S) URL.");
                }
            }
        }
        foreach (['custom_port', 'app_id', 'installation_id'] as $field) {
            if (array_key_exists($field, $payload) && (! is_int($payload[$field]) || $payload[$field] < 0)) {
                throw new \InvalidArgumentException("GitHub app field '{$field}' must be a non-negative integer.");
            }
        }
        if (isset($payload['custom_port']) && $payload['custom_port'] > 65535) {
            throw new \InvalidArgumentException('GitHub app custom_port cannot exceed 65535.');
        }
        if (isset($payload['is_system_wide']) && ! is_bool($payload['is_system_wide'])) {
            throw new \InvalidArgumentException('GitHub app is_system_wide must be a boolean.');
        }

        return $payload;
    }
}
