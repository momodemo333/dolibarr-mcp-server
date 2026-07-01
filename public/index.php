<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Accept Dolibarr credentials via HTTP headers (sent by DalfredAgent)
// These override .env values, allowing per-user API keys.
$headers = getallheaders();
if (!empty($headers['X-Dolibarr-Url'])) {
    putenv('DOLIBARR_URL=' . $headers['X-Dolibarr-Url']);
}
if (!empty($headers['X-Dolibarr-Api-Key'])) {
    putenv('DOLIBARR_API_KEY=' . $headers['X-Dolibarr-Api-Key']);
}

DolibarrMcp\Bootstrap::run('http');
