# Shipper Coolify Provider

Composer plugin source for deploying Shipper projects to Coolify. The package is not currently listed in the shippercli Packagist catalog; until it is published, install the `main` branch from GitHub as shown below.

## Install

Add the GitHub VCS repository and require the provider's `main` branch in the project that owns the deployment configuration:

~~~sh
composer config repositories.provider-coolify vcs https://github.com/shippercli/provider-coolify
composer require shippercli/cli 'shippercli/provider-coolify:dev-main'
~~~

After a stable provider release is published to Packagist, the VCS repository can be removed and the provider installed with `composer require shippercli/cli shippercli/provider-coolify`.

Keep provider packages out of the application's runtime composer.json unless that application is also the deployment controller.

## Configure

~~~yaml
providers:
  coolify:
    url: https://coolify.example.com
    api_token: \${COOLIFY_API_TOKEN}
    server_uuid: \${COOLIFY_SERVER_UUID}
    project_uuid: \${COOLIFY_PROJECT_UUID}

projects:
  storefront:
    provider: coolify
    repository:
      provider: github
      url: https://github.com/acme/storefront
    profiles:
      production:
        branch: main
        domain: shop.example.com
        aliases:
          - www.shop.example.com
        coolify:
          environment_name: production
          build_pack: nixpacks
          ports_exposes: "8080"
~~~

Shipper SSL settings control Coolify's domain scheme: enabled SSL keeps HTTPS domains (Coolify automatically provisions and renews certificates), while disabled SSL sends configured HTTPS domains and aliases to Coolify as HTTP. `force_https` independently controls the HTTP-to-HTTPS redirect. The `letsencrypt` and `auto` types use Coolify's automatic TLS; `wildcard` requires wildcard DNS and proxy certificate challenge configuration in Coolify, and `custom` requires the certificate to be installed in the Coolify proxy. Shipper cannot upload or manage custom certificate files through Coolify's documented API.

For private GitHub repositories, configure github_app_uuid or private_key_uuid in provider or profile settings and authorize the GitHub App or deploy key in Coolify. server_uuid may be set per profile when environments deploy to different servers. Existing Shipper-managed applications, workers, Compose services, and databases are migrated when their current destination belongs to another server. If the target server has multiple Docker destinations, configure an explicit `destination_uuid` in `coolify.application` or the corresponding database options. Migrations transfer persistent volumes by default.

Alternatively, Shipper's profile-level `infrastructure.server` setting can provision or select the Coolify server. For cloud creation, Shipper resolves an existing server by stable name before creating it; Coolify validates the create specification before provisioning. Cloud provisioning supports Hetzner, DigitalOcean, and Vultr, and requires a matching Coolify cloud-provider token, a private-key UUID, and provider-specific location/size/image fields. `cleanup: destroy` removes a linked cloud instance only when Coolify's persisted Shipper ownership marker matches and Coolify confirms no resources remain. Registering an existing SSH host is record-only; Shipper can remove its Coolify server record when authorized, but never deletes the external host.

Example Hetzner server configuration:

~~~yaml
profiles:
  production:
    branch: main
    infrastructure:
      server:
        mode: create
        cleanup: retain
        spec:
          cloud_provider: hetzner
          cloud_provider_token_uuid: "${COOLIFY_HETZNER_TOKEN_UUID}"
          private_key_uuid: "${COOLIFY_PRIVATE_KEY_UUID}"
          name: storefront-production
          location: fsn1
          server_type: cx23
          image: 2404
~~~

The provider creates database credentials and attempts to derive connection values from Coolify's creation response. The primary MySQL, MariaDB, or PostgreSQL database is exposed as Laravel-compatible `DB_*` variables; each database also receives `SHIPPER_DB_<NAME>_*` variables when Coolify returns enough connection details. Explicit project/profile environment values take precedence. Database `password` values from profile-level database declarations are used at creation time; they are not rotated on later applies. Coolify may redact credentials from later reads unless the API token has sensitive-data read access. Existing databases whose credentials are redacted cannot be auto-wired; provide their connection values explicitly in the Shipper environment. SQLite is wired to `/var/www/html/database/<database-name>.sqlite` and therefore should use an application storage mount if persistence is required.

## Coolify-specific options

Set `coolify.force_deploy: true` (or provider-level `force_deploy: true`) to force Coolify to rebuild the primary application, changed managed worker applications, and configured Compose service without using its build cache. This is separate from Shipper's CLI `--force`, which only skips the interactive confirmation prompt. The default is `false`.

For regular (non-preview) primary application applies, `coolify.application.instant_deploy: true` is passed to Coolify's application start endpoint to skip its deployment queue. PR previews use Coolify's preview deployment endpoint instead.

Set `coolify.database_environment_variables` to synchronize variables to individual managed database resources, keyed by the Shipper database declaration name. This is separate from project/profile environment variables, which are synchronized to the application and Compose service. Only variables marked as Shipper-managed are pruned when removed from configuration; other Coolify variables are preserved. Values are interpolated using Shipper's project/profile placeholders, and keys listed in `secret_environment_variables` are marked shown-once in Coolify.

~~~yaml
        coolify:
          database_environment_variables:
            primary:
              MYSQL_INIT_COMMAND: "SET time_zone = '+00:00'"
~~~

Manage a pre-existing scheduled database backup by declaring `coolify.database_backups` keyed by the Shipper database declaration name. Each entry must include its Coolify backup `uuid`; Shipper patches only that explicit schedule and never guesses ownership, creates duplicate schedules, or deletes unlisted schedules. Scheduled backups are available for PostgreSQL, MySQL, MariaDB, MongoDB, and ClickHouse, not Redis, Dragonfly, or KeyDB. Supported settings mirror Coolify's database backup API (`frequency`, `enabled`, `save_s3`, `s3_storage_uuid`, `databases_to_backup`, `dump_all`, retention fields, `timeout`, and `missing_backup_notification_days`). To request an immediate execution without making every normal apply trigger a backup, call the provider's explicit `triggerDatabaseBackup(databaseUuid, backupUuid)` operation.


