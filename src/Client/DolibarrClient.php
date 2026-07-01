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
        int $timeout = 30
    ) {
        $this->apiKey = $apiKey;
        $this->httpClient = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/api/index.php/',
            'timeout' => $timeout,
            'headers' => [
                'DOLAPIKEY' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
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
                throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg() . ' (body: ' . substr($body, 0, 100) . ')');
            }

            return $decoded;
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response !== null) {
                $body = $response->getBody()->getContents();
                $decoded = json_decode($body, true);
                $errorMessage = $decoded['error']['message']
                    ?? $decoded['error']
                    ?? $body
                    ?? 'Unknown error';
                throw new RuntimeException(
                    "Dolibarr API error ({$response->getStatusCode()}): {$errorMessage}"
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
}
