<?php

declare(strict_types=1);

namespace DolibarrMcp\Config;

use RuntimeException;

/**
 * Request-scoped Dolibarr connection settings (instance URL + user API key).
 *
 * Prefer passing an instance of this class explicitly (per request, per
 * user) over relying on process environment variables: under PHP-FPM the
 * environment is shared by every request served by a worker, so global
 * state is a cross-user leak waiting to happen. fromEnvironment() remains
 * as a fallback for CLI/stdio usage where one process serves one user.
 */
final class ConnectionConfig
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly int $timeout = 30,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $baseUrl = getenv('DOLIBARR_URL');
        $apiKey = getenv('DOLIBARR_API_KEY');

        if (!$baseUrl || !$apiKey) {
            throw new RuntimeException(
                'Missing required environment variables: DOLIBARR_URL and DOLIBARR_API_KEY'
            );
        }

        return new self($baseUrl, $apiKey);
    }
}