Coolify's UI supports engine-aware logical backup schedules for database components inside a Compose Service, but the published REST API does not document schedule CRUD for those service database components. Shipper can manage volume-level service storage backup schedules through `storage_backups`; these protect mounted files but are not a substitute for database-aware dumps. Configure service database logical backups in Coolify until an API endpoint is available.

Backup configurations can also be managed explicitly through `databaseBackups`, `createDatabaseBackup`, `updateDatabaseBackup`, `databaseBackupExecutions`, `deleteDatabaseBackup`, and `deleteDatabaseBackupExecution`. Deletion requires the database and relevant schedule/execution UUIDs; pass `deleteS3: true` only when the associated S3 files should also be removed. Shipper's normal apply/destroy flow does not delete backup configurations, executions, or archives.

To import a backup into a database component inside a Coolify Compose service, call `importServiceDatabaseBackup(serviceUuid, databaseUuid, importOptions)` with the source-specific payload documented by Coolify. Imports are explicit operations and are not run as part of normal apply or destroy.

For targeted lifecycle control of one Compose component, use `startServiceComponent(serviceUuid, componentType, componentUuid, force: false, latest: false)`, `stopServiceComponent(...)`, or `restartServiceComponent(...)`. `componentType` must be `application` or `database`; `force` and `latest` apply only to start. These calls do not start, stop, or redeploy the full stack.

To queue an immediate run of an existing service-storage backup schedule, call `runServiceStorageBackup(serviceUuid, storageUuid)`. This does not create or alter the schedule.

Coolify database mounts can be declared in `coolify.database_options.<database-name>.storages`. Shipper creates a missing mount or updates the unique mount matching its `mount_path`; unlisted database mounts are preserved. Optional `storage_backups` maps those mount paths to Coolify volume backup settings and applies schedules using the storage UUID returned by Coolify. Database mounts support `persistent` and `file` declarations with Coolify's `name`, `content`, `is_directory`, `fs_path`, and `host_path` fields. Coolify file-mount backups require a directory mount; host-file mounts cannot be backed up.

~~~yaml
        coolify:
          database_options:
            primary:
              storages:
                - type: persistent
                  name: mysql-data
                  mount_path: /var/lib/mysql
              storage_backups:
                /var/lib/mysql:
                  frequency: daily
                  enabled: true
                  save_s3: true
                  s3_storage_uuid: 00000000-0000-0000-0000-000000000000
~~~

~~~yaml
        coolify:
          database_backups:
            primary:
              uuid: 00000000-0000-0000-0000-000000000000
              frequency: "0 2 * * *"
              enabled: true
              save_s3: true
              s3_storage_uuid: 00000000-0000-0000-0000-000000000000
              database_backup_retention_days_locally: 7
~~~

Profile-level coolify options can select source_type (git, docker_image, or dockerfile), docker_image, docker_registry_image_tag, dockerfile (literal content), project_uuid, project_name, environment_uuid, environment_name, server_uuid, application_uuid, pull_request_id, build_pack, github_app_uuid, private_key_uuid, install_command, build_command, ports_exposes, dockerfile_location, docker_compose_location, preview_url_template, and redirect (www, non-www, or both). Repository provider/name metadata supports GitHub, GitLab, Bitbucket, and Gitea.com URL synthesis; for self-hosted Git services, provide an explicit repository URL. A positive pull_request_id is passed to Coolify's preview deployment API. For Git applications, Coolify must first create the preview from its configured pull-request webhook; the deploy API only redeploys an existing Git preview, and Shipper reports Coolify's missing-preview response as an apply failure. Docker Image previews can be created or updated by the API, but require `docker_tag` to identify the image built for that PR. Standalone Dockerfile applications cannot use PR previews. During a PR preview apply, the separately managed custom Compose service is left unchanged because Coolify does not document preview lifecycle for standalone service resources.

Set `coolify.additional_destination_uuids` to attach standalone Docker destinations to a supported non-Compose application during `apply`. This is additive: Shipper attaches missing destinations but does not detach unlisted ones, so manually managed destinations are preserved. Coolify requires a registry image for multi-server deployments, does not deploy PR previews to additional destinations, and currently disallows persistent storage and Compose applications on this path. Configure an external load balancer separately; Coolify and Shipper do not create one.

For Git/Nixpacks applications, Shipper `php_version` is sent as `NIXPACKS_PHP_VERSION`. Other Coolify sources/build packs do not have a Shipper PHP-version mapping and validation rejects `php_version` rather than silently ignoring it. Railpack selects PHP from the repository's `composer.json` and currently supports PHP 8.2 or newer (default 8.4); pin its version through the application's Composer platform requirement. For Git/Railpack applications, set `coolify.railpack_php_extensions` to a comma-separated list (for example `gd,imagick,redis`) to populate Railpack's `RAILPACK_PHP_EXTENSIONS` build variable. Shipper also applies this setting to its separately scoped preview environment.

Use `coolify.application` for Coolify's documented application settings, including Compose overrides/domains, health checks, resource limits, retention/restart controls, custom Docker labels/options, build behavior (including `use_build_server`), webhook secrets, preview settings, GPU options, `noindex_domains` (a list of domains to serve with `X-Robots-Tag: noindex, nofollow`), `tags` (a list of additional application tags), and `destination_uuid` to select the application's primary Docker destination on the configured server. Existing Shipper-managed primary and worker applications are migrated to this destination when it changes; Coolify transfers persistent volumes by default. This is separate from `additional_destination_uuids`, which attaches extra destinations. The provider validates option names and value types; unknown settings are errors rather than silently ignored. Response-only settings such as `is_build_server_enabled`, `is_debug_enabled`, and `is_swarm_only_worker_nodes` are rejected rather than sent as writes. Configured tags are additive: missing configured tags and Shipper ownership tags are attached through Coolify's tag endpoint; existing tags are preserved, and removing a tag from configuration does not detach it. It does not allow this map to replace Shipper's resource name, project/environment, server, repository, domains, or ownership tags. `docker_compose_domains` is a list of Coolify domain objects, and numeric retention/restart fields are range-checked. Docker Image profiles reject source/build-only options instead of silently ignoring them. `dockerfile_target_build`, `static_image`, HTTP Basic Auth settings, and `autogenerate_domain` are applied only when creating an application; Coolify's PATCH API does not document updates for these fields.

