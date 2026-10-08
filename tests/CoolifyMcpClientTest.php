<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyMcpClient;

final class CoolifyMcpClientTest extends TestCase
{
    public function testItDiscoversAndReadsEveryPageOfApplicationPreviews(): void
    {
        $requests = [];
        $transport = static function (array $message, array $headers) use (&$requests): array {
            $requests[] = [$message, $headers];
            $method = $message['method'];
            if ($method === 'notifications/initialized') {
                return ['status' => 202, 'headers' => [], 'body' => ''];
            }

            $result = match ($method) {
                'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'serverInfo' => ['name' => 'Coolify', 'version' => '4.3.23']],
                'tools/list' => ['tools' => [['name' => 'list_application_previews', 'inputSchema' => ['type' => 'object']]]],
                'tools/call' => self::previewPage($message['params']['arguments']),
                default => throw new \LogicException('Unexpected MCP method: '.$method),
            };

            return [
                'status' => 200,
                'headers' => [],
                'body' => \json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $message['id'],
                    'result' => $result,
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new CoolifyMcpClient('https://coolify.example.test', 'secret', transport: $transport);

        self::assertSame([
            ['pull_request_id' => 48, 'fqdn' => 'https://48.preview.example.test', 'status' => 'running'],
            ['pull_request_id' => 47, 'fqdn' => 'https://47.preview.example.test', 'status' => 'stopped'],
        ], $client->listApplicationPreviews('application-uuid'));

        $toolCalls = \array_values(\array_filter($requests, static fn (array $request): bool => $request[0]['method'] === 'tools/call'));
        self::assertCount(2, $toolCalls);
        self::assertSame(['uuid' => 'application-uuid', 'page' => 1, 'per_page' => 100], $toolCalls[0][0]['params']['arguments']);
        self::assertSame(['uuid' => 'application-uuid', 'page' => 2, 'per_page' => 100], $toolCalls[1][0]['params']['arguments']);
    }

    public function testItUsesBearerAuthenticationAndTheServerSession(): void
    {
        $headersSeen = [];
        $transport = static function (array $message, array $headers) use (&$headersSeen): array {
            $headersSeen[] = $headers;
            if ($message['method'] === 'notifications/initialized') {
                return ['status' => 202, 'headers' => [], 'body' => ''];
            }
            $result = match ($message['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25'],
                'tools/list' => ['tools' => [['name' => 'list_application_previews']]],
                'tools/call' => ['structuredContent' => ['data' => ['previews' => []]]],
                default => [],
            };
            $responseHeaders = $message['method'] === 'initialize' ? ['Mcp-Session-Id' => 'session-abc'] : [];

            return [
                'status' => 200,
                'headers' => $responseHeaders,
                'body' => \json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR),
            ];
        };

        (new CoolifyMcpClient('https://coolify.example.test', 'secret', transport: $transport))
            ->listApplicationPreviews('application-uuid');

        self::assertSame('Bearer secret', $headersSeen[0]['Authorization']);
        self::assertSame('session-abc', $headersSeen[2]['Mcp-Session-Id']);
        self::assertSame('2025-11-25', $headersSeen[2]['MCP-Protocol-Version']);
    }

    public function testDebugOutputRedactsTheEndpointAndToken(): void
    {
        $client = new CoolifyMcpClient('https://coolify.example.test', 'secret-token');

        self::assertSame([
            'baseUrl' => '[redacted]',
            'token' => '[redacted]',
            'timeout' => 30,
            'verifySsl' => true,
        ], $client->__debugInfo());
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    private static function previewPage(array $arguments): array
    {
        if ($arguments['page'] === 1) {
            return ['structuredContent' => [
                'data' => ['previews' => [['pull_request_id' => 48, 'fqdn' => 'https://48.preview.example.test', 'status' => 'running']]],
                '_pagination' => ['next' => ['args' => ['page' => 2, 'per_page' => 100]]],
            ]];
        }

        return ['structuredContent' => [
            'data' => ['previews' => [['pull_request_id' => 47, 'fqdn' => 'https://47.preview.example.test', 'status' => 'stopped']]],
            '_pagination' => ['next' => null],
        ]];
    }
}
