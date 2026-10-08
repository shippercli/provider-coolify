<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

final class CoolifyMcpClient
{
    private const PROTOCOL_VERSION = '2025-11-25';

    /** @var (\Closure(array<string, mixed>, array<string, string>): array<string, mixed>)|null */
    private readonly ?\Closure $transport;

    private ?string $sessionId = null;

    private string $protocolVersion = self::PROTOCOL_VERSION;

    private int $requestId = 0;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $toolsCache = null;

    /**
     * @param (callable(array<string, mixed>, array<string, string>): array<string, mixed>)|null $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 30,
        private readonly bool $verifySsl = true,
        ?callable $transport = null,
    ) {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => '[redacted]',
            'token' => '[redacted]',
            'timeout' => $this->timeout,
            'verifySsl' => $this->verifySsl,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function tools(): array
    {
        if ($this->toolsCache !== null) {
            return $this->toolsCache;
        }
        $this->initialize();
        $result = $this->rpc('tools/list', [])['result']['tools'] ?? null;
        if (! \is_array($result) || ! \array_is_list($result)) {
            throw new \RuntimeException('Coolify MCP returned an invalid tools catalog.');
        }

        return $this->toolsCache = \array_values(\array_filter($result, 'is_array'));
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function callTool(string $name, array $arguments = []): array
    {
        if (\trim($name) === '' || ($arguments !== [] && (\array_is_list($arguments)
            || \array_filter(\array_keys($arguments), static fn (mixed $key): bool => ! \is_string($key)) !== []))) {
            throw new \InvalidArgumentException('Coolify MCP tool name and object-like arguments are required.');
        }
        foreach ($this->tools() as $tool) {
            if (($tool['name'] ?? null) === $name) {
                $rpc = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
                $result = $rpc['result'] ?? null;
                if (! \is_array($result) || ($result['isError'] ?? false) === true) {
                    throw new \RuntimeException("Coolify MCP tool '{$name}' failed.");
                }

                return $this->toolPayload($result);
            }
        }

        throw new \InvalidArgumentException("Coolify MCP does not expose tool '{$name}'.");
    }

    /** @return array<int, array<string, mixed>> */
    public function resources(): array
    {
        $this->initialize();
        $result = $this->rpc('resources/list', [])['result']['resources'] ?? null;
        if (! \is_array($result) || ! \array_is_list($result)) {
            throw new \RuntimeException('Coolify MCP returned an invalid resources catalog.');
        }

        return \array_values(\array_filter($result, 'is_array'));
    }

