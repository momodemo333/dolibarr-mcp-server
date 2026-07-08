# Changelog

## 2.1.0 (unreleased)

- **`fields` filter no longer hides mistakes silently**: when none of the requested field names exist on the returned data, the tool now returns `{"warning": "unknown_fields", "requested_fields": [...], "available_fields": [...]}` instead of an empty `[[]]`. This was misleading agents into thinking a working endpoint (e.g. `/tasks/{id}/timespent`, whose keys are prefixed `timespent_line_*`) was broken. Covered by regression tests in `tests/Tools/CrudToolsTest.php`.
- **Documented required fields and field names that agents kept guessing wrong**: `dolibarr_create` now states that tasks need `ref` + `fk_project` and proposals need `socid` + `date` (a dateless proposal fails with a misleading "Error creating order" 500); LLM.md documents the real `/tasks/{id}/timespent` field names and a compact `fields` recipe for project task listings.
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
- **Resource names are canonicalized on every tool** (`FieldMapper::normalizeResource()`): models routinely infer singular (`project`), capitalized (`Invoices`), underscore (`supplier_invoices`) or French (`facture`, `devis`, `tiers`) resource names from natural language, which used to reach non-existent endpoints and surface confusing HTML/CSRF errors (reported by a customer on "create a project"). The first path segment is now lowercased and, when the rewrite lands on a known core endpoint (Dolibarr 16-21 API modules list), pluralized/aliased; unknown names (custom-module endpoints) pass through untouched apart from lowercasing, so legitimately singular custom routes keep working. Applied uniformly in CrudTools (including `subresource`), LineTools, ActionTools and ContactTools — this also fixes `dolibarr_add_line` with a singular resource, which previously built a wrong `/line` endpoint AND silently skipped the supplier field remapping (`subprice` → `pu_ht`).
- **Endpoints can no longer escape `/api/index.php/`**: `DolibarrClient::request()` strips leading slashes before resolution. Per RFC 3986, a leading-slash relative URL replaces the whole base path, which sent the request to the Dolibarr web front controller and produced the HTML/CSRF page instead of a REST response — the root mechanism of the customer-reported error, now closed for every tool.
- **API errors are short and actionable**: non-JSON error bodies (HTML login/CSRF pages, including those served with HTTP 200) are detected and replaced by a message telling the model the endpoint probably does not exist and pointing to `dolibarr_api_explorer`; JSON errors are extracted properly (no more PHP `Array` interpolation) and every raw body is truncated instead of flooding the tool result with kilobytes of markup.
- **`ConnectionConfig` fails fast on empty URL or API key** instead of letting a blank `DOLAPIKEY` header produce opaque 401s downstream.
- LLM.md gained a "Resource Names" section (canonical plural list, auto-correction behavior, what to do on "HTML page" errors).

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
