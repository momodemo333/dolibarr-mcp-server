<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Integration;

use DolibarrMcp\Bootstrap;
use DolibarrMcp\Config\ConnectionConfig;
use DolibarrMcp\Container;
use DolibarrMcp\Extension\ExtensionRegistrar;
use DolibarrMcp\Extension\ToolExtension;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

final class FakeTodoProvider
{
    public array $calls = [];

    #[McpTool(name: 'fake_todo_get', description: 'Read a fake todo.')]
    public function get(
        #[Schema(description: 'Todo id')]
        int $id,
        ?string $ref = null
    ): string {
        $this->calls[] = [$id, $ref];

        return json_encode(['success' => true, 'id' => $id, 'ref' => $ref]);
    }

    #[McpTool(name: 'dolibarr_list', description: 'Tries to shadow a built-in tool.')]
    public function shadow(): string
    {
        return 'shadowed';
    }

    #[McpTool(name: 'Bad Name', description: 'Invalid name.')]
    public function badName(): string
    {
        return 'x';
    }

    public function notATool(): string
    {
        return 'never exposed';
    }
}

final class FakeExtension implements ToolExtension
{
    public function __construct(private array $providers)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function toolProviders(): iterable
    {
        return $this->providers;
    }
}

final class ToolExtensionTest extends TestCase
{
    private string $sessionDir;

    protected function setUp(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/dolibarr-mcp-ext-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sessionDir)) {
            array_map('unlink', glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
    }

    private function rpc(array $extensions, string $method, array $params, ?string $session = null): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }
        $request = new ServerRequest('POST', 'https://example.org/mcp.php', $headers, json_encode([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params,
        ]));
        $response = Bootstrap::handleHttpRequest(
            $request,
            $this->sessionDir,
            new ConnectionConfig('https://dolibarr.invalid', 'test-key'),
            null,
            null,
            $extensions,
        );

        return [json_decode((string) $response->getBody(), true), $response->getHeaderLine('Mcp-Session-Id')];
    }

    private function session(array $extensions): string
    {
        [, $session] = $this->rpc($extensions, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ]);
        $this->assertNotSame('', $session);

        return $session;
    }

    public function testExtensionToolIsListedWithItsGeneratedSchema(): void
    {
        $extensions = [new FakeExtension([new FakeTodoProvider()])];
        $session = $this->session($extensions);

        [$body] = $this->rpc($extensions, 'tools/list', [], $session);
        $tools = array_column($body['result']['tools'], null, 'name');

        $this->assertArrayHasKey('fake_todo_get', $tools);
        $this->assertSame(['id'], $tools['fake_todo_get']['inputSchema']['required']);
        $this->assertSame('Todo id', $tools['fake_todo_get']['inputSchema']['properties']['id']['description']);
        $this->assertArrayHasKey('dolibarr_add_time_spent', $tools, 'built-in tools are still there');
    }

    public function testExtensionToolIsCalledWithNamedArguments(): void
    {
        $provider = new FakeTodoProvider();
        $extensions = [new FakeExtension([$provider])];
        $session = $this->session($extensions);

        [$body] = $this->rpc($extensions, 'tools/call', ['name' => 'fake_todo_get', 'arguments' => ['id' => 7, 'ref' => 'TODO-7']], $session);

        $payload = json_decode($body['result']['content'][0]['text'], true);
        $this->assertSame(7, $payload['id']);
        $this->assertSame('TODO-7', $payload['ref']);
        $this->assertSame([[7, 'TODO-7']], $provider->calls);
    }

    public function testBuiltInToolCannotBeShadowedAndBadNamesAreSkipped(): void
    {
        $registrar = new ExtensionRegistrar();
        $registrar->register(Server::builder(), new Container(), [new FakeExtension([new FakeTodoProvider()]), new \stdClass()]);

        $this->assertSame(['fake_todo_get' => 'fake'], $registrar->registered());
        $rejected = implode("\n", $registrar->rejected());
        $this->assertStringContainsString('"dolibarr_list"', $rejected);
        $this->assertStringContainsString('reserved prefix', $rejected);
        $this->assertStringContainsString('"Bad Name"', $rejected);
        $this->assertStringContainsString('stdClass does not implement', $rejected);
        $this->assertStringNotContainsString('notATool', $rejected);
    }

    public function testDuplicateToolAcrossExtensionsKeepsTheFirst(): void
    {
        $registrar = new ExtensionRegistrar();
        $registrar->register(Server::builder(), new Container(), [
            new FakeExtension([new FakeTodoProvider()]),
            new FakeExtension([new FakeTodoProvider()]),
        ]);

        $this->assertCount(1, $registrar->registered());
        $this->assertStringContainsString('already registered', implode("\n", $registrar->rejected()));
    }

    public function testNoExtensionChangesNothing(): void
    {
        $session = $this->session([]);
        [$body] = $this->rpc([], 'tools/list', [], $session);
        $names = array_column($body['result']['tools'], 'name');

        $this->assertNotContains('fake_todo_get', $names);
        $this->assertContains('dolibarr_list', $names);
    }
}
