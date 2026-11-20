<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Client;

use DolibarrMcp\Bootstrap;
use DolibarrMcp\Client\ApiSchemaClient;
use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Config\ConnectionConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Multicompany: the entity the host chose travels as the native DOLAPIENTITY
 * header, and nothing at all is sent when no entity is configured (behaviour
 * of every version before 2.8.0).
 */
class EntityHeaderTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function stack(Response ...$responses): HandlerStack
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    public function testRestCallsCarryTheEntityHeaderWhenAnEntityIsSet(): void
    {
        $client = new DolibarrClient('https://example.com', 'k', 30, $this->stack(new Response(200, [], '[]')), 3);
        $client->get('thirdparties');

        $request = $this->history[0]['request'];
        $this->assertSame('3', $request->getHeaderLine('DOLAPIENTITY'));
        $this->assertSame('k', $request->getHeaderLine('DOLAPIKEY'));
    }

    public function testNoEntityHeaderWhenEntityIsNull(): void
    {
        $client = new DolibarrClient('https://example.com', 'k', 30, $this->stack(new Response(200, [], '[]')));
        $client->post('thirdparties', ['name' => 'x']);

        $this->assertFalse($this->history[0]['request']->hasHeader('DOLAPIENTITY'));
    }

    public function testSchemaClientCarriesTheEntityHeaderOnlyWhenSet(): void
    {
        $with = new ApiSchemaClient('https://example.com', 'k', 7, $this->stack(new Response(200, [], '{"paths":{}}')));
        $with->getSchema();
        $this->assertSame('7', $this->history[0]['request']->getHeaderLine('DOLAPIENTITY'));

        $without = new ApiSchemaClient('https://example.com', 'k', null, $this->stack(new Response(200, [], '{"paths":{}}')));
        $without->getSchema();
        $this->assertFalse($this->history[0]['request']->hasHeader('DOLAPIENTITY'));
    }

    public function testContainerPassesTheConfiguredEntityToTheClients(): void
    {
        $container = Bootstrap::createContainer(new ConnectionConfig('https://example.com', 'k', 30, 5));
        $client = $container->get(DolibarrClient::class);

        $property = new \ReflectionProperty(DolibarrClient::class, 'httpClient');
        $http = $property->getValue($client);
        $headers = $http->getConfig('headers');
        $this->assertSame('5', $headers['DOLAPIENTITY'] ?? null);

        $legacy = Bootstrap::createContainer(new ConnectionConfig('https://example.com', 'k'));
        $headers = $property->getValue($legacy->get(DolibarrClient::class))->getConfig('headers');
        $this->assertArrayNotHasKey('DOLAPIENTITY', $headers);
    }

    public function testEntityMustBePositive(): void
    {
        $this->expectException(RuntimeException::class);
        new ConnectionConfig('https://example.com', 'k', 30, 0);
    }
}
