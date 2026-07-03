<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\ApiSchemaClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class ExplorerTools
{
    public function __construct(private ApiSchemaClient $schemaClient) {}

    #[McpTool(
        name: 'dolibarr_api_explorer',
        description: 'Explore the Dolibarr API: list available modules, endpoints, and their parameters. Use this to discover what operations are available before using other tools.'
    )]
    public function apiExplorer(
        #[Schema(description: 'What to explore: "modules" (list all modules), "endpoints" (list endpoints for a module), or "parameters" (get parameters for a specific endpoint)')]
        string $action = 'modules',
        #[Schema(description: 'Module name to explore (required for "endpoints" and "parameters" actions). Examples: thirdparties, invoices, products, orders')]
        ?string $module = null,
        #[Schema(description: 'Endpoint path (required for "parameters" action). Example: /thirdparties, /invoices/{id}')]
        ?string $endpoint = null,
        #[Schema(description: 'HTTP method for parameters action. Default: GET')]
        string $method = 'GET'
    ): string {
        $module = ($module === '' || $module === 'null') ? null : $module;
        $endpoint = ($endpoint === '' || $endpoint === 'null') ? null : $endpoint;
        $action = ($action === '' || $action === null) ? 'modules' : $action;
        $method = ($method === '' || $method === null) ? 'GET' : strtoupper($method);

        switch ($action) {
            case 'modules':
                $modules = $this->schemaClient->getModules();
                if (empty($modules)) {
                    return json_encode([
                        'error' => true,
                        'code' => 'NO_MODULES_FOUND',
                        'message' => 'No API modules found. Please check: 1) API module is enabled in Dolibarr, 2) URL is correct, 3) API key is valid and has proper permissions',
                        'total_modules' => 0,
                        'modules' => [],
                    ], JSON_PRETTY_PRINT);
                }
                $result = [
                    'description' => 'Available Dolibarr API modules',
                    'total_modules' => count($modules),
                    'modules' => array_map(fn($m) => [
                        'name' => $m['name'],
                        'endpoints_count' => $m['endpoints'],
                    ], $modules),
                ];
                break;

            case 'endpoints':
                if ($module === null) {
                    return json_encode(['error' => 'Module parameter is required for "endpoints" action'], JSON_PRETTY_PRINT);
                }
                $endpoints = $this->schemaClient->getModuleEndpoints($module);
                $result = [
                    'module' => $module,
                    'total_endpoints' => count($endpoints),
                    'endpoints' => $endpoints,
                ];
                break;

            case 'parameters':
                if ($endpoint === null) {
                    return json_encode(['error' => 'Endpoint parameter is required for "parameters" action'], JSON_PRETTY_PRINT);
                }
                $params = $this->schemaClient->getEndpointParameters($endpoint, $method);
                $result = [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'parameters' => $params,
                ];
                break;

            default:
                $result = ['error' => 'Invalid action. Use: modules, endpoints, or parameters'];
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
