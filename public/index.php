<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DolibarrMcp\Bootstrap;
use DolibarrMcp\Config\ConnectionConfig;

// Accept Dolibarr credentials via HTTP headers (per-user, request-scoped).
// Falls back to .env / environment variables when headers are absent.
$headers = function_exists('getallheaders') ? getallheaders() : [];
$url = $headers['X-Dolibarr-Url'] ?? '';
$apiKey = $headers['X-Dolibarr-Api-Key'] ?? '';

$config = ($url !== '' && $apiKey !== '') ? new ConnectionConfig($url, $apiKey) : null;

Bootstrap::loadEnv();

try {
    Bootstrap::emit(Bootstrap::handleHttpRequest(null, null, $config));
} catch (\Throwable $e) {
    // E.g. malformed Mcp-Session-Id (the SDK requires a valid UUID)
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32600, 'message' => $e->getMessage()],
    ]);
}
