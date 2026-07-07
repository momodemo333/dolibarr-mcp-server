<?php

declare(strict_types=1);

namespace DolibarrMcp\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class DolibarrClient
{
    private Client $httpClient;
    private string $apiKey;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        int $timeout = 30,
        ?callable $handler = null
    ) {
        $this->apiKey = $apiKey;
        $config = [
            'base_uri' => rtrim($baseUrl, '/') . '/api/index.php/',
            'timeout' => $timeout,
            'headers' => [
                'DOLAPIKEY' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->httpClient = new Client($config);
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
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>|int|string
     */
    public function get(string $endpoint, array $queryParams = []): array|int|string
    {
        return $this->request('GET', $endpoint, ['query' => $queryParams]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|int|string
     */
    public function post(string $endpoint, array $data = []): array|int|string
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|int|string
     */
    public function put(string $endpoint, array $data = []): array|int|string
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>|int|string
     */
    public function delete(string $endpoint, array $queryParams = []): array|int|string
    {
        $options = [];
        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }
        return $this->request('DELETE', $endpoint, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|int|string
     */
    private function request(string $method, string $endpoint, array $options = []): array|int|string
    {
        // A leading slash would make Guzzle resolve the path against the host
        // root (RFC 3986), silently dropping /api/index.php/ and landing on the
        // Dolibarr web front controller (HTML/CSRF page) instead of the API.
        $endpoint = ltrim($endpoint, '/');

        try {
            $response = $this->httpClient->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();

            // Trim whitespace and BOM that might be present in the response
            $body = trim($body, " \t\n\r\0\x0B\xEF\xBB\xBF");

            // Handle empty responses (some Dolibarr endpoints return empty body on success)
            if ($body === '') {
                return ['success' => true, 'message' => 'Operation completed (empty response from API)'];
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Some endpoints return plain integers (like create/add_line operations)
                // The response might be just an ID number
                if (is_numeric($body)) {
                    return (int) $body;
                }
                // Also handle case where response might be a quoted string containing a number
                if (preg_match('/^"?(\d+)"?$/', $body, $matches)) {
                    return (int) $matches[1];
                }
                // A wrong URL can land on a web page that answers 200 with HTML
                if ($this->looksLikeHtml($body)) {
                    throw new RuntimeException('Dolibarr API error: ' . $this->formatErrorBody($body, $endpoint));
                }
                throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg() . ' (body: ' . substr($body, 0, 100) . ')');
            }

            return $decoded;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response !== null) {
                $body = $response->getBody()->getContents();
                throw new RuntimeException(
                    "Dolibarr API error ({$response->getStatusCode()}): " . $this->formatErrorBody($body, $endpoint)
                );
            }
            throw new RuntimeException('HTTP request failed: ' . $e->getMessage());
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'cURL error 6') || str_contains($message, 'Could not resolve host')) {
                throw new RuntimeException('API unreachable: Cannot resolve hostname. Please check DOLIBARR_URL is correct.');
            }
            if (str_contains($message, 'cURL error 7') || str_contains($message, 'Connection refused')) {
                throw new RuntimeException('API unreachable: Connection refused. Please check if Dolibarr is running and accessible.');
            }
            throw new RuntimeException('HTTP request failed: ' . $message);
        }
    }

    /**
     * Turn an error body into a short, actionable message for the calling LLM.
     * Raw bodies are never passed through untruncated: an HTML login/CSRF
     * page would otherwise flood the tool result with kilobytes of markup.
     */
    private function formatErrorBody(string $body, string $endpoint): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? null;
            if (is_array($message)) {
                $message = json_encode($message, JSON_UNESCAPED_UNICODE);
            }
            if (is_string($message) && $message !== '') {
                return substr($message, 0, 300);
            }
            return substr(json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: 'Unknown error', 0, 300);
        }

        if ($this->looksLikeHtml($body)) {
            return "The server answered with an HTML page instead of a REST API response."
                . " The endpoint '{$endpoint}' most likely does not exist on this Dolibarr."
                . " Resource names are lowercase and plural (e.g. 'thirdparties', 'invoices', 'projects')."
                . " Use dolibarr_api_explorer to list valid endpoints, and check that the matching"
                . " Dolibarr module and its API are enabled.";
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return 'Empty error response from the API';
        }

        return substr($trimmed, 0, 300);
    }

    private function looksLikeHtml(string $body): bool
    {
        $start = strtolower(ltrim($body));

        return str_starts_with($start, '<!doctype')
            || str_starts_with($start, '<html')
            || str_contains($start, '<html');
    }
}
