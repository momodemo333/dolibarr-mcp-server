<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools\Gated;

use DolibarrMcp\Sql\SqlCapabilityInterface;
use DolibarrMcp\Sql\SqlReadOnlyValidator;
use DolibarrMcp\Sql\SqlValidationException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Read-only SQL access for reporting.
 *
 * This class lives under Tools/Gated/ rather than Tools/ because the bootstrap
 * excludes that directory from attribute discovery when no host capability is
 * injected: the tools then do not appear in tools/list at all, instead of
 * existing and refusing every call.
 *
 * The tools stay thin on purpose. Validation belongs to SqlReadOnlyValidator,
 * and execution, authorisation and auditing belong to the host capability.
 */
class SqlTools
{
    public function __construct(private ?SqlCapabilityInterface $capability = null)
    {
    }

    #[McpTool(
        name: 'dolibarr_sql_query',
        description: 'Run a read-only SQL query against the Dolibarr database for reporting. '
            . 'Supports SELECT, WITH/CTE, JOIN, subqueries, aggregates and UNION. '
            . 'Writes, DDL, multiple statements and credential columns are refused. '
            . 'A row limit is always applied. Call dolibarr_sql_schema first to learn the real column names.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function queryDatabase(
        #[Schema(description: 'A single read-only SQL statement. Example: SELECT s.nom, SUM(f.total_ht) AS ca FROM llx_facture f JOIN llx_societe s ON s.rowid = f.fk_soc WHERE f.fk_statut = 1 GROUP BY s.nom ORDER BY ca DESC')]
        string $sql,
    ): string {
        if ($this->capability === null) {
            return $this->unavailable();
        }

        try {
            $capability = $this->capability;
            $validated = (new SqlReadOnlyValidator(
                $capability->getPolicy(),
                null,
                static fn (string $table): array => $capability->listColumns($table)
            ))->validate($sql);
        } catch (SqlValidationException $e) {
            // A refused query never reaches the database, so this is the only
            // chance to record the attempt. An audit failure must not mask the
            // refusal the caller actually needs to see.
            try {
                $this->capability->auditRefusal($sql, $e->code(), 'query');
            } catch (\Throwable $ignored) {
                // deliberately swallowed
            }

            return $this->failure($e->code(), $e->getMessage());
        }

        try {
            $result = $this->capability->runSelect($validated);
        } catch (SqlValidationException $e) {
            return $this->failure($e->code(), $e->getMessage());
        } catch (\Throwable $e) {
            // Driver messages carry host names and credentials; the detail goes
            // to the server log, never to the model.
            return $this->failure(
                'SQL_EXECUTION_ERROR',
                'The query could not be executed. Check the table and column names with dolibarr_sql_schema.'
            );
        }

        $payload = ['success' => true] + $result->toArray();
        if ($result->isTruncated()) {
            $payload['notice'] = 'Results were truncated by the server limit. Narrow the query or add aggregation.';
        }

        return $this->encode($payload);
    }

    #[McpTool(
        name: 'dolibarr_sql_schema',
        description: 'Explore the Dolibarr schema, to build correct SQL for dolibarr_sql_query. '
            . 'Called without a table, it returns the list of readable table NAMES only. '
            . 'Called with a table name, it returns that table\'s columns with their types; '
            . 'a name matching no table exactly is treated as a prefix. '
            . 'Note on dates: columns such as datef or date_lim_reglement are of type date, '
            . 'while datec and tms are datetime, both in server time. '
            . 'Tables holding credentials or sessions are not listed.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function describeDatabaseSchema(
        #[Schema(description: 'Table name, for example "llx_facture", to get its columns. A name matching no table exactly is used as a prefix ("llx_fact"). Omit to list every readable table name without columns.')]
        ?string $table = null,
    ): string {
        if ($this->capability === null) {
            return $this->unavailable();
        }

        $startedAt = microtime(true);
        $namesOnly = ($table === null || $table === '');

        try {
            $schema = $namesOnly
                ? ['tables' => $this->capability->listTables(), 'truncated' => false]
                : $this->capability->describeSchema($table);
        } catch (\Throwable $e) {
            try {
                $this->capability->auditRefusal((string) $table, 'SQL_EXECUTION_ERROR', 'schema');
            } catch (\Throwable $ignored) {
                // deliberately swallowed
            }

            return $this->failure(
                'SQL_EXECUTION_ERROR',
                'The schema could not be read.'
            );
        }

        // Introspection reveals which modules an instance runs, so an
        // unrecorded schema read is withheld rather than returned — the same
        // fail-closed rule that applies to a successful query. Swallowing the
        // failure here would have made the audit trail optional in practice
        // for anyone able to break it.
        try {
            $recorded = $this->capability->auditSchemaAccess(
                $table,
                count($schema['tables'] ?? []),
                (int) round((microtime(true) - $startedAt) * 1000)
            );
        } catch (\Throwable $e) {
            $recorded = false;
        }

        if (!$recorded) {
            return $this->failure(
                'SQL_AUDIT_FAILED',
                'The audit trail could not be written, so the schema is withheld. '
                    . 'Ask an administrator to check the Dolibarr log.'
            );
        }

        $tables = $schema['tables'] ?? [];

        // Without a filter, answer with table NAMES only.
        //
        // Returning every column of every table produced ~380 KB on an ordinary
        // instance, which the server then cut at 200 tables in alphabetical
        // order — so an unfiltered call silently stopped somewhere in the "e"s
        // and llx_facture was not in the answer at all. A caller had no way to
        // tell, and the notice suggesting a prefix arrived too late to help.
        //
        // Names alone are a few kilobytes for a whole instance, which is what
        // the question "what is in this database" actually needs; the columns
        // are one more call away, on the table that matters.
        if ($namesOnly) {
            $payload = [
                'success' => true,
                'tables' => $tables,
                'truncated' => (bool) ($schema['truncated'] ?? false),
                'notice' => 'Table names only. Call this tool again with a table name to get its columns.',
            ];
            if ($payload['truncated']) {
                $payload['notice'] = 'Too many tables to list at once; this list is incomplete. '
                    . 'Pass a table prefix to narrow it.';
            }

            return $this->encode($payload);
        }

        $payload = [
            'success' => true,
            'tables' => $tables,
            'truncated' => (bool) ($schema['truncated'] ?? false),
        ];
        if ($payload['truncated']) {
            $payload['notice'] = 'Too many tables matched this prefix to list at once. Use a longer prefix, '
                . 'or the exact table name.';
        }

        return $this->encode($payload);
    }

    private function unavailable(): string
    {
        return $this->failure(
            'SQL_CAPABILITY_UNAVAILABLE',
            'Read-only SQL access is not enabled on this Dolibarr instance, '
                . 'or the current user has not been granted it.'
        );
    }

    private function failure(string $code, string $message): string
    {
        return $this->encode([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
