# Changelog

## Unreleased

- Add standalone Shipper provider plugin for Coolify.
- Add Git, Docker Image, and standalone Dockerfile application deployment.
- Add databases, Compose services, storage mounts, environment variables, scheduled tasks, worker applications, and daemon applications.
- Add deployment status, logs, rollback, preview cleanup, and guarded Coolify server lifecycle operations.
- Expose Coolify MCP tool discovery/calls, resources, prompts, infrastructure health summaries, and paginated unhealthy-resource inventory.
- Honor Coolify `Retry-After` responses with bounded retries for safe REST and MCP reads; do not retry mutations.
- Add cloud-provider token renaming and private-key CRUD operations.
- Install the unpublished provider from its GitHub VCS repository until a stable Packagist release is available.
- Reconcile existing service tags through Coolify's dedicated tag endpoint instead of sending tags in the service update payload.