    /** @return array<string, mixed> */
    public function readResource(string $uri): array
    {
        if (\trim($uri) === '') {
            throw new \InvalidArgumentException('Coolify MCP resource URI is required.');
        }
        $this->initialize();
        $result = $this->rpc('resources/read', ['uri' => $uri])['result'] ?? null;
        if (! \is_array($result)) {
            throw new \RuntimeException('Coolify MCP returned an invalid resource response.');
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function prompts(): array
    {
        $this->initialize();
        $result = $this->rpc('prompts/list', [])['result']['prompts'] ?? null;
        if (! \is_array($result) || ! \array_is_list($result)) {
            throw new \RuntimeException('Coolify MCP returned an invalid prompts catalog.');
        }

        return \array_values(\array_filter($result, 'is_array'));
    }

    /** @param array<string, string> $arguments @return array<string, mixed> */
    public function getPrompt(string $name, array $arguments = []): array
    {
        if (\trim($name) === '') {
            throw new \InvalidArgumentException('Coolify MCP prompt name is required.');
        }
        foreach ($this->prompts() as $prompt) {
            if (($prompt['name'] ?? null) === $name) {
                $result = $this->rpc('prompts/get', ['name' => $name, 'arguments' => $arguments])['result'] ?? null;
                if (! \is_array($result)) {
                    throw new \RuntimeException('Coolify MCP returned an invalid prompt response.');
                }

                return $result;
            }
        }

        throw new \InvalidArgumentException("Coolify MCP does not expose prompt '{$name}'.");
    }

    /** @return array<string, mixed> */
    public function infrastructureOverview(): array
    {
        return $this->callTool('get_infrastructure_overview');
    }

    /**
     * Return each raw page so Coolify's response fields and pagination metadata are preserved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unhealthyResourcePages(bool $sampleOnly = false): array
    {
        $arguments = ['page' => 1, 'per_page' => 100];
        if ($sampleOnly) {
            $arguments['sample_only'] = true;
        }
        $pages = [];
        $seenPages = [];
        for ($count = 0; $count < 10000; $count++) {
            $pageKey = \json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (isset($seenPages[$pageKey])) {
                throw new \RuntimeException('Coolify MCP unhealthy-resource pagination repeated a page.');
            }
            $seenPages[$pageKey] = true;
            $payload = $this->callTool('list_unhealthy_resources', $arguments);
            $pages[] = $payload;
            $pagination = $this->findNestedValue($payload, '_pagination');
            $nextArguments = \is_array($pagination) ? ($pagination['next']['args'] ?? null) : null;
            if (! \is_array($nextArguments) || $nextArguments === []) {
                return $pages;
            }
            $arguments = \array_merge($arguments, $nextArguments);
        }

        throw new \RuntimeException('Coolify MCP unhealthy-resource inventory exceeded the pagination safety limit.');
    }

    /** @return array<int, array<string, mixed>> */
    public function listApplicationPreviews(string $applicationUuid): array
    {
        if (\trim($applicationUuid) === '') {
            throw new \InvalidArgumentException('Coolify application UUID is required to list previews.');
        }

        $this->initialize();
        $tools = $this->rpc('tools/list', []);
        $toolFound = false;
        foreach (($tools['result']['tools'] ?? []) as $tool) {
            if (\is_array($tool) && ($tool['name'] ?? null) === 'list_application_previews') {
                $toolFound = true;
                break;
            }
        }
        if (! $toolFound) {
            throw new \RuntimeException('Coolify MCP does not expose list_application_previews.');
        }

        $previews = [];
        $arguments = ['uuid' => $applicationUuid, 'page' => 1, 'per_page' => 100];
        $seenPages = [];
        for ($count = 0; $count < 10000; $count++) {
            $pageKey = \json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (isset($seenPages[$pageKey])) {
                throw new \RuntimeException('Coolify MCP preview pagination repeated a page.');
            }
            $seenPages[$pageKey] = true;

            $rpc = $this->rpc('tools/call', [
                'name' => 'list_application_previews',
                'arguments' => $arguments,
            ]);
            $result = $rpc['result'] ?? null;
            if (! \is_array($result) || ($result['isError'] ?? false) === true) {
                throw new \RuntimeException('Coolify MCP failed to list application previews.');
            }

            $payload = $this->toolPayload($result);
            $rows = $this->findNestedValue($payload, 'previews');
            if (! \is_array($rows) || ! \array_is_list($rows)) {
                throw new \RuntimeException('Coolify MCP returned an invalid preview inventory.');
            }
            foreach ($rows as $preview) {
                if (\is_array($preview)) {
                    $previews[] = $preview;
                }
            }

            $pagination = $this->findNestedValue($payload, '_pagination');
            $nextArguments = \is_array($pagination) ? ($pagination['next']['args'] ?? null) : null;
            if (! \is_array($nextArguments) || $nextArguments === []) {
                return $previews;
            }
            $arguments = \array_merge(['uuid' => $applicationUuid], $nextArguments);
        }

        throw new \RuntimeException('Coolify MCP preview inventory exceeded the pagination safety limit.');
    }

    private function initialize(): void
    {
        if ($this->sessionId !== null) {
            return;
        }

        $response = $this->send([
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [],
                'clientInfo' => ['name' => 'shippercli-provider-coolify', 'version' => '1.0.0'],
            ],
        ], false);
        $result = $response['message']['result'] ?? null;
        if (! \is_array($result) || ! \is_string($result['protocolVersion'] ?? null)) {
            throw new \RuntimeException('Coolify MCP returned an invalid initialize response.');
        }
        $this->protocolVersion = $result['protocolVersion'];
        $this->sessionId = $response['headers']['mcp-session-id'] ?? '';

        $this->send([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], true);
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function rpc(string $method, array $params): array
    {
        $response = $this->send([
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => $method,
            'params' => $params,
        ], false);
        $message = $response['message'];
        if (isset($message['error']) && \is_array($message['error'])) {
            $error = $message['error']['message'] ?? 'unknown MCP error';
            throw new \RuntimeException('Coolify MCP request failed: '.(\is_string($error) ? $error : 'unknown MCP error'));
        }

        return $message;
    }

    /** @param array<string, mixed> $message
     *  @return array{message: array<string, mixed>, headers: array<string, string>}
     */
    private function send(array $message, bool $notification): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        if ($message['method'] !== 'initialize') {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
            if ($this->sessionId !== null && $this->sessionId !== '') {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }
        }

        $method = $message['method'];
        $safeToRetry = \in_array($method, ['tools/list', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get'], true);
        for ($attempt = 0; ; $attempt++) {
            if ($this->transport !== null) {
                $response = ($this->transport)($message, $headers);
            } else {
                $response = $this->curlPost($message, $headers);
            }

            $status = $response['status'] ?? null;
            $responseHeaders = [];
            foreach (($response['headers'] ?? []) as $name => $value) {
                if (\is_string($name) && \is_string($value)) {
                    $responseHeaders[\strtolower($name)] = $value;
                }
            }
            $retryAfterValue = $responseHeaders['retry-after'] ?? null;
            $retryAfter = \is_string($retryAfterValue) ? \filter_var(\trim($retryAfterValue), FILTER_VALIDATE_INT) : false;
            if ($status === 429 && $safeToRetry && $retryAfter !== false && $retryAfter !== null && $attempt < 2) {
                \sleep($retryAfter);
                \usleep(\random_int(0, 250_000));
                continue;
            }
            if (! \is_int($status) || $status < 200 || $status >= 300) {
                $retryMessage = $status === 429 && $retryAfter !== false && $retryAfter !== null ? " Retry after {$retryAfter} seconds." : '';
                throw new \RuntimeException('Coolify MCP HTTP request failed ('.(\is_int($status) ? $status : 'unknown status').').'.$retryMessage);
            }

            break;
        }
        $body = $response['body'] ?? '';
        if (! \is_string($body)) {
            throw new \RuntimeException('Coolify MCP returned an invalid HTTP body.');
        }
        if ($notification && ($status === 202 || $body === '')) {
            return ['message' => [], 'headers' => $responseHeaders];
        }

        $messages = $this->decodeMessages($body);
        foreach ($messages as $decoded) {
            if (\is_array($decoded) && ($decoded['id'] ?? null) === ($message['id'] ?? null)) {
                return ['message' => $decoded, 'headers' => $responseHeaders];
            }
        }
        throw new \RuntimeException('Coolify MCP response did not contain the matching JSON-RPC response.');
    }

    /** @param array<string, mixed> $message
     *  @param array<string, string> $headers
     *  @return array<string, mixed>
     */
    private function curlPost(array $message, array $headers): array
    {
        if (! \function_exists('curl_init')) {
            throw new \RuntimeException('The Coolify provider requires the PHP cURL extension.');
        }
        $handle = \curl_init(\rtrim($this->baseUrl, '/').'/mcp');
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize a Coolify MCP request.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        \curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => \json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        $rawResponse = \curl_exec($handle);
        $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) \curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $curlError = \curl_error($handle);
        \curl_close($handle);
        if ($rawResponse === false) {
            throw new \RuntimeException('Coolify MCP transport error: '.$curlError);
        }

        $rawHeaders = \substr($rawResponse, 0, $headerSize);
        $responseHeaders = [];
        foreach (\preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (\str_contains($line, ':')) {
                [$name, $value] = \explode(':', $line, 2);
                $responseHeaders[\strtolower(\trim($name))] = \trim($value);
            }
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => \substr($rawResponse, $headerSize),
        ];
    }

    /** @return array<int, mixed> */
    private function decodeMessages(string $body): array
    {
        try {
            $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return \is_array($decoded) ? [$decoded] : [];
        } catch (\JsonException) {
            $messages = [];
            foreach (\preg_split('/\r?\n\r?\n/', \trim($body)) ?: [] as $event) {
                $data = [];
                foreach (\preg_split('/\r?\n/', $event) ?: [] as $line) {
                    if (\str_starts_with($line, 'data:')) {
                        $data[] = \ltrim(\substr($line, 5));
                    }
                }
                if ($data === []) {
                    continue;
                }
                try {
                    $decoded = \json_decode(\implode("\n", $data), true, 512, JSON_THROW_ON_ERROR);
                    if (\is_array($decoded)) {
                        $messages[] = $decoded;
                    }
                } catch (\JsonException) {
                    // Ignore non-JSON SSE events and continue to the response event.
                }
            }

            return $messages;
        }
    }

    /** @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private function toolPayload(array $result): array
    {
        if (\is_array($result['structuredContent'] ?? null)) {
            return $result['structuredContent'];
        }
        foreach (($result['content'] ?? []) as $content) {
            $text = \is_array($content) ? ($content['text'] ?? null) : null;
            if (! \is_string($text)) {
                continue;
            }
            try {
                $decoded = \json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                // Continue in case a later content block contains structured JSON.
            }
        }

        throw new \RuntimeException('Coolify MCP returned no structured preview data.');
    }

    private function findNestedValue(array $value, string $key): mixed
    {
        if (\array_key_exists($key, $value)) {
            return $value[$key];
        }
        foreach ($value as $child) {
            if (\is_array($child)) {
                $found = $this->findNestedValue($child, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
