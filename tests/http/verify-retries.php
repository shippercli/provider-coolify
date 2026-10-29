<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderCoolify\CoolifyApiClient;

$client = new CoolifyApiClient($argv[1], 'synthetic-local-only', timeout: 1);
if (($client->request('GET', '/retry-short')['uuid'] ?? null) !== 'ready') {
    throw new RuntimeException('Short safe retries did not complete');
}
foreach ([['POST', '/retry-post', null], ['GET', '/retry-long', 3600], ['GET', '/retry-invalid', null]] as [$method, $path, $hint]) {
    $failure = null;
    try { $client->request($method, $path); } catch (RuntimeException $error) { $failure = $error; }
    if ($failure === null || ! str_contains($failure->getMessage(), '(HTTP 429)')) {
        throw new RuntimeException('Rate limit did not return a clear failure');
    }
    if ($hint !== null && ! str_contains($failure->getMessage(), 'Retry after '.$hint.' seconds')) {
        throw new RuntimeException('Long retry hint was lost');
    }
}
echo "HTTP retry checks passed\n";
