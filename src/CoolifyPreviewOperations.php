<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifyPreviewOperations
{
    /** @return list<array<string, mixed>> */
    public function applicationPreviews(string $applicationUuid): array
    {
        if (($this->config['mcp_enabled'] ?? false) !== true) {
            return [];
        }
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Coolify application UUID is required to list previews.');
        }

        if ($this->mcpClient === null) {
            $url = $this->stringConfig('url');
            $token = $this->stringConfig('api_token', $this->stringConfig('token'));
            if ($url === '' || $token === '') {
                throw new \RuntimeException('Coolify MCP preview inventory requires the Coolify URL and API token.');
            }
            $this->mcpClient = new CoolifyMcpClient($url, $token);
        }

        return $this->mcpClient->listApplicationPreviews($applicationUuid);
    }
}
