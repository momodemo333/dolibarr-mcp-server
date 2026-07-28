<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

/**
 * What a read-only query is allowed to touch, and how big it may get.
 *
 * Immutable and free of parsing logic, so the rules can be reviewed — and
 * displayed in the admin UI — without reading the validator.
 *
 * Denylisted table names are stored without the database prefix and compared
 * against the configured one, so an install using a prefix other than "llx_"
 * keeps the same protections.
 */
class SqlPolicy
{
    /** Tables holding credentials, tokens, sessions or the audit trail itself. */
    private const DENIED_TABLE_SUFFIXES = [
        'const',
        'session',
        'oauth_token',
        'oauth_state',
        'emmcp_oauth_token',
        'emmcp_oauth_client',
        'emmcp_sql_audit',
        'emmcp_sql_permissions',
        'dalfred_activity_log',
        'dalfred_toolkit_permissions',
        'dalfred_oauth_token',
        'dalfred_oauth_client',
    ];

    private const DENIED_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
    ];

    /**
     * Refused wherever they appear, whatever the table.
     *
     * Matching on the terminal column name rather than resolving alias to table
     * is deliberately stricter: it costs rare false positives, and avoids
     * hanging a security decision on fragile alias resolution.
     */
    private const DENIED_COLUMNS = [
        'pass',
        'pass_crypted',
        'pass_temp',
        'pass_indatabase',
        'pass_indatabase_crypted',
        'api_key',
        'token',
        'token_hash',
        'client_secret',
        'client_secret_hash',
        'code_challenge',
        'refresh_token',
        'secret',
        'private_key',
        'signature_key',
    ];

    /**
     * Fragments refused anywhere in a normalised column name.
     *
     * The exact list above only covers the spellings we thought of, which is
     * worth little against third-party modules: `smtp_password`, `apikey`,
     * `access_token` and `webhook_secret` all sailed through it. Matching on
     * fragments of a name stripped of separators catches the family instead of
     * the instance.
     *
     * These are deliberately fail-closed. `token_count` and `secretary_name`
     * are refused although they are innocent — narrowing the fragments enough
     * to let them through would also let real secrets past, and an unreadable
     * column costs a reformulated query while a leaked one cannot be undone.
     */
    private const DENIED_COLUMN_FRAGMENTS = [
        'password',
        'passwd',
        'passphrase',
        'motdepasse',
        'passcrypted',
        'passindatabase',
        'passtemp',
        'apikey',
        'token',
        'secret',
        'credential',
        'privatekey',
        'signaturekey',
        'signingkey',
    ];

    private const DENIED_FUNCTIONS = [
        'sleep',
        'benchmark',
        'get_lock',
        'release_lock',
        'is_free_lock',
        'is_used_lock',
        'master_pos_wait',
        'source_pos_wait',
        'load_file',
        'sys_exec',
        'sys_eval',
    ];

    private const HARD_MAX_ROWS = 5000;
    private const HARD_MAX_TIMEOUT = 30;

    private int $maxRows;
    private int $timeoutSeconds;
    private int $maxBytes;
    private int $maxSqlLength;

    /**
     * @param array{maxRows?: int, timeoutSeconds?: int, maxBytes?: int, maxSqlLength?: int} $overrides
     */
    public function __construct(private string $tablePrefix = 'llx_', array $overrides = [])
    {
        $this->maxRows = $this->clamp((int) ($overrides['maxRows'] ?? 200), 1, self::HARD_MAX_ROWS);
        $this->timeoutSeconds = $this->clamp((int) ($overrides['timeoutSeconds'] ?? 5), 1, self::HARD_MAX_TIMEOUT);
        $this->maxBytes = max(1024, (int) ($overrides['maxBytes'] ?? 262144));
        $this->maxSqlLength = max(64, (int) ($overrides['maxSqlLength'] ?? 8000));
    }

    public function isTableAllowed(string $table): bool
    {
        $name = $this->normalize($table);

        if (in_array($name, self::DENIED_DATABASES, true)) {
            return false;
        }
        if (!str_starts_with($name, $this->tablePrefix)) {
            return false;
        }
        foreach (self::DENIED_TABLE_SUFFIXES as $suffix) {
            if ($name === $this->tablePrefix . $suffix) {
                return false;
            }
        }

        return true;
    }

    public function isColumnAllowed(string $column): bool
    {
        $name = $this->normalize($column);

        if (in_array($name, self::DENIED_COLUMNS, true)) {
            return false;
        }

        // Separators removed so api_key, api-key and apikey are one name.
        $collapsed = str_replace(['_', '-', ' '], '', $name);
        foreach (self::DENIED_COLUMN_FRAGMENTS as $fragment) {
            if (str_contains($collapsed, $fragment)) {
                return false;
            }
        }

        return true;
    }

    public function isFunctionAllowed(string $function): bool
    {
        return !in_array($this->normalize($function), self::DENIED_FUNCTIONS, true);
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function maxRows(): int
    {
        return $this->maxRows;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxSqlLength(): int
    {
        return $this->maxSqlLength;
    }

    /**
     * Fully-qualified denied table names, for display in the admin page.
     *
     * @return array<int, string>
     */
    public function deniedTables(): array
    {
        return array_map(fn(string $s): string => $this->tablePrefix . $s, self::DENIED_TABLE_SUFFIXES);
    }

    /** @return array<int, string> */
    public function deniedColumns(): array
    {
        return self::DENIED_COLUMNS;
    }

    /**
     * Fragments refused anywhere in a column name, for display in the admin
     * page so an administrator can see why a column is unreadable.
     *
     * @return array<int, string>
     */
    public function deniedColumnFragments(): array
    {
        return self::DENIED_COLUMN_FRAGMENTS;
    }

    /** @return array<int, string> */
    public function deniedFunctions(): array
    {
        return self::DENIED_FUNCTIONS;
    }

    private function normalize(string $identifier): string
    {
        return strtolower(trim($identifier, " \t\n\r`\"'"));
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