Set `coolify.application.is_preserve_repository_enabled: true` when updating an existing application without replacing its current repository. Coolify then retains the existing repository and ignores any replacement repository supplied in that update.

Runtime-focused `coolify.application` settings are also applied to Shipper-managed queue and daemon worker applications: health checks, resource limits, Docker labels/options, restart and stop handling, retained images, log drain, GPU, and predefined-network settings. Build-, source-, preview-, and domain-specific settings remain scoped to the primary application. Coolify's `max_restart_count` is a container restart ceiling and does not implement Shipper daemon `restart_delay`.

Set `secret_environment_variables` to a list of environment-variable names whose values should be marked “shown once” in Coolify. The same list applies to the application, worker applications, and Compose stack. Values are still sent to Coolify over the authenticated API; this controls subsequent display/API redaction.

Use `environment_variable_options` to set Coolify's per-variable literal, multiline, build-time, and runtime flags for application, worker, and Compose-service variables. Keys are environment-variable names; supported flags are `is_literal`, `is_multiline`, `is_buildtime`, and `is_runtime`. Coolify defaults variables to build-time and runtime; configure these flags when a value must be excluded from either phase. Preview scope and shown-once handling remain controlled by `preview_environment_variables` and `secret_environment_variables`:

~~~yaml
coolify:
  environment_variable_options:
    PRIVATE_KEY:
      is_literal: true
      is_multiline: true
    PUBLIC_BUILD_VALUE:
      is_buildtime: true
      is_runtime: false
~~~

Coolify shared variables can be managed through the provider's shared-environment-variable API helpers at team, project, environment, and server scope. To reference one from a Shipper-managed application, service, or database environment value, use Coolify's native syntax, for example `DATABASE_URL: "{{environment.DATABASE_URL}}"` or `API_TOKEN: "{{team.API_TOKEN}}"`. Coolify resolves these references when preparing the resource; defining the shared variable alone does not attach it to resources. Avoid marking a reference literal, because literal mode prevents Coolify from expanding it.

Shipper cron tasks target the primary Coolify application by default. Set `scheduled_task_containers` to map selected cron task names to Compose service names; those tasks are created only on the custom/catalog service, not duplicated on the primary app. This option requires a configured Coolify Compose or catalog service. Tasks omitted from the map continue to target the application.

Set `coolify.cron_timeout` to configure the execution timeout in seconds for these tasks (60 to 36000; default 300). It can be set per profile, overriding the provider-level value.

Shipper defaults scheduled-task and daemon users to `ploi`; Coolify does not expose a task-level user setting and runs scheduled tasks as the container's configured runtime identity. To use that identity for a cron task, explicitly set `user: ""`. Daemon users map to Coolify's custom Docker `--user` option. Coolify does not expose a daemon restart-delay setting, so Shipper applies `restart_delay` in the managed daemon command wrapper.

~~~yaml
projects:
  storefront:
    cron:
      prune-cache:
        command: php artisan cache:prune
        frequency: "0 * * * *"
        user: ""
~~~

For PR previews, set `pull_request_id` on the profile and provide isolated values under `coolify.preview_environment_variables`. These values are sent with Coolify's preview scope; production project/profile environment values are never copied into an explicit PR-preview apply. Normal applies configure both production values and this separate preview scope for Coolify webhook-created previews. Add preview-only secrets to `secret_environment_variables` as usual. Shipper-managed databases are created with PR-specific names/tags and are removed when Shipper receives `destroy` for that PR. Coolify automatically removes previews on PR close/merge but does not notify Shipper; when `mcp_enabled` is true, Shipper's orphan cleanup recovers PR IDs from remaining owned worker/database tags and removes those leftovers even after the preview record is gone. Destroy is idempotent if Coolify has already removed the preview. Cron tasks are rejected for explicit PR previews because Coolify does not document preview-scoped scheduled-task lifecycle. Persistent/file storage mounts default to Coolify's PR-specific suffix whenever preview deployments are enabled, isolating preview data from production.

Set `coolify.preview_domains` to replace the domain(s) for an individual PR preview during `apply`. Use `domains` for regular applications or `docker_compose_domains` for Docker Compose applications; both follow Coolify's preview-domain API shape. `force_domain_override: true` explicitly permits conflicting routes. This option requires `pull_request_id` and an existing preview. For Git applications, let Coolify's configured PR webhook create the preview first; an absent preview returns a clear apply error. The API applies these settings after Shipper queues the preview deployment:

~~~yaml
        coolify:
          pull_request_id: 42
          preview_domains:
            domains: https://pr-42.preview.example.com
            force_domain_override: false
~~~

Set provider-level `show_log_timestamps: true` to include Coolify timestamps in application, worker, database, service-component, and preview logs. A profile-level `coolify.show_log_timestamps` overrides the provider setting; the default is `false`.

When a profile has `pull_request_id`, Shipper's `logs` operation reads that preview's runtime logs rather than production application logs. Coolify requires an API token with `read:sensitive` or `root` ability for preview logs.

To read logs for a container in a separately managed Coolify Compose service, set `coolify.sub_service_name` on the selected profile. It must match the Compose service name. Shipper resolves the service UUID from its ownership tag and managed name instead of accepting an arbitrary resource UUID. Without this option, Shipper reads the managed application (or its PR preview) logs as usual.

To read logs for a separately managed Shipper database, set `coolify.database_log_name` to the database declaration name. The provider resolves the UUID only among Shipper-owned databases in the current production or PR-preview scope. It cannot be combined with `sub_service_name`.

To read a separately managed queue worker or daemon, set `coolify.workload_log_name` to its generated Coolify application name (for example `storefront-production-pr-42-queue-worker-1`). The provider accepts only Shipper-owned workload apps tagged for the selected production or PR scope. This selector cannot be combined with `database_log_name` or `sub_service_name`.

