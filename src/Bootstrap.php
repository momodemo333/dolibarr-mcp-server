<?php

declare(strict_types=1);

namespace DolibarrMcp;

use DolibarrMcp\Client\ApiSchemaClient;
use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use DolibarrMcp\Tools\ActionTools;
use DolibarrMcp\Tools\ContactTools;
use DolibarrMcp\Tools\CrudTools;
use DolibarrMcp\Tools\FileGenerationTools;
use DolibarrMcp\Tools\DocumentTools;
use DolibarrMcp\Tools\ExplorerTools;
use DolibarrMcp\Tools\ExtrafieldTools;
use DolibarrMcp\Tools\LineTools;
use DolibarrMcp\Tools\ProjectTools;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;

class Bootstrap
{
    /**
     * Load environment variables from .env file.
     * Existing env vars (e.g. passed by parent process) take priority.
     */
    public static function loadEnv(): void
    {
        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key] = explode('=', trim($line), 2);
                if (getenv($key) === false) {
                    putenv(trim($line));
                }
            }
        }
    }

    /**
     * Build the DI container with all services registered.
     */
    public static function createContainer(): Container
    {
        $container = new Container();

        // Clients
        $container->set(DolibarrClient::class, fn() => DolibarrClient::fromEnvironment());
        $container->set(ApiSchemaClient::class, fn() => ApiSchemaClient::fromEnvironment());

        // Support
        $container->set(FieldMapper::class, fn() => new FieldMapper());

        // Tools
        $container->set(ExplorerTools::class, fn(Container $c) => new ExplorerTools(
            $c->get(ApiSchemaClient::class),
        ));
        $container->set(CrudTools::class, fn(Container $c) => new CrudTools(
            $c->get(DolibarrClient::class),
            $c->get(FieldMapper::class),
        ));
        $container->set(DocumentTools::class, fn(Container $c) => new DocumentTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(LineTools::class, fn(Container $c) => new LineTools(
            $c->get(DolibarrClient::class),
            $c->get(FieldMapper::class),
        ));
        $container->set(ActionTools::class, fn(Container $c) => new ActionTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(ExtrafieldTools::class, fn(Container $c) => new ExtrafieldTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(ContactTools::class, fn(Container $c) => new ContactTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(FileGenerationTools::class, fn(Container $c) => new FileGenerationTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(ProjectTools::class, fn(Container $c) => new ProjectTools(
            $c->get(DolibarrClient::class),
        ));

        return $container;
    }

    /**
     * Build the MCP server with tool discovery.
     */
    public static function buildServer(?Container $container = null): Server
    {
        $container ??= self::createContainer();

        $server = Server::make()
            ->withServerInfo('Dolibarr MCP Server', '2.0.0')
            ->withContainer($container)
            ->build();

        $server->discover(dirname(__DIR__), ['src']);

        return $server;
    }

    /**
     * Bootstrap and run the MCP server.
     *
     * @param string $transport 'stdio' or 'http'
     */
    public static function run(string $transport = 'stdio'): void
    {
        self::loadEnv();

        $server = self::buildServer();

        $transportInstance = match ($transport) {
            'http' => new StreamableHttpServerTransport(),
            default => new StdioServerTransport(),
        };

        $server->listen($transportInstance);
    }
}
