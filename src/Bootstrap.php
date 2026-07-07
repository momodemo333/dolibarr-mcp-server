<?php

declare(strict_types=1);

namespace DolibarrMcp;

use DolibarrMcp\Client\ApiSchemaClient;
use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Config\ConnectionConfig;
use DolibarrMcp\Resources\GuideResources;
use DolibarrMcp\Support\FieldMapper;
use DolibarrMcp\Support\LlmFriendlyReferenceHandler;
use DolibarrMcp\Tools\ActionTools;
use DolibarrMcp\Tools\ContactTools;
use DolibarrMcp\Tools\CrudTools;
use DolibarrMcp\Tools\FileGenerationTools;
use DolibarrMcp\Tools\DocumentTools;
use DolibarrMcp\Tools\ExplorerTools;
use DolibarrMcp\Tools\ExtrafieldTools;
use DolibarrMcp\Tools\LineTools;
use DolibarrMcp\Tools\ProjectTools;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
     *
     * @param ConnectionConfig|null $config Explicit connection settings (recommended,
     *                                      request-scoped). When null, falls back to
     *                                      the DOLIBARR_URL / DOLIBARR_API_KEY
     *                                      environment variables at first use.
     */
    public static function createContainer(?ConnectionConfig $config = null): Container
    {
        $container = new Container();

        // Connection settings (lazy: env is only read if no explicit config)
        $container->set(ConnectionConfig::class, fn() => $config ?? ConnectionConfig::fromEnvironment());

        // Clients
        $container->set(DolibarrClient::class, function (Container $c) {
            $cfg = $c->get(ConnectionConfig::class);
            return new DolibarrClient($cfg->baseUrl, $cfg->apiKey, $cfg->timeout);
        });
        $container->set(ApiSchemaClient::class, function (Container $c) {
            $cfg = $c->get(ConnectionConfig::class);
            return new ApiSchemaClient($cfg->baseUrl, $cfg->apiKey);
        });

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
            $c->get(FieldMapper::class),
        ));
        $container->set(ExtrafieldTools::class, fn(Container $c) => new ExtrafieldTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(ContactTools::class, fn(Container $c) => new ContactTools(
            $c->get(DolibarrClient::class),
            $c->get(FieldMapper::class),
        ));
        $container->set(FileGenerationTools::class, fn(Container $c) => new FileGenerationTools(
            $c->get(DolibarrClient::class),
        ));
        $container->set(ProjectTools::class, fn(Container $c) => new ProjectTools(
            $c->get(DolibarrClient::class),
        ));

        // Resources (usage guides served to MCP clients)
        $container->set(GuideResources::class, fn() => new GuideResources());

        return $container;
    }

    /**
     * Build the MCP server with attribute-based tool discovery.
     *
     * @param string|null $sessionDir When set, sessions are persisted on disk so they
     *                                survive across PHP-FPM requests (required for the
     *                                Streamable HTTP transport). When null, the SDK's
     *                                in-memory store is used (fine for stdio).
     */
    public static function buildServer(
        ?Container $container = null,
        ?string $sessionDir = null,
        ?ConnectionConfig $config = null,
    ): Server {
        $container ??= self::createContainer($config);

        $builder = Server::builder()
            ->setServerInfo('Dolibarr MCP Server', '2.0.0')
            ->setContainer($container)
            ->setReferenceHandler(new LlmFriendlyReferenceHandler(new ReferenceHandler($container)))
            ->setDiscovery(dirname(__DIR__), ['src']);

        if ($sessionDir !== null) {
            $builder->setSession(new FileSessionStore($sessionDir));
        }

        return $builder->build();
    }

    /**
     * Bootstrap and run the MCP server on stdio (blocking, for CLI usage).
     */
    public static function run(string $transport = 'stdio'): void
    {
        self::loadEnv();

        if ($transport === 'http') {
            // Legacy entry point: handle a single HTTP request and emit the response.
            self::emit(self::handleHttpRequest());
            return;
        }

        $server = self::buildServer();
        $server->run(new StdioTransport());
    }

    /**
     * Handle one Streamable HTTP request (per-request model, PHP-FPM friendly).
     *
     * No daemon, no event loop: the caller (public/index.php, or a Dolibarr
     * module endpoint) invokes this once per incoming HTTP request and emits
     * the returned PSR-7 response.
     *
     * Note: the SDK's DnsRebindingProtectionMiddleware is intentionally not
     * installed — its default allowlist is localhost-only, which would reject
     * any real deployment. Host validation is the web server's job here.
     *
     * @param ServerRequestInterface|null $request    Defaults to the current request (superglobals).
     * @param string|null                 $sessionDir Where to persist MCP sessions between requests.
     */
    public static function handleHttpRequest(
        ?ServerRequestInterface $request = null,
        ?string $sessionDir = null,
        ?ConnectionConfig $config = null,
    ): ResponseInterface {
        $request ??= ServerRequest::fromGlobals();
        $sessionDir ??= sys_get_temp_dir() . '/dolibarr-mcp-sessions';

        $server = self::buildServer(null, $sessionDir, $config);

        $httpFactory = new HttpFactory();
        $transport = new StreamableHttpTransport(
            $request,
            $httpFactory,
            $httpFactory,
            null,
            [new CorsMiddleware(), new ProtocolVersionMiddleware()],
        );

        return $server->run($transport);
    }

    /**
     * Send a PSR-7 response to the SAPI output (status line, headers, body).
     */
    public static function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header($name . ': ' . $value, $i === 0);
                }
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }
    }
}