~~~yaml
        coolify:
          sub_service_name: worker
~~~

Coolify status reports the application's runtime status and filters Coolify's currently running deployment inventory to the selected application and, when configured, its pull request. The API only inventories deployments that are currently running; an empty list is not treated as proof that a preview is healthy or stopped.

Coolify provider profile settings can also contain a `databases` list with `name`, `type`, optional `user`, and optional `password`. Core project database objects are merged with these entries by name; the profile entry takes precedence when names match.

Database names must remain distinct after placeholder interpolation, slug
normalization, the 50-character limit, and preview suffix truncation. Colliding
declarations block validation and planning before API changes. Each database
receives `SHIPPER_DB_<NORMALIZED_NAME>_*` connection variables. The default
`DB_*` variables come from the first SQL/SQLite declaration; missing fields are
not borrowed from another database. Check that the selected connection has all
required values before using it in the application.

To use Coolify's Doppler, Infisical, or Vault integration for the primary application, first create and validate its integration token in Coolify, then set `coolify.secret_manager.integration_token_uuid`. Optional provider-specific `settings` are passed through to Coolify. The integration token itself is never stored in `shipper.yml`:

~~~yaml
providers:
  coolify:
    secret_manager:
      integration_token_uuid: "coolify-integration-uuid"
      settings: {}
~~~

The setting is application-only; Coolify's documented secret-manager API does not expose equivalent configuration endpoints for services or databases. Shipper applies the same integration to generated queue and daemon worker applications.

Use `coolify.database_options` to configure Coolify-specific settings by Shipper database declaration name:

~~~yaml
        coolify:
          database_options:
            primary:
              image: postgres:18-alpine
              limits_memory: 1g
              health_check_timeout: 10
              postgres_conf: "max_connections = 200"
~~~

Options are restricted to fields documented by Coolify's database create/update APIs and to the selected engine type. This includes resource limits, health checks, custom images/configuration, and public access settings. Public ports and health-check timing/retry values are range-checked before requests are sent. `tags` are additive: configured tags are attached to managed databases without removing existing tags or Shipper ownership tags. Set `destination_uuid` to choose the Coolify Docker destination for a database; existing managed databases are migrated when their current destination differs. Migration transfers persistent volumes by default; set `migrate_volumes: false` only when data transfer is intentionally not wanted. When `server_uuid` changes, Shipper uses the target server's only destination or requires `destination_uuid` if it has several. Credential fields explicitly supplied here update existing managed databases; ordinary Shipper declaration passwords remain creation-time values. Coolify project/environment/server placement and resource name remain provider-managed.

A custom Compose stack can be supplied as a literal multiline docker_compose string. Coolify receives this as a base64-encoded docker_compose_raw resource. The provider also synchronizes project/profile environment variables to the stack.

Configure documented Coolify service-level settings under `coolify.service`: `name`, `description`, `tags`, `instant_deploy`, `connect_to_docker_network`, `force_domain_override`, `is_container_label_escape_enabled`, `urls`, `destination_uuid`, and `migrate_volumes`. A custom service `name` is optional; Shipper's generated name remains the default. Custom tags are additive to the Shipper ownership tags. Each `urls` entry contains the Compose service `name` and a comma-separated `url` list. `destination_uuid` selects the stack's Docker destination, and existing managed stacks migrate when this destination changes. Volume transfer defaults to true; set `migrate_volumes: false` only when data transfer is intentionally unwanted. `applications` and `databases` can also map Compose component names to fields supported by Coolify's service component update APIs:

~~~yaml
        coolify:
          service:
            name: storefront-stack
            description: Storefront Compose stack
            tags: [team-storefront]
            connect_to_docker_network: true
            urls:
              - name: web
                url: https://shop.example.com,https://www.shop.example.com
            applications:
              web:
                human_name: Storefront web
                url: https://shop.example.com
                is_gzip_enabled: true
                max_restart_count: 5
            databases:
              mysql:
                human_name: Storefront database
                is_public: false
                public_port: 3307
~~~

Application component overrides accept `url`, `noindex_domains`, `human_name`, `description`, `image`, `exclude_from_status`, `is_log_drain_enabled`, `is_gzip_enabled`, `is_stripprefix_enabled`, `is_force_https_enabled`, `max_restart_count`, and `force_domain_override`. Database component overrides accept `human_name`, `description`, `image`, `exclude_from_status`, `is_log_drain_enabled`, `is_public`, `public_port`, and `public_port_timeout`. Component map keys must match exactly one component returned by Coolify; missing or ambiguous names fail the apply rather than silently skipping settings. The custom service name does not affect ownership lookup, logs, status, or cleanup.

To deploy a Coolify one-click catalog service instead of a custom Compose stack, set `coolify.service.type` to its catalog identifier (for example, `actualbudget`). Do not combine `type` with `docker_compose`. Shipper manages the catalog resource's environment variables, storage, and scheduled tasks through the same service APIs. Changing an existing managed service between catalog and custom Compose modes is rejected; destroy it before changing the service type.

Use storages to declare persistent or file mounts:

~~~yaml
        coolify:
          storages:
            - type: persistent
              name: storefront-data
              mount_path: /var/lib/storefront
            - type: file
              mount_path: /etc/storefront/config.json
              content: |
                {"mode":"production"}
~~~

Application storage declarations accept `type`, `mount_path`, and the relevant Coolify fields (`name` for persistent mounts; `content`, `is_directory`, and `fs_path` for file mounts). Mounts are matched by `mount_path` and updated in place. When PR previews are enabled, preview suffixing is enabled by default so preview data is isolated.

Configure per-mount backup schedules with `coolify.storage_backups`, keyed by the exact mount path. The schedule fields are passed to Coolify's storage-backup API; `save_s3: true` requires a valid `s3_storage_uuid` configured in Coolify:

