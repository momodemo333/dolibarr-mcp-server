<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Config\EnvironmentInfo;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Tells the caller what it is actually connected to.
 *
 * Worth its own tool because an agent otherwise guesses: it tries an endpoint
 * that does not exist because the module is disabled, or assumes behaviour that
 * changed between Dolibarr versions. One cheap call up front avoids a series of
 * failed ones — and it answers the human question too: "which version is this
 * install running, and is it up to date?"
 */
class EnvironmentTools
{
    public function __construct(private ?EnvironmentInfo $environment = null)
    {
    }

    #[McpTool(
        name: 'dolibarr_environment',
        description: 'Report the Dolibarr installation this server is connected to: Dolibarr and PHP versions, '
            . 'the version of the MCP module serving this session, the list of enabled Dolibarr modules, and which '
            . 'optional capabilities (such as read-only SQL) are available to you. '
            . 'Call this once at the start of a session when the answer depends on the Dolibarr version or on which '
            . 'modules are installed, rather than discovering it through failed calls.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function describeEnvironment(): string
    {
        $info = $this->environment ?? new EnvironmentInfo();

        return (string) json_encode(
            ['success' => true] + $info->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
