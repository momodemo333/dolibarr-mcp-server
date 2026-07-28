<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

/**
 * The database access a host grants to the SQL tools.
 *
 * This package has no database connection of its own and no notion of a
 * Dolibarr user, by design: every other tool reaches Dolibarr over the REST
 * API and therefore inherits the caller's permissions for free. SQL cannot,
 * so the host must build this object *after* deciding the caller is allowed,
 * and inject it.
 *
 * A host that does not inject one — the stdio entry point, the standalone HTTP
 * entry point — leaves the tools undiscoverable. Deny-by-default is therefore
 * structural rather than a runtime check that could be forgotten.
 *
 * Implementations are responsible for enforcement the validator cannot do:
 * running on a dedicated read-only transaction, applying a statement timeout,
 * capping the response size, and writing the audit trail.
 */
interface SqlCapabilityInterface
{
    /**
     * Describe tables and columns the caller may read.
     *
     * @param string|null $tableFilter optional table name or prefix to narrow the result
     *
     * @return array{tables: array<string, array<int, array{name: string, type: string, nullable: bool, key: string}>>, truncated: bool}
     */
    public function describeSchema(?string $tableFilter = null): array;

    /**
     * Execute an already-validated read-only query.
     *
     * The SQL passed here has been through SqlReadOnlyValidator and carries a
     * row limit. Implementations must still defend at the engine level rather
     * than trusting that guarantee.
     */
    public function runSelect(string $validatedSql): SqlExecutionResult;

    /**
     * The limits and denylists in force, so the tool validates against the same
     * rules the host will apply.
     */
    public function getPolicy(): SqlPolicy;

    /**
     * Record an attempt the tool refused before any database access.
     *
     * Rejected queries never reach runSelect(), so without this the audit trail
     * would only ever show what succeeded — exactly the opposite of what an
     * administrator investigating an incident needs. Implementations must store
     * metadata only: the query text is already covered by the host's own
     * truncation and hash-only settings, and results do not exist yet.
     *
     * Auditing a refusal must not itself raise: the caller is already being
     * refused, and turning a logging failure into a second error would only
     * obscure the reason.
     *
     * @param string $sql       Statement as submitted
     * @param string $errorCode Stable SCREAMING_SNAKE reason
     * @param string $operation 'query' or 'schema'
     */
    public function auditRefusal(string $sql, string $errorCode, string $operation = 'query'): void;

    /**
     * Record a schema introspection.
     *
     * Reading the schema is not neutral — it reveals which modules an instance
     * runs — so it belongs in the trail alongside queries.
     *
     * Unlike auditRefusal(), the caller acts on the answer: a schema that could
     * not be recorded is not returned. Implementations must therefore report
     * failure honestly rather than swallowing it.
     *
     * @param string|null $tableFilter Filter as submitted
     * @param int         $tableCount  Number of tables described
     * @param int         $durationMs  Wall-clock duration
     *
     * @return bool true when the entry was written
     */
    public function auditSchemaAccess(?string $tableFilter, int $tableCount, int $durationMs): bool;
}
