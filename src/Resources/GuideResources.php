<?php

declare(strict_types=1);

namespace DolibarrMcp\Resources;

use Mcp\Capability\Attribute\McpResource;

/**
 * Serves the Dolibarr usage knowledge (LLM.md) as MCP resources.
 *
 * LLM.md is the single source of truth: this class slices it by section at
 * read time, so there is nothing to keep in sync. Exposing the knowledge as
 * MCP resources means every client (Claude Code, claude.ai, API agents…)
 * gets the same guidance that Dalfred injects in its system prompt —
 * without it, third-party clients rediscover every API quirk the hard way.
 */
class GuideResources
{
    /** Level-2 section titles of LLM.md making up each guide. */
    private const GUIDES = [
        'essentials' => [
            'Overview',
            '⚠️ MCP Failure Discipline',
            '⚠️ CRITICAL: Update Behavior — Read Before You Write',
            '⚠️ Resolving a Reference (ref) to a rowid',
            'Error Handling',
            'Best Practices',
        ],
        'tools' => [
            'Tools Reference',
        ],
        'domains' => [
            'Extrafields Management',
            'Common Modules',
            'Bank Accounts Module',
            'Payments — Recording Payments on Invoices',
            'Thirdparties — Advanced Sub-Endpoints',
            'Setup & Dictionaries — Reference Data Lookup',
            'Products — Advanced Sub-Endpoints',
            'Invoices & Orders — Advanced Sub-Endpoints',
        ],
        'workflows' => [
            'Workflow Examples',
        ],
        'fields-and-quirks' => [
            'Field Reference',
            'API Quirks & Known Limitations',
            'Version Compatibility Notes',
        ],
    ];

    public function __construct(private readonly ?string $guidePath = null)
    {
    }

    #[McpResource(
        uri: 'dolibarr://guide/essentials',
        name: 'dolibarr-guide-essentials',
        title: 'Dolibarr MCP — essential rules',
        description: 'READ THIS FIRST. Critical rules for using the Dolibarr tools safely: update semantics (line updates need ALL fields), resolving a ref (e.g. "F2024-0042") to a rowid before get/update, failure discipline, error handling, best practices.',
        mimeType: 'text/markdown',
    )]
    public function essentials(): string
    {
        return $this->sections('essentials');
    }

    #[McpResource(
        uri: 'dolibarr://guide/tools',
        name: 'dolibarr-guide-tools',
        title: 'Dolibarr MCP — detailed tool reference',
        description: 'Detailed reference of every tool with examples: sqlfilters syntax, recommended fields per module, create/update payloads per resource type, document handling, lines, contacts, extrafields.',
        mimeType: 'text/markdown',
    )]
    public function tools(): string
    {
        return $this->sections('tools');
    }

    #[McpResource(
        uri: 'dolibarr://guide/domains',
        name: 'dolibarr-guide-domains',
        title: 'Dolibarr MCP — domain guides',
        description: 'Domain-specific guidance: bank accounts and transactions, recording payments on invoices, thirdparty outstanding amounts, dictionaries and reference data, product variants and prices, advanced invoice/order sub-endpoints, extrafields.',
        mimeType: 'text/markdown',
    )]
    public function domains(): string
    {
        return $this->sections('domains');
    }

    #[McpResource(
        uri: 'dolibarr://guide/workflows',
        name: 'dolibarr-guide-workflows',
        title: 'Dolibarr MCP — business workflows',
        description: 'End-to-end business workflows: proposal → order → invoice → payment chains, supplier flows, document generation. Use when orchestrating multi-step commercial operations.',
        mimeType: 'text/markdown',
    )]
    public function workflows(): string
    {
        return $this->sections('workflows');
    }

    #[McpResource(
        uri: 'dolibarr://guide/fields-and-quirks',
        name: 'dolibarr-guide-fields-and-quirks',
        title: 'Dolibarr MCP — field reference and API quirks',
        description: 'Field naming reference per resource (JSON vs SQL names, datec/tms/datef…), known Dolibarr REST API quirks and limitations, version compatibility notes (16.x → 21.x).',
        mimeType: 'text/markdown',
    )]
    public function fieldsAndQuirks(): string
    {
        return $this->sections('fields-and-quirks');
    }

    /**
     * Concatenate the requested level-2 sections of LLM.md, in guide order.
     */
    private function sections(string $guide): string
    {
        $titles = self::GUIDES[$guide];
        $all = $this->parseSections();

        $parts = [];
        foreach ($titles as $title) {
            if (isset($all[$title])) {
                $parts[] = '## ' . $title . "\n" . $all[$title];
            }
        }

        if ($parts === []) {
            return 'Guide content unavailable (LLM.md not found or section titles changed).';
        }

        return implode("\n\n", $parts);
    }

    /**
     * Split LLM.md into level-2 sections: title => body.
     *
     * Fenced code blocks are respected ("## " inside ``` fences is content,
     * not a heading).
     *
     * @return array<string, string>
     */
    private function parseSections(): array
    {
        $path = $this->guidePath ?? dirname(__DIR__, 2) . '/LLM.md';
        if (!is_file($path)) {
            return [];
        }

        $sections = [];
        $current = null;
        $buffer = [];
        $inFence = false;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^```/', $line)) {
                $inFence = !$inFence;
            }
            if (!$inFence && str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $sections[$current] = rtrim(implode("\n", $buffer)) . "\n";
                }
                $current = trim(substr($line, 3));
                $buffer = [];
                continue;
            }
            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        if ($current !== null) {
            $sections[$current] = rtrim(implode("\n", $buffer)) . "\n";
        }

        return $sections;
    }
}
