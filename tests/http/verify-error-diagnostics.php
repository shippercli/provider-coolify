<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderCoolify\CoolifyApiClient;

$client = new CoolifyApiClient($argv[1], 'synthetic-api-token');
$file = tempnam(sys_get_temp_dir(), 'shipper-diagnostic-');
file_put_contents($file, 'synthetic-upload-secret');
try {
    foreach ([
        [fn () => $client->request('POST', '/fail-json', ['password' => 'synthetic-db-secret']), 422],
        [fn () => $client->requestText('GET', '/fail-text'), 401],
        [fn () => $client->requestMultipart('POST', '/fail-multipart', ['password' => 'synthetic-db-secret'], ['file' => $file]), 403],
        [fn () => $client->request('GET', '/fail-html'), 502],
        [fn () => $client->request('POST', '/fail-rate'), 429],
    ] as [$operation, $status]) {
        $failure = null;
        try { $operation(); } catch (RuntimeException $error) { $failure = $error; }
        if ($failure === null || ! str_contains($failure->getMessage(), '(HTTP '.$status.')')) {
            throw new RuntimeException('HTTP status was not retained');
        }
        foreach (['synthetic-api-token', 'synthetic-db-secret', 'synthetic-upload-secret'] as $secret) {
            if (str_contains($failure->getMessage(), $secret)) throw new RuntimeException('Error diagnostics exposed a synthetic credential');
        }
        if ($status === 429 && ! str_contains($failure->getMessage(), 'Retry after 2 seconds')) {
            throw new RuntimeException('Rate limit hint was lost');
        }
    }
} finally { unlink($file); }
echo "HTTP error diagnostics checks passed\n";
