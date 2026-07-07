<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Client;

use DolibarrMcp\Client\DolibarrClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DolibarrClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function makeClient(Response ...$responses): DolibarrClient
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new DolibarrClient('https://example.com/erp', 'test-key', 30, $stack);
    }

    public function testRequestsAreResolvedUnderApiIndexPath(): void
    {
        $client = $this->makeClient(new Response(200, [], '[]'));
        $client->get('thirdparties');

        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertSame('https://example.com/erp/api/index.php/thirdparties', $uri);
    }

    public function testLeadingSlashEndpointCannotEscapeApiIndexPath(): void
    {
        // RFC 3986: a leading-slash relative URL would replace the whole
        // base path and hit the Dolibarr web front controller (CSRF page).
        $client = $this->makeClient(new Response(200, [], '[]'));
        $client->get('/thirdparties');

        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertSame('https://example.com/erp/api/index.php/thirdparties', $uri);
    }

    public function testHtmlErrorResponseYieldsActionableMessage(): void
    {
        $html = '<!DOCTYPE html><html><body>Error CSRF check failed</body></html>';
        $client = $this->makeClient(new Response(403, [], $html));

        try {
            $client->get('projet');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTML page instead of a REST API response', $e->getMessage());
            $this->assertStringContainsString('dolibarr_api_explorer', $e->getMessage());
            $this->assertStringNotContainsString('<html', $e->getMessage());
        }
    }

    public function testHtmlBodyWithSuccessStatusYieldsActionableMessage(): void
    {
        // A wrong URL can land on a web page that answers 200 with HTML.
        $html = '<html><head><title>Login</title></head><body>login</body></html>';
        $client = $this->makeClient(new Response(200, [], $html));

        try {
            $client->get('unknownthing');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTML page instead of a REST API response', $e->getMessage());
            $this->assertStringNotContainsString('<html', $e->getMessage());
        }
    }

    public function testJsonErrorMessageIsSurfaced(): void
    {
        $body = json_encode(['error' => ['code' => 404, 'message' => 'Thirdparty not found']]);
        $client = $this->makeClient(new Response(404, [], $body));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dolibarr API error (404): Thirdparty not found');
        $client->get('thirdparties/999999');
    }

    public function testArrayErrorWithoutMessageDoesNotPrintArray(): void
    {
        $body = json_encode(['error' => ['code' => 500, 'details' => ['a' => 1]]]);
        $client = $this->makeClient(new Response(500, [], $body));

        try {
            $client->get('thirdparties');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(': Array', $e->getMessage());
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    public function testLongNonJsonErrorBodyIsTruncated(): void
    {
        $client = $this->makeClient(new Response(500, [], str_repeat('x', 5000)));

        try {
            $client->get('thirdparties');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertLessThan(500, strlen($e->getMessage()));
        }
    }

    public function testNumericBodyStillReturnsInt(): void
    {
        $client = $this->makeClient(new Response(200, [], '42'));
        $this->assertSame(42, $client->post('thirdparties', ['name' => 'X']));
    }
}
