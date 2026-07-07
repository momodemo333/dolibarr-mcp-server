# Changelog

## 2.1.0 (unreleased)

- **Migrated from `php-mcp/server` to the official MCP PHP SDK (`mcp/sdk`)** — ReactPHP is gone entirely.
- The Streamable HTTP transport is now **per-request** (PSR-7, PHP-FPM/Apache friendly): no daemon, no event loop, no port to manage. `Bootstrap::handleHttpRequest()` handles one HTTP request and returns a PSR-7 response; `Bootstrap::emit()` sends it to the SAPI output.
- HTTP sessions are persisted on disk between requests (`FileSessionStore`), so the `Mcp-Session-Id` handshake works under PHP-FPM.
- Tool exceptions now surface their message to the LLM as `isError` tool results (`LlmFriendlyReferenceHandler`) instead of an opaque "Error while executing tool" JSON-RPC error.
- Tool attributes now come from `Mcp\Capability\Attribute` (`McpTool`, `Schema`); signatures are unchanged.
- stdio entry point (`bin/server.php`) unchanged for MCP clients.
- **Request-scoped connection config** (`ConnectionConfig`): callers pass the Dolibarr URL + API key explicitly instead of mutating process-global environment variables (unsafe under PHP-FPM worker reuse). Env vars remain a CLI/stdio fallback.
- **Usage knowledge served as MCP resources**: LLM.md is sliced at read time into five guides (`dolibarr://guide/essentials`, `tools`, `domains`, `workflows`, `fields-and-quirks`) so every MCP client gets the same guidance Dalfred injects in its system prompt. LLM.md stays the single source of truth.
- **HTTP integration test suite** (`tests/Integration/`): full JSON-RPC flows (initialize/session persistence/tools/resources/error surfacing) exercised in PHPUnit with in-memory PSR-7 requests — no web server needed.
- **`id`/`rowid` list filters are normalized to `t.rowid` sqlfilters** in `dolibarr_list`: many Dolibarr list endpoints silently ignore raw `id` query params, so `{"id": 123}` used to return the full unfiltered list. The conversion merges with any user-supplied `sqlfilters`, is documented for agents in LLM.md, and is covered by regression tests in `tests/Tools/CrudToolsTest.php`.

This public repository starts from the Dolibarr MCP Server 2.x codebase.

Earlier private development history is intentionally not imported because it contained internal development notes and environment-specific information that are not suitable for a public repository.

## 2.x

- Generic CRUD tools for Dolibarr REST resources.
- Dynamic API exploration from Dolibarr Swagger/OpenAPI metadata.
- Commercial workflow actions: validate, close, reopen, create from source documents.
- Document tools: list, upload, download, build PDF, delete.
- Contacts, extrafields, projects, and time-spent helpers.
- Stdio and HTTP entry points for MCP clients.
- Field-name guidance and structured error responses for LLM/tool usage.

Future public releases will use this changelog normally.
