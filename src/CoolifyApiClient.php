<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

final class CoolifyApiClient
{
    /** @var (\Closure(string, string, array<string, mixed>|null): mixed)|null */
    private readonly ?\Closure $transport;
    /** @var (\Closure(string, string, array<string, mixed>, array<string, string>): mixed)|null */
    private readonly ?\Closure $multipartTransport;

    /**
     * @param (callable(string, string, array<string, mixed>|null): mixed)|null $transport
     * @param (callable(string, string, array<string, mixed>, array<string, string>): mixed)|null $multipartTransport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 30,
        private readonly bool $verifySsl = true,
        ?callable $transport = null,
        ?callable $multipartTransport = null,
    ) {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
        $this->multipartTransport = $multipartTransport === null ? null : \Closure::fromCallable($multipartTransport);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => '[redacted]', 'token' => '[redacted]', 'timeout' => $this->timeout, 'verifySsl' => $this->verifySsl];
    }

    /** @param array<string, mixed>|null $body
     *  @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        return $this->requestJson($method, $path, $body, false);
    }

    /** @return array<string, mixed> */
    public function requestInventory(string $path): array
    {
        return $this->requestJson('GET', $path, null, true);
    }

    /** @param array<string, mixed>|null $body
     *  @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $body, bool $inventory): array
    {
        if (! \str_starts_with($path, '/') || \str_contains($path, "\n") || \str_contains($path, "\r")) {
            throw new \InvalidArgumentException('Coolify API paths must be absolute API paths.');
        }

        if ($this->transport !== null) {
            $response = ($this->transport)(\strtoupper($method), $path, $body);
            if (! \is_array($response)) {
                throw new \RuntimeException('Coolify test transport returned a non-object response.');
            }

            if ($inventory) {
                return $this->decodeInventory(\json_encode($response, JSON_THROW_ON_ERROR));
            }
            return $response;
        }

        if (! \function_exists('curl_init')) {
            throw new \RuntimeException('The Coolify provider requires the PHP cURL extension.');
        }

        $method = \strtoupper($method);
        $headers = [
            'Authorization: Bearer '.$this->token,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];
        if ($body !== null) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = \json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        [$responseBody, $status, $retryAfter] = $this->executeRequest($method, $path, $options);
        if ($status < 200 || $status >= 300) throw $this->httpFailure($status, $retryAfter);
        if ($responseBody === '') {
            $decoded = [];
        } else {
            try {
                $decoded = \json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Coolify API returned invalid JSON.', 0, $exception);
            }
        }
        if (! \is_array($decoded)) {
            throw new \RuntimeException('Coolify API returned an invalid response.');
        }


        return $inventory ? $this->decodeInventory($responseBody) : $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeInventory(string $body): array
    {
        if ($body === '') {
            throw new \RuntimeException('Coolify inventory response has no JSON body.');
        }
        try {
            $shape = \json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Coolify inventory response has invalid JSON.', 0, $error);
        }
        if (\is_array($shape)) return $decoded;
        if (! $shape instanceof \stdClass) {
            throw new \RuntimeException('Coolify inventory response must contain a JSON list.');
        }
        $hasData = \property_exists($shape, 'data');
        $hasItems = \property_exists($shape, 'items');
        if ($hasData === $hasItems || ! \is_array($hasData ? $shape->data : $shape->items)) {
            throw new \RuntimeException('Coolify inventory response has an invalid JSON list envelope.');
        }
        return $decoded;
    }

    public function requestText(string $method, string $path): string
    {
        if (! \str_starts_with($path, '/') || \str_contains($path, "\n") || \str_contains($path, "\r")) {
            throw new \InvalidArgumentException('Coolify API paths must be absolute API paths.');
        }
        $method = \strtoupper($method);
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $path, null);
            if (\is_string($response)) {
                return $response;
            }
            if (\is_array($response) && \is_string($response['__text'] ?? null)) {
                return $response['__text'];
            }

            throw new \RuntimeException('Coolify test transport did not return a text response.');
        }
        if (! \function_exists('curl_init')) {
            throw new \RuntimeException('The Coolify provider requires the PHP cURL extension.');
        }

        [$responseBody, $status, $retryAfter] = $this->executeRequest($method, $path, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$this->token, 'Accept: text/plain, application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        if ($status < 200 || $status >= 300) throw $this->httpFailure($status, $retryAfter);

        return $responseBody;
    }

    /** @param array<string, mixed> $fields
     *  @param array<string, string> $files Multipart field name to readable local file path.
     *  @return array<string, mixed>
     */
    public function requestMultipart(string $method, string $path, array $fields, array $files): array
    {
        if (! \str_starts_with($path, '/') || \str_contains($path, "\n") || \str_contains($path, "\r")) {
            throw new \InvalidArgumentException('Coolify API paths must be absolute API paths.');
        }
        if ($files === []) {
            throw new \InvalidArgumentException('Coolify multipart requests require at least one file.');
        }
        foreach ($files as $field => $filePath) {
            if ($field === '' || ! \is_file($filePath) || ! \is_readable($filePath)) {
                throw new \InvalidArgumentException('Coolify multipart file fields must reference readable files.');
            }
        }

        $method = \strtoupper($method);
        if ($this->multipartTransport !== null) {
            $response = ($this->multipartTransport)($method, $path, $fields, $files);
            if (! \is_array($response)) {
                throw new \RuntimeException('Coolify test transport returned a non-object response.');
            }

            return $response;
        }
        if (! \function_exists('curl_init') || ! \class_exists(\CURLFile::class)) {
            throw new \RuntimeException('The Coolify provider requires the PHP cURL extension.');
        }

        $multipart = $fields;
        foreach ($files as $field => $filePath) {
            $mimeType = \function_exists('mime_content_type') ? (\mime_content_type($filePath) ?: 'application/octet-stream') : 'application/octet-stream';
            $multipart[$field] = new \CURLFile($filePath, $mimeType, \basename($filePath));
        }
        [$responseBody, $status, $retryAfter] = $this->executeRequest($method, $path, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$this->token, 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $multipart,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        if ($status < 200 || $status >= 300) throw $this->httpFailure($status, $retryAfter);
        try {
            $decoded = $responseBody === '' ? [] : \json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Coolify API returned invalid JSON.', 0, $exception);
        }
        if (! \is_array($decoded)) {
            throw new \RuntimeException('Coolify API returned an invalid response.');
        }


        return $decoded;
    }

    private function httpFailure(int $status, ?int $retryAfter): \RuntimeException
    {
        $reason = match ($status) {
            400 => 'bad request',
            401 => 'unauthenticated',
            403 => 'access denied',
            404 => 'resource not found',
            409 => 'resource conflict',
            422 => 'validation failed',
            429 => 'rate limit exceeded',
            default => $status >= 500 ? 'server error' : 'request rejected',
        };
        $retry = $status === 429 && $retryAfter !== null ? " Retry after {$retryAfter} seconds." : '';
        return new \RuntimeException("Coolify API request failed (HTTP {$status}): {$reason}.{$retry}");
    }

    /**
     * @param array<int, mixed> $options
     * @return array{string, int, int|null}
     */
    private function executeRequest(string $method, string $path, array $options): array
    {
        $safeToRetry = \in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        for ($attempt = 0; ; $attempt++) {
            $retryAfter = null;
            $handle = \curl_init(\rtrim($this->baseUrl, '/').'/api/v1'.$path);
            if ($handle === false) {
                throw new \RuntimeException('Unable to initialize a Coolify API request.');
            }
            $attemptOptions = $options;
            $attemptOptions[CURLOPT_HEADERFUNCTION] = static function ($curl, string $header) use (&$retryAfter): int {
                if (\preg_match('/^Retry-After:\\s*(\\d+)\\s*$/i', \trim($header), $matches) === 1) {
                    $parsed = \filter_var($matches[1], FILTER_VALIDATE_INT);
                    $retryAfter = $parsed === false ? null : $parsed;
                }

                return \strlen($header);
            };
            \curl_setopt_array($handle, $attemptOptions);
            $responseBody = \curl_exec($handle);
            $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlErrno = \curl_errno($handle);
            \curl_close($handle);

            if ($responseBody === false) {
                $reason = match ($curlErrno) {
                    CURLE_OPERATION_TIMEDOUT => 'request timed out',
                    CURLE_COULDNT_RESOLVE_HOST => 'host could not be resolved',
                    CURLE_COULDNT_CONNECT => 'connection failed',
                    CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT => 'TLS connection failed',
                    CURLE_URL_MALFORMAT => 'invalid request URL',
                    default => 'request failed',
                };
                throw new \RuntimeException('Coolify API transport error: '.$reason.'.');
            }
            if ($status !== 429 || ! $safeToRetry || $retryAfter === null || $attempt >= 2) {
                return [$responseBody, $status, $retryAfter];
            }

            \sleep($retryAfter);
            \usleep(\random_int(0, 250_000));
        }
    }
}
