<?php

declare(strict_types=1);

namespace DolibarrMcp\Extension;

/**
 * Tools added to the server by something other than this package.
 *
 * The built-in tools are discovered from this package's own src/ directory. A
 * host (emMCP, Dalfred) can also hand Bootstrap extensions provided by other
 * Dolibarr modules — for example tools for a third-party module this package
 * knows nothing about. Each provider is a plain object whose public methods
 * carry #[McpTool] and #[Schema], exactly like the built-in tool classes; the
 * input schema is generated from the method signature the same way.
 *
 * Extension tool names must not start with "dolibarr_": that prefix is reserved
 * for the built-in tools, which therefore can never be shadowed.
 */
interface ToolExtension
{
    /**
     * Short identifier of the extension, used in diagnostics (e.g. "emmcpext").
     */
    public function name(): string;

    /**
     * Objects whose #[McpTool] methods become MCP tools.
     *
     * @return iterable<object>
     */
    public function toolProviders(): iterable;
}
