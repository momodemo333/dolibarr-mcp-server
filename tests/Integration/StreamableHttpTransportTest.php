<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Integration;

use DolibarrMcp\Bootstrap;
use DolibarrMcp\Config\ConnectionConfig;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Integration tests of the per-request Streamable HTTP entry point.
 *
 * These run the full stack (transport middleware, JSON-RPC dispatch, tool
 * discovery, file-backed sessions) with in-memory PSR-7 requests — no web
 * server, no network, no daemon. Tool *execution* is not covered here (it
 * would require a live Dolibarr REST API); discovery and protocol flows are.
 */
final class StreamableHttpTransportTest extends TestCase
{
    private string $sessionDir;

    private ConnectionConfig $config;

    protected function setUp(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/dolibarr-mcp-test-sessions-' . uniqid();
        $this->config = new ConnectionConfig('https://dolibarr.invalid', 'test-key');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sessionDir)) {
            array_map('unlink', glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
    }

    private function handle(ServerRequest $request): ResponseInterface
    {
        return Bootstrap::handleHttpRequest($request, $this->sessionDir, $this->config);
    }

    private function postJson(array $payload, array $headers = []): ServerRequest
    {
        $request = new ServerRequest('POST', 'https://example.org/mcp.php', array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ], $headers), json_encode($payload));

        return $request;
    }

    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ];
    }

    /**
     * Run the initialize handshake and return the session id.
     */
    private function initializeSession(): string
    {
        $response = $this->handle($this->postJson($this->initializePayload()));

        $this->assertSame(200, $response->getStatusCode());
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId, 'initialize must return a Mcp-Session-Id header');

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Dolibarr MCP Server', $body['result']['serverInfo']['name']);

        // Client acknowledges the handshake
        $notif = $this->postJson(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['Mcp-Session-Id' => $sessionId]
        );
        $this->assertSame(202, $this->handle($notif)->getStatusCode());

        return $sessionId;
    }

    public function testInitializeReturnsServerInfoAndSession(): void
    {
        $sessionId = $this->initializeSession();

        $this->assertNotEmpty($sessionId);
        $this->assertNotEmpty(glob($this->sessionDir . '/*'), 'session must be persisted on disk');
    }

    public function testSessionSurvivesAcrossRequests(): void
    {
        $sessionId = $this->initializeSession();

        // A separate request (new transport, new server instance — as under
        // PHP-FPM) must find the session again.
        $response = $this->handle($this->postJson(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []],
            ['Mcp-Session-Id' => $sessionId]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertNotEmpty($body['result']['tools']);
    }

    public function testToolsListExposesAllTools(): void
    {
        $sessionId = $this->initializeSession();

        $response = $this->handle($this->postJson(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []],
            ['Mcp-Session-Id' => $sessionId]
        ));

        $body = json_decode((string) $response->getBody(), true);
        $names = array_column($body['result']['tools'], 'name');

        $this->assertGreaterThanOrEqual(20, count($names));
        foreach (['dolibarr_list', 'dolibarr_get', 'dolibarr_create', 'dolibarr_api_explorer'] as $expected) {
            $this->assertContains($expected, $names);
        }

        // Every tool must have a JSON schema with typed properties
        foreach ($body['result']['tools'] as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
        }
    }

    public function testUnknownSessionIsRejected(): void
    {
        $response = $this->handle($this->postJson(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []],
            ['Mcp-Session-Id' => '00000000-0000-4000-8000-000000000000']
        ));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMalformedSessionIdThrows(): void
    {
        // Documented SDK behavior: a non-UUID session id raises instead of
        // returning 4xx. Callers (dolimcp/mcp.php, public/index.php flows)
        // must therefore wrap handleHttpRequest() in a try/catch.
        $this->expectException(\InvalidArgumentException::class);

        $this->handle($this->postJson(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []],
            ['Mcp-Session-Id' => 'does-not-exist']
        ));
    }

    public function testGetRequestIsRejected(): void
    {
        $request = new ServerRequest('GET', 'https://example.org/mcp.php', [
            'Accept' => 'text/event-stream',
        ]);

        $response = $this->handle($request);

        // Per-request model: no long-lived SSE stream to subscribe to
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testResourcesListExposesGuides(): void
    {
        $sessionId = $this->initializeSession();

        $response = $this->handle($this->postJson(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => (object) []],
            ['Mcp-Session-Id' => $sessionId]
        ));

        $body = json_decode((string) $response->getBody(), true);
        $uris = array_column($body['result']['resources'] ?? [], 'uri');

        foreach (['essentials', 'tools', 'domains', 'workflows', 'fields-and-quirks'] as $guide) {
            $this->assertContains('dolibarr://guide/' . $guide, $uris);
        }
    }

    public function testResourceReadReturnsGuideContent(): void
    {
        $sessionId = $this->initializeSession();

        $response = $this->handle($this->postJson(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'resources/read',
                'params' => ['uri' => 'dolibarr://guide/essentials'],
            ],
            ['Mcp-Session-Id' => $sessionId]
        ));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('error', $body);

        $text = $body['result']['contents'][0]['text'];
        $this->assertStringContainsString('## Overview', $text);
        $this->assertStringContainsString('Update Behavior', $text);
        $this->assertStringContainsString('rowid', $text);
    }

    public function testToolCallErrorIsSurfacedToTheLlm(): void
    {
        $sessionId = $this->initializeSession();

        // dolibarr.invalid is unreachable: the DolibarrClient throws, and the
        // LlmFriendlyReferenceHandler must convert that into an isError tool
        // result carrying the message (not an opaque JSON-RPC error).
        $response = $this->handle($this->postJson(
            [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'dolibarr_list',
                    'arguments' => ['resource' => 'thirdparties', 'limit' => 1],
                ],
            ],
            ['Mcp-Session-Id' => $sessionId]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayNotHasKey('error', $body, 'tool failures must be tool results, not JSON-RPC errors');
        $this->assertTrue($body['result']['isError']);
        $this->assertNotEmpty($body['result']['content'][0]['text']);
        $this->assertStringNotContainsString('Error while executing tool', $body['result']['content'][0]['text']);
    }
}
