<?php

declare(strict_types=1);

namespace DolibarrMcp\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class ApiSchemaClient
{
    private string $baseUrl;
    private string $apiKey;
    private ?array $schema = null;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
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

    /**
     * Fetch the Swagger/OpenAPI schema from Dolibarr
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $url = $this->baseUrl . '/api/index.php/explorer/swagger.json';

        $client = new Client(['timeout' => 30]);

        try {
            $response = $client->get($url, [
                'headers' => [
                    'DOLAPIKEY' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $body = $response->getBody()->getContents();
            $this->schema = json_decode($body, true);

            if (!is_array($this->schema)) {
                throw new RuntimeException('Invalid Swagger schema received');
            }

            return $this->schema;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'cURL error 6') || str_contains($message, 'Could not resolve host')) {
                throw new RuntimeException('API unreachable: Cannot resolve hostname. Please check DOLIBARR_URL is correct.');
            }
            if (str_contains($message, 'cURL error 7') || str_contains($message, 'Connection refused')) {
                throw new RuntimeException('API unreachable: Connection refused. Please check if Dolibarr is running and accessible.');
            }
            if (str_contains($message, '401') || str_contains($message, 'Unauthorized')) {
                throw new RuntimeException('API authentication failed: Invalid API key. Please check DOLIBARR_API_KEY.');
            }
            if (str_contains($message, '403') || str_contains($message, 'Forbidden')) {
                throw new RuntimeException('API access denied: The API key does not have permission to access the API explorer.');
            }
            if (str_contains($message, '404')) {
                throw new RuntimeException('API not found: The API module may not be enabled in Dolibarr. Enable it in Home > Setup > Modules > API REST.');
            }
            throw new RuntimeException('Failed to connect to Dolibarr API: ' . $message);
        }
    }

    /**
     * Get list of available modules (tags) with their endpoint counts
     *
     * @return array<string, array{name: string, endpoints: int, operations: array<string>}>
     */
    public function getModules(): array
    {
        $schema = $this->getSchema();
        $modules = [];

        foreach ($schema['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $info) {
                if (!is_array($info) || !isset($info['tags'])) {
                    continue;
                }

                foreach ($info['tags'] as $tag) {
                    if (!isset($modules[$tag])) {
                        $modules[$tag] = [
                            'name' => $tag,
                            'endpoints' => 0,
                            'operations' => [],
                        ];
                    }

                    $modules[$tag]['endpoints']++;
                    $operationId = $info['operationId'] ?? "{$method}_{$path}";
                    $modules[$tag]['operations'][] = [
                        'method' => strtoupper($method),
                        'path' => $path,
                        'operationId' => $operationId,
                        'summary' => $info['summary'] ?? '',
                    ];
                }
            }
        }

        ksort($modules);
        return $modules;
    }

    /**
     * Get endpoints for a specific module
     *
     * @return array<int, array{method: string, path: string, operationId: string, summary: string, description: string, parameters: array}>
     */
    public function getModuleEndpoints(string $module): array
    {
        $schema = $this->getSchema();
        $endpoints = [];

        foreach ($schema['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $info) {
                if (!is_array($info)) {
                    continue;
                }

                $tags = $info['tags'] ?? [];

                if (!in_array($module, $tags, true)) {
                    continue;
                }

                $endpoints[] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'operationId' => $info['operationId'] ?? '',
                    'summary' => $info['summary'] ?? '',
                    'description' => $info['description'] ?? '',
                    'parameters' => $this->extractParameters($info['parameters'] ?? []),
                ];
            }
        }

        return $endpoints;
    }

    /**
     * Get all available endpoints grouped by HTTP method type
     *
     * @return array<string, array<string, array{path: string, module: string, summary: string}>>
     */
    public function getEndpointsByType(): array
    {
        $schema = $this->getSchema();
        $grouped = [
            'list' => [],    // GET without {id} - collection endpoints
            'get' => [],     // GET with {id} - single resource
            'create' => [],  // POST
            'update' => [],  // PUT
            'delete' => [],  // DELETE
            'other' => [],   // Other operations
        ];

        foreach ($schema['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $info) {
                if (!is_array($info)) {
                    continue;
                }

                $module = $info['tags'][0] ?? 'unknown';
                $hasIdParam = str_contains($path, '{id}') || preg_match('/\{[a-z_]*id\}/i', $path);

                $entry = [
                    'path' => $path,
                    'module' => $module,
                    'summary' => $info['summary'] ?? '',
                    'operationId' => $info['operationId'] ?? '',
                ];

                switch (strtoupper($method)) {
                    case 'GET':
                        if ($hasIdParam) {
                            $grouped['get'][$path] = $entry;
                        } else {
                            $grouped['list'][$path] = $entry;
                        }
                        break;
                    case 'POST':
                        $grouped['create'][$path] = $entry;
                        break;
                    case 'PUT':
                        $grouped['update'][$path] = $entry;
                        break;
                    case 'DELETE':
                        $grouped['delete'][$path] = $entry;
                        break;
                    default:
                        $grouped['other'][$path] = $entry;
                }
            }
        }

        return $grouped;
    }

    /**
     * Get parameters for a specific endpoint
     *
     * @return array<int, array{name: string, type: string, in: string, required: bool, description: string}>
     */
    public function getEndpointParameters(string $path, string $method = 'GET'): array
    {
        $schema = $this->getSchema();
        $method = strtolower($method);

        if (!isset($schema['paths'][$path][$method])) {
            return [];
        }

        return $this->extractParameters($schema['paths'][$path][$method]['parameters'] ?? []);
    }

    /**
     * Extract and normalize parameters from Swagger format
     *
     * @param array<int, array<string, mixed>> $parameters
     * @return array<int, array{name: string, type: string, in: string, required: bool, description: string}>
     */
    private function extractParameters(array $parameters): array
    {
        $result = [];

        foreach ($parameters as $param) {
            $result[] = [
                'name' => $param['name'] ?? '',
                'type' => $param['type'] ?? $param['schema']['type'] ?? 'string',
                'in' => $param['in'] ?? 'query',
                'required' => $param['required'] ?? false,
                'description' => $param['description'] ?? '',
            ];
        }

        return $result;
    }
}
