<?php

declare(strict_types=1);

namespace DolibarrMcp\Config;

/**
 * The MCP server package version, in one place.
 *
 * It used to live only in the setServerInfo() call, which meant a tool wanting
 * to report it had to duplicate the literal — and the emMCP build checks it by
 * grepping that same line, so a second copy would have drifted silently.
 */
final class Version
{
    public const SERVER = '2.5.0';
}