~~~yaml
        coolify:
          storage_backups:
            /var/lib/storefront:
              frequency: daily
              enabled: true
              save_s3: true
              s3_storage_uuid: 00000000-0000-0000-0000-000000000000
~~~

For Compose services, service mounts must identify the Compose component whose application/database sub-resource owns the mount. Coolify's service component-list API currently returns weakly documented response shapes; the provider resolves `component` against the returned `name`, `service_name`, `docker_compose_service_name`, or `container_name`, and fails rather than guessing when resolution is not unique:

~~~yaml
        coolify:
          service:
            storages:
              - component: web
                type: persistent
                name: storefront-data
                mount_path: /var/lib/storefront
            storage_backups:
              /var/lib/storefront:
                frequency: daily
                enabled: true
~~~

Coolify-owned Compose mounts can be preview-suffixed but cannot otherwise be edited through the API. Service storage backups support persistent mounts and directory file mounts only; host file mounts are not backup targets. Applications and services both require the mount to exist before a backup schedule can be applied; declared application mounts are created or updated before the schedule is reconciled. Coolify's storage archive API does not provide a restore operation, so test restoration separately before relying on these backups.

Coolify resource ownership is tracked with shippercli-managed plus a deterministic project/profile tag. Apply reuses only tagged resources. Destroy deletes only resources that still carry both ownership tags; project and environment containers are left intact. Destroying a production profile also removes its tagged PR-specific queue/daemon worker applications and databases, while leaving Coolify's own preview deployment records to Coolify's lifecycle.

## Coverage

- Git-based application create/update/deploy, domain aliases, HTTPS proxy settings, NGINX configuration, PHP buildpack selection, and deployment commands.
- Project and environment lookup/creation for Shipper projects/profiles.
- MySQL, MariaDB, PostgreSQL, Redis, MongoDB, and SQLite resources, with primary SQL connection variables synchronized to applications, workers, and Compose stacks when Coolify returns the connection details.
- ClickHouse, Dragonfly, and KeyDB resources available in current Coolify releases.
- Application and Compose-stack environment variables, plus persistent/file mounts.
- Queue workers and daemons as independent Coolify applications; each configured process gets its own app resource.
- Coolify scheduled tasks, manual application/service task execution and execution history, application/service start-stop-restart operations, deployment cancellation by UUID, status and logs, and retained-image rollback.
- Targeted pull-request preview cleanup and per-preview domain updates. Coolify exposes active preview inventory through MCP's `list_application_previews` tool. Set `providers.coolify.mcp_enabled: true` and enable MCP for the Coolify instance and team to enumerate active PR previews; the same Coolify bearer token needs read access. Without MCP, Shipper can recover PR IDs only from its tagged workers/databases and derive domains only when the profile domain contains `${GITHUB_PR_NUMBER}`; app-only previews still require MCP. The provider also exposes Coolify's MCP tool catalog/calls, resources, prompts, infrastructure overview, and paginated unhealthy-resource inventory.
- Coolify server lookup, ownership-guarded removal, existing SSH host registration, and billable Hetzner, DigitalOcean, and Vultr provisioning when the corresponding cloud token and SSH key are configured in Coolify.

Network rules are rejected during validation because Coolify does not manage the host/provider firewall through its API. Host-only permanent redirects between a hostname and its `www` form map to Coolify's native www/non-www direction setting. Absolute-path 301/302 redirects are translated to Traefik redirect-regex labels on application resources and require the server to use Traefik; Compose applications and custom Service components must define their redirects in Compose. Arbitrary URL redirects, unsupported status codes, and conflicting www/non-www directions are rejected rather than silently changed. Custom proxy middleware remains a Coolify-specific configuration concern.

Coolify's scheduled-task API runs commands in a running container and does not accept a user identity. Daemon and scheduled-task commands therefore use the container's configured runtime user; set `user: ""` explicitly because Shipper defaults it to `ploi`. Non-empty user values are rejected. Coolify server timezone controls scheduled execution.

Coolify rollback only restores a retained application image/commit. It does not roll back database migrations, persistent data, or external services. Coolify issues and renews proxy certificates; the provider configures HTTPS rather than managing certificate objects.

See the [Coolify API reference](https://coolify.io/docs/api/overview) for API-token setup and self-hosted API access requirements.

Shipper's project-level `nginx_config` is applied to Git-based Coolify Static applications and to Git-based Nixpacks or Railpack applications when `coolify.application.is_static: true`. Coolify serves those static outputs through Nginx. Other build packs and non-Git sources reject this setting rather than silently ignoring it.

## Existing application ownership

An explicit `application_uuid` selects a resource; it does not authorize adoption.
Apply and application lookup require both `shippercli-managed` and the matching
project/profile resource tag before using an existing application. Missing or
differently scoped tags fail closed before application updates or retagging.
Review the resource identity and deployment scope separately before explicitly
migrating tags through Coolify; Shipper does not automatically adopt it.

### Scope encoding and migration

Resource tags use `shippercli-` followed by the first 16 hex characters of the
SHA-256 hash of `json_encode([projectName, profileName], JSON_THROW_ON_ERROR)`.
A project-only scope encodes a JSON null profile, distinct from a literal `*`
profile. Scheduled-task prefixes use the first 10 characters of the same hash.
This encoding prevents delimiter-containing names from sharing ownership scope.

Tags and task prefixes from older delimiter-based hashes require operator review.
Verify resource identities, exact project/profile scope, preview IDs, attached
databases, and scheduled tasks before explicitly migrating tags or task prefixes
in Coolify. Shipper does not automatically retag resources. A same-name application
without matching scope blocks creation rather than creating a duplicate. Legacy
resources are excluded from ownership-based listing and cleanup until migrated.
Database cleanup accepts only the exact scope tag or a positive numeric PR suffix.

### Plan ownership inspection

Plan reads application inventory and verifies an explicit application's ownership
when `application_uuid` is configured. Unowned same-name applications, mismatched
UUID ownership, malformed inventories/identities, and read failures return
`blocked: true` with one public reason. Planning performs no writes. CLI
enforcement is proposed in shippercli/cli#154. Other resources and deployment
prerequisites remain subject to checks during apply.

### Existing database declaration credentials

Ordinary database declaration user/name/password values initialize new resources.
They are not sent as implicit PATCH fields for existing tagged databases. Existing
connection fields come from Coolify's known resource/details response; explicit
`database_options` fields remain the opt-in update surface. A creation password
is not substituted when an existing password is redacted. Supply missing connection
values explicitly in the application environment; complete automatic delivery is
not established when the API withholds those values. Live credential rotation and
connection verification remain required for explicit credential updates.

Explicit engine-specific credential fields supplied through `database_options`
also take precedence when constructing application connection variables, even
if Coolify returns an older `internal_db_url` after the update. URL-derived host,
port and unspecified credentials remain available. This applies consistently to
the primary `DB_*` variables and the per-database `SHIPPER_DB_*` variables.
It does not verify that the running database has applied a credential change.

### Incomplete SQL connections

Before application environment writes, SQL connections are checked after combining
known database values with the application environment. The primary SQL connection
requires `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and a known
`DB_PASSWORD` (an explicitly empty string is allowed). Each additional SQL database
requires its own complete `SHIPPER_DB_<RESOLVED_NAME>_*` host/database/user/password
values. Explicit primary values also complete missing primary namespaced fields.
An additional database never borrows the primary password. Missing fields fail
with variable names only, without displaying credentials.

Preview checks use `preview_environment_variables`, never production environment
values. Existing resources with hidden user/database/password fields need explicit
values; creation defaults are not evidence of an existing connection. Earlier
application reconciliation or database provisioning may already have occurred when
this check fails. It prevents partial connection delivery, not all earlier changes,
and does not establish successful database authentication. Non-SQL connection
completeness and live application connection tests remain under review.

Connection URL usernames, passwords and database paths are URL-decoded exactly
once when converted to environment variables. Literal values returned in separate
API fields are not URL-decoded. Plus signs remain plus signs; encoded percent
characters are not decoded a second time.

### Database inventory and ownership preflight

Before database reconciliation, apply validates the entire returned database
inventory. Rows need unique non-empty UUIDs, readable names and tag lists. String
tags and objects with `name` or `tag` are supported; conflicting tag names are
rejected. Each database owned by the exact current scope must carry exactly one
unambiguous per-database ownership tag. Multiple resources with that tag, multiple
Shipper ownership tags on one resource, and legacy scope-only tags require review.

During apply, this check runs before database creation, movement, updates or
removal; application reconciliation may already have occurred. Plans also inspect
database ownership using read-only requests and block on unavailable or ambiguous
inventory. Profile destroy and pull-request cleanup preflight database inventory
before deleting any application, worker or database. Database status/log selection
uses the same validation. Scope-only legacy database tags must be reviewed and
migrated before these operations; they no longer authorize removal. Invalid or
overflowing preview IDs are not treated as owned previews.

This does not authenticate manually editable tags, prevent changes after a read,
or validate every non-database resource inventory. Shared resource concurrency,
other prerequisite checks, migration review and live operation remain unfinished.

### Profile application cleanup inventory

Profile destroy now reads the full application inventory once and validates
UUIDs, names and tags before deletion. It rejects duplicate UUIDs, duplicate names
within a managed scope, unreadable tags and conflicting Shipper scope claims.
Production applications/workers and eligible preview workers are collected into
a unique target set, so an application is not deleted twice through overlapping
scope queries. Preview applications themselves remain excluded from profile
preview-worker cleanup. Preview IDs must fit a positive integer.

Database and selected service inventories are read before these application
deletions; unavailable services or invalid selected service identities stop the
operation first. API operations remain non-transactional, and a later deletion
failure can still leave partial cleanup. Application creation/update lookups and shared explicit-ID reads also validate
inventory/response identities before selecting a resource. Automatic service inventory ownership checks also run before reconciliation;
mutation-response validation and live behavior remain under review.

Preview cleanup now validates its parent identity and the complete returned worker
inventory before deleting the preview endpoint. An explicit parent UUID must match
the returned identity. Standalone deletion is limited to exact-scope queue and
daemon worker prefixes; preview applications, other application types sharing the
prefix, and foreign tags are preserved. Unavailable or ambiguous worker data stops
cleanup before any preview/worker/database deletion. Shared tagged-application
queries now use the application inventory validator, including identity, tag and
managed-name ambiguity checks. These checks do not prevent concurrent changes
after preflight or make later API deletion failures transactional.

Explicit application reads used by discovery and update now require a readable
owned UUID/name response whose UUID exactly matches the requested UUID. Tagged
update searches and global same-name collision searches validate their returned
inventories before selection. Duplicate managed names, invalid identities and
conflicting tags therefore stop before a PATCH or create request. A valid explicit
UUID may retain an arbitrary readable owned application name. These consistency
checks do not authenticate tags, lock resources after the read, or validate every
create/update response; live behavior and those remaining paths still need review.

### Automatic service ownership inventory

The provider manages one Compose/catalog service per project/profile scope.
Service apply, removal, profile destroy, and shared status/log selection validate
service UUID/name/tag metadata before choosing a resource. Conflicting Shipper
scope claims, duplicate identities/names and multiple services claiming the same
scope require explicit ownership review. A global service lookup that disagrees
with an empty scoped lookup stops creation rather than creating another service.
Normal profile plans also preflight services using read-only requests; explicit
PR preview plans leave shared services unchanged.

This is a compatibility change for profiles with multiple services carrying the
same ownership tag: they are no longer chosen or deleted by inference. Review
those tags manually. These checks do not authenticate editable tags, confirm
mutation responses or service runtime health, or lock resources after the read.
Standalone low-level API operations remain caller-directed.

### Application and service update responses

After an automatic application/service PATCH, every supplied top-level or nested
`data.uuid` must match the requested resource. Empty, null, conflicting or foreign
identities stop follow-up operations. Acknowledgement-only responses retain the
previously verified UUID. The merged response must still have readable metadata
and the expected Shipper scope tags; explicit ownership loss is not ignored.

This check occurs after the PATCH, which may already have changed the original
resource. It prevents accepting an unexpected response identity for later
operations; it is not transactional rollback or proof of the resource's runtime
state. Create/database responses and other mutation surfaces remain under review.

### Database response identity and optional detail access

Database create responses must provide a valid identity not already present in
the preflight inventory. Supplied top-level/nested UUIDs must agree. Existing and
new database detail/update responses are checked before their values or later
resource operations are used. Returned metadata must retain the exact database
ownership tag. Destination-detail reads also reject a supplied mismatched UUID.

Only an explicit API HTTP 403 permission refusal can preserve previously known
database data during optional detail reads. Missing resources, server/transport
failures, malformed identity or lost ownership do not use this fallback. The
connection completeness checks still apply to retained known data. Creation or
updates may already have occurred before response validation fails; recovery and
live authentication remain unverified.

Rejection tests also guard against treating PHPUnit assertion failures as expected
runtime exceptions. This keeps a test's own failure from satisfying its exception
check.

### Application and service creation responses

Automatic application/service creation requires a non-empty identity not already
present in the complete preflight inventory. Supplied top-level/nested UUIDs must
agree; explicit null, empty or whitespace identities are refused. Returned
metadata must remain readable and preserve the requested scope ownership when
merged with the request. Valid UUID-only acknowledgements remain supported;
nested UUID acknowledgements are normalized to a top-level known identity.

The request supplies metadata omitted from a thin acknowledgement. This is not
independent read-back proof that Coolify persisted every requested setting. The
POST can already have created a resource before an invalid response is detected;
follow-up operations stop and account review may be required. Concurrent creations,
other API surfaces and live runtime verification remain unfinished.

### Inventory envelopes and completeness indicators

REST inventory parsing supports a flat list or one unambiguous `data`/`items`
wrapper. Explicit null data is not replaced by a second field. Failed/error
wrappers are refused. Recognized continuation indicators (`next`,
`next_page_url`, `next_cursor`, `has_more`), partial page metadata and totals that
disagree with returned rows stop the operation. Complete one-page metadata and
terminal continuation markers remain accepted. Continuation URLs are never
requested by this parser. Supporting a future paginated endpoint requires its
own documented traversal before its result can authorize reconciliation.

These guards cover recognized metadata in the root, `meta`, `links`,
`pagination`, and `_pagination` maps. They are not general support for arbitrary
response envelopes or pagination protocols. Coolify's documented application
list is a flat all-applications response; live/version-specific schema checks
remain required. Error diagnostics do not include the continuation URL/payload.

### Raw JSON inventory shapes

Inventory GETs use a dedicated client path that checks JSON shape before it is
lost during associative decoding. `{}` and `[]` are distinct: an empty object,
a numeric-key object, an object-valued `data`/`items` container, and an empty HTTP
body do not establish an empty list. Valid flat arrays and array-valued wrappers
remain supported. Application environment inventory uses the same validated list
path. Ordinary mutation acknowledgements can still return `{}`.

`python3 tests/http/test_inventory_shapes.py` exercises real local HTTP/cURL
requests with synthetic credentials, verifies shape handling, and proves bad
database JSON blocks plan/destroy without writes. The negative control with the
shape guard disabled reproduces the empty-object acceptance. This test runs in
CI alongside PHPUnit; it needs loopback socket access and contacts no live account.
The generic untyped request method remains available for object/acknowledgement
endpoints; callers must use the inventory path when completeness matters.

### HTTP failure diagnostics

JSON, text and multipart HTTP failures report status-based categories rather than
server-provided message/error bodies, which can echo credentials or uploaded data.
The HTTP status prefix is retained for existing 403/404 handling, and 429 errors
retain a numeric Retry-After hint. Non-JSON error bodies still report their HTTP
status. Transport errors use cURL error categories without raw cURL URL text.

The local HTTP regression sends synthetic bearer credentials, a JSON password and
multipart password/file data, confirms the server receives them intact, and checks
that rejected-response diagnostics do not expose them. Successful response bodies
and application log contents are unchanged. Field-level API error details are no
longer included in these failure messages; inspect protected server diagnostics
when that detail is needed. This covers client exception messages, not arbitrary
application output or user-enabled engine traces.

### Bounded rate-limit retries

Automatic rate-limit retries apply only to GET, HEAD and OPTIONS with a valid
numeric Retry-After of at most five seconds, for at most two retries. Each retry
also has a small bounded jitter. Larger hints return the HTTP 429 error immediately
with the requested delay, so a server hint cannot suspend the CLI for hours.
Mutation requests and responses without a valid numeric hint are not retried.
The configured timeout remains per HTTP attempt; it is not a total operation
deadline across attempts and retry delays.

The local HTTP retry regression checks two short GET retries, single-attempt POST,
immediate long-hint failure and invalid-hint failure. The prior implementation
hit the test's four-second subprocess cutoff while sleeping on a one-hour hint.
No live account or mutation was used in this check.

### Project/profile database field inheritance

Profile database declarations merge with a same-name project declaration field by
field. A profile supplying only `password` retains the project's database engine
and user. Explicit profile type/user/password fields still win. Profile-only
databases keep the normal MySQL/empty-user defaults; explicit null fields are
validation errors rather than omitted values.

`php tests/integration/verify-cli-databases.php /path/to/installed/cli` exercises
real CLI ConfigLoader, ProjectConfig, ProfileConfig, DatabaseConfig and environment
objects. Both checkouts need dependencies installed; the script loads only the
CLI configuration/YAML namespaces to avoid mixing test framework versions. It
checks YAML inheritance, PostgreSQL create payloads, and project/profile environment
precedence in application requests against a fake API. The unit inheritance tests
run in provider CI; this separate cross-checkout script was run locally against
CLI commit aa3e6a7618a32f6d3a1deae6da481f6a56eea721. It performs no live deployment.

Duplicate names within the profile database list/map are rejected before merging
with project declarations. This prevents a later profile entry from silently
replacing an earlier engine or credential choice. Validation reports the error;
planning blocks and database reconciliation stops before any API request.

An explicitly configured empty password in `database_options` remains a known
empty value in generated default/namespaced application variables. It is not
silently dropped after the database update. Missing or redacted API credentials
still require explicit application configuration; they are not inferred empty.


### Distribution archive checks

Composer archive exclusions and Git export attributes omit installed dependencies,
tests, workflow files, root environment/auth configuration, and PHPUnit caches and
configuration. Runtime source, package metadata, documentation, and assets remain
available. `python3 tests/package/verify-dist.py` checks Composer and Git exports,
then loads all declared runtime classes from an extracted ZIP with an overridden
class map. Dependency classes come from the installed checkout; this check does
not perform a fresh production dependency install or live deployment. CI runs it
with the existing provider tests. Git export verification uses current worktree
attributes; committed attributes determine subsequent GitHub source archives.

### Non-SQL connection delivery checks

Before application environment reads/writes, Redis, Dragonfly and KeyDB
connections require namespaced `SHIPPER_DB_<NAME>_HOST` and `_PASSWORD` string
values. MongoDB and ClickHouse additionally require `_USERNAME`. The host must
be nonempty. Credentials may be explicitly empty strings; absence is not
silently interpreted as passwordless authentication. Explicit application
variables can supply unavailable values, with existing preview isolation and
precedence rules. These engines do not borrow unrelated SQL `DB_*` defaults.

This is a minimum field-delivery check, not a connection test. Port/database
selection, MongoDB authentication database, replica-set/SRV topology, TLS and
engine-specific client configuration still require application and live
verification. A failure can occur after earlier resource operations; deployment
is not transactional. MongoDB connection formats are described at
https://www.mongodb.com/docs/manual/reference/connection-string/.

Non-SQL preview delivery is covered separately: production project/profile
variables cannot fill missing preview secrets, and the PR-specific namespaced
variables are written only with `is_preview: true`. Explicit production values
are not copied into preview requests. The HTTP fixture covers all five engines;
a negative control enabling production fallback fails the fixture. This verifies
request scope using synthetic data, not isolation inside a live database server.

Database declaration uniqueness is checked within project declarations before
merging profile overrides, as it already is within profile declarations. Two
project declarations cannot silently collapse into one name, even if a profile
override would hide the conflict. Validation/planning and direct database apply
reject before API operations. The existing normalized-name check additionally
rejects punctuation/case and resource-name/preview-truncation collisions before
namespaced connection fields can overlap. One project declaration plus one
matching profile override remains supported.

When Coolify provides an internal database connection URL, Shipper additionally
exports `SHIPPER_DB_<NAME>_URL`. It preserves the scheme, authority/seed hosts,
path and query options rather than reducing the connection to scalar fields.
Known credential updates are percent-encoded into the userinfo; explicit database
updates replace the encoded path while retaining options. An explicit empty
password remains empty, not replaced by an old URL password. Malformed URL
structure fails with a fixed diagnostic without echoing the URL.

The URL follows existing generated-environment scope and explicit override rules;
it does not automatically set a framework-specific default URL variable. Configure
the application to consume it when options/topology matter. This preserves the
reported connection description; driver compatibility, authentication and live
connectivity remain unverified.

PostgreSQL named URI parameters (`host`, `port`, `user`, `password`, `dbname`)
are reflected in scalar connection values as well as the full URL. Known user,
password and database updates replace all corresponding query occurrences,
including percent-encoded keys, so stale query credentials cannot override the
new userinfo/path. Other query options retain their original bytes; URI `+`
characters are not treated as form-encoded spaces. PostgreSQL URLs with an empty
authority and a query host are supported. Three generated URI cases were checked
with the installed libpq parser without opening a database connection.

Reference: https://www.postgresql.org/docs/current/libpq-connect.html#LIBPQ-CONNSTRING-URIS
This covers the named fields above, not every libpq connection parameter or live
authentication/TLS behavior.

Database passwords are literal values at the provider boundary. Creation
passwords and engine-specific `*_password` options do not undergo provider
`${PROJECT_NAME}`/`${PROFILE}` name-macro substitution. Names and descriptive
metadata retain their existing interpolation. For YAML environment references,
CLI PR #158 provides single-pass loading so injected secret contents are not
expanded again inside profile options. This changes configurations that relied
on provider macros inside passwords; supply the intended password explicitly
through your secret/configuration workflow instead.

Values in `database_environment_variables` are also literal at the provider
boundary, matching application environment handling. Project/profile name macros
are not expanded inside values that may contain secrets. Existing managed keys
still update, new keys still create, explicitly empty strings are retained, and
only stale variables marked as Shipper-managed are removed. Configurations that
used provider macros in these values must supply their intended resolved values.

### Deployment records in status output

Public status summarizes running/history/latest deployment records, including
worker deployment summaries. Only scalar IDs, state, pull-request identity and
timestamps are retained. Raw logs, environment fields and unknown extra fields
are omitted because CLI status prints its result as JSON. Consumers depending
on raw deployment fields in status must use the appropriate explicit API method
and handle secret-bearing responses separately. This change does not sanitize
all low-level API methods or establish deployment/application health.

Scheduled-task status similarly omits configured command strings and summarizes
execution records to scalar identifiers, state and timestamps. Commands can embed
credentials, and raw execution output can contain application secrets. Explicit
scheduled-task configuration/output API methods retain their existing behavior;
this privacy boundary applies to CLI-visible status summaries.

Database backup execution records in status use the same summary boundary. Raw
output and extra credential-bearing fields are omitted, and malformed execution
lists are refused with a fixed diagnostic. Both direct lists and the existing
`executions` wrapper are supported. Explicit backup execution/output API methods
are unchanged.

Status also summarizes rollback images to current/tag, creation time and current
selection flag, refusing malformed image lists. Opt-in preview status includes
only scalar identifiers, state and timestamps; raw preview configuration and URLs
are omitted. Explicit image/preview API methods keep their original responses.
Injected MCP fixtures verify the preview summary without connecting to a server.
