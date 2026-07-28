<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

use PHPSQLParser\PHPSQLParser;

/**
 * Decides whether a statement is a read-only query the policy allows, and
 * returns it with a row limit applied.
 *
 * The pipeline is strictly additive in refusals: lexer, then parser, then a
 * whitelist of top-level clauses, then the policy over every table, column,
 * function and variable found anywhere in the tree. A parser failure is a
 * refusal, never an acceptance.
 *
 * Identifiers are matched on their terminal segment, which neutralises both
 * database qualification (gsedem.llx_user) and table aliasing (u.api_key).
 */
class SqlReadOnlyValidator
{
    /**
     * Top-level clauses a read-only query may produce.
     *
     * Anything else — INSERT, UPDATE, DROP, SET, SHOW, INTO … — is a refusal.
     * A whitelist is used rather than a denylist of write keywords because the
     * parser happily merges an unexpected clause into the same tree.
     */
    private const ALLOWED_SECTIONS = [
        'SELECT',
        'FROM',
        'WHERE',
        'GROUP',
        'HAVING',
        'ORDER',
        'LIMIT',
        'WITH',
        'UNION',
        'UNION ALL',
        'EXCEPT',
        'INTERSECT',
        'BRACKET',
        // OPTIONS is deliberately absent. The parser files trailing modifiers
        // there, including LOCK IN SHARE MODE — a locking read that was going
        // through on a supposedly read-only path. DISTINCT and
        // SQL_CALC_FOUND_ROWS do not use this section, so nothing legitimate
        // depends on it.
    ];

    private SqlLexer $lexer;

    public function __construct(private SqlPolicy $policy, ?SqlLexer $lexer = null)
    {
        $this->lexer = $lexer ?? new SqlLexer($policy->maxSqlLength());
    }

    /**
     * @return string the query with a row limit applied, ready to execute
     *
     * @throws SqlValidationException
     */
    public function validate(string $sql): string
    {
        $this->lexer->assertSingleReadOnlyStatement($sql);

        $normalized = rtrim(trim($sql), "; \t\n\r");

        try {
            $tree = (new PHPSQLParser())->parse($normalized, true);
        } catch (\Throwable $e) {
            throw new SqlValidationException(
                'SQL_PARSE_ERROR',
                'The query could not be parsed. Check its syntax.'
            );
        }

        if (!is_array($tree) || $tree === []) {
            throw new SqlValidationException(
                'SQL_PARSE_ERROR',
                'The query could not be parsed. Check its syntax.'
            );
        }

        $this->assertSectionsAllowed($tree);
        $this->assertNoLockingClause($normalized);

        $found = $this->collect($tree);
        $this->assertPolicyHolds($found);

        return $this->applyLimit($normalized, $tree);
    }

    public function policy(): SqlPolicy
    {
        return $this->policy;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function assertSectionsAllowed(array $tree): void
    {
        // Checking only the top level was not enough. A parenthesised
        // statement is parsed as a BRACKET section whose sub_tree carries the
        // real command, so "(UPDATE llx_societe SET nom='x')" presented itself
        // as a lone allowed BRACKET and passed as read-only. The same holds for
        // any nested sub_tree the parser produces.
        //
        // The walk is therefore over the whole tree, and every upper-case key
        // anywhere in it must be an allowed clause. Section names are the only
        // upper-case keys the parser emits — node fields are lower-case
        // (expr_type, base_expr, no_quotes, sub_tree) — so this is fail-closed
        // by construction: a clause we have never seen is refused rather than
        // ignored.
        $this->assertNoForbiddenSectionKey($tree);
    }

    /**
     * @param array<mixed> $node
     */
    private function assertNoForbiddenSectionKey(array $node): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && $key === strtoupper($key) && preg_match('/[A-Z]/', $key) === 1) {
                if (!in_array($key, self::ALLOWED_SECTIONS, true)) {
                    throw new SqlValidationException(
                        'SQL_NOT_READ_ONLY',
                        'Only read-only SELECT queries are allowed (WITH, JOIN, UNION and subqueries are supported).'
                    );
                }
            }

            if (is_array($value)) {
                $this->assertNoForbiddenSectionKey($value);
            }
        }
    }

    /**
     * Refuse locking reads, which the parse tree does not reliably expose.
     *
     * `LOCK IN SHARE MODE` lands in an OPTIONS section (now off the whitelist),
     * but `FOR SHARE` is swallowed into the table alias and leaves no trace at
     * all — the tree reports a plain SELECT. The check therefore runs on the
     * text, but on the masked text: string literals and comments are blanked
     * first, so `WHERE nom = 'FOR UPDATE'` cannot trip it.
     *
     * These statements take row locks. They are not writes, but they are not
     * the read-only behaviour this tool advertises either, and they can block
     * the application.
     *
     * @throws SqlValidationException
     */
    private function assertNoLockingClause(string $sql): void
    {
        $masked = $this->lexer->maskLiteralsAndComments($sql);

        $patterns = [
            '/\bFOR\s+UPDATE\b/i',
            '/\bFOR\s+SHARE\b/i',
            '/\bFOR\s+NO\s+KEY\s+UPDATE\b/i',
            '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $masked) === 1) {
                throw new SqlValidationException(
                    'SQL_NOT_READ_ONLY',
                    'Locking reads (FOR UPDATE, FOR SHARE, LOCK IN SHARE MODE) are not allowed.'
                );
            }
        }
    }

    /**
     * Walk the whole tree once, collecting everything the policy cares about.
     *
     * @param array<string, mixed> $tree
     *
     * @return array{tables: array<int, string>, columns: array<int, string>, functions: array<int, string>, cteNames: array<int, string>, hasStar: bool, variables: array<int, string>}
     */
    private function collect(array $tree): array
    {
        $found = [
            'tables' => [],
            'columns' => [],
            'functions' => [],
            'cteNames' => [],
            'hasStar' => false,
            'variables' => [],
        ];

        $this->walk($tree, $found);

        return $found;
    }

    /**
     * @param array<mixed>                                                                                                                              $node
     * @param array{tables: array<int, string>, columns: array<int, string>, functions: array<int, string>, cteNames: array<int, string>, hasStar: bool, variables: array<int, string>} $found
     */
    private function walk(array $node, array &$found, bool $insideCount = false): void
    {
        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }

            $type = isset($value['expr_type']) && is_string($value['expr_type'])
                ? strtolower($value['expr_type'])
                : '';

            if ($type !== '') {
                $this->classify($value, $found, $insideCount);
            }

            // COUNT(*) is the one place a star is an argument rather than a
            // projection. Any other function wrapping a star is not a valid
            // aggregate form, so the exemption is limited to COUNT instead of
            // covering functions in general.
            $isCount = ($type === 'function' || $type === 'aggregate_function')
                && strtolower(trim((string) ($value['base_expr'] ?? ''))) === 'count';

            $this->walk($value, $found, $insideCount || $isCount);
        }
    }

    /**
     * @param array<string, mixed>                                                                                                                      $node
     * @param array{tables: array<int, string>, columns: array<int, string>, functions: array<int, string>, cteNames: array<int, string>, hasStar: bool, variables: array<int, string>} $found
     */
    private function classify(array $node, array &$found, bool $insideCount = false): void
    {
        $type = strtolower((string) $node['expr_type']);
        $name = $this->identifier($node);

        switch ($type) {
            case 'table':
                // A table written as db.table would otherwise be reduced to
                // its terminal segment and checked as if it lived in this
                // database, letting "other_db.llx_societe" read another
                // Dolibarr on the same server. Refusing qualification outright
                // does not depend on MySQL grants, which this module neither
                // sets nor can inspect.
                if ($this->segmentCount($node) > 1) {
                    throw new SqlValidationException(
                        'SQL_QUALIFIED_TABLE',
                        'Database-qualified table names are not allowed: query the current database only.'
                    );
                }
                if ($name !== '') {
                    $found['tables'][] = $name;
                }
                break;

            case 'subquery-factoring':
                // The parser mis-reads "WITH RECURSIVE n AS (…)" and reports
                // RECURSIVE as the CTE name, losing "n". Read the real name off
                // the clause text instead.
                $base = trim((string) ($node['base_expr'] ?? ''));
                if (preg_match('/^\s*(?:RECURSIVE\s+)?`?([A-Za-z0-9_$]+)`?\s+AS\s*\(/i', $base, $m) === 1) {
                    $found['cteNames'][] = strtolower($m[1]);
                }
                break;

            case 'temporary-table':
                if ($name !== '' && $name !== 'recursive') {
                    $found['cteNames'][] = $name;
                }
                break;

            case 'colref':
                $raw = trim((string) ($node['base_expr'] ?? ''));
                if ($raw === '*' || str_ends_with($raw, '.*')) {
                    if (!$insideCount) {
                        $found['hasStar'] = true;
                    }
                    break;
                }
                // db.table.column has three segments; two is the ordinary
                // alias.column form, which stays allowed.
                if ($this->segmentCount($node) > 2) {
                    throw new SqlValidationException(
                        'SQL_QUALIFIED_COLUMN',
                        'Database-qualified column names are not allowed: query the current database only.'
                    );
                }
                if ($name !== '' && $name !== '*') {
                    $found['columns'][] = $name;
                }
                break;

            case 'function':
            case 'aggregate_function':
                $fn = strtolower(trim((string) ($node['base_expr'] ?? '')));
                if ($fn === '') {
                    break;
                }
                // A qualified name reaches a routine in another database, so
                // comparing the whole string against the denylist is not
                // enough — otherdb.evil_udf() matches nothing in it.
                if (str_contains($fn, '.')) {
                    throw new SqlValidationException(
                        'SQL_FORBIDDEN_FUNCTION',
                        'Database-qualified function names are not allowed.'
                    );
                }
                $found['functions'][] = $fn;
                break;

            case 'session_variable':
            case 'user_variable':
                $found['variables'][] = trim((string) ($node['base_expr'] ?? ''));
                break;
        }
    }

    /**
     * How many dot-separated parts an identifier has.
     *
     * The parser exposes them in no_quotes.parts, which also handles the
     * backticked form (`db`.`table`). When that is missing, the leading token
     * of base_expr is counted instead — table nodes carry their alias and join
     * condition there.
     *
     * @param array<string, mixed> $node
     */
    private function segmentCount(array $node): int
    {
        $parts = $node['no_quotes']['parts'] ?? null;
        if (is_array($parts)) {
            return count($parts);
        }

        $base = trim((string) ($node['base_expr'] ?? ''));
        if ($base === '') {
            return 0;
        }
        $first = preg_split('/\s+/', $base)[0] ?? '';

        return count(explode('.', trim($first, '`"\'')));
    }

    /**
     * Terminal segment of a qualified identifier, quotes removed.
     *
     * "u.api_key" yields "api_key" — matching the column denylist on the
     * terminal name is deliberately stricter than resolving alias to table.
     * Database qualification is refused before reaching here.
     *
     * @param array<string, mixed> $node
     */
    private function identifier(array $node): string
    {
        $parts = $node['no_quotes']['parts'] ?? null;
        if (is_array($parts) && $parts !== []) {
            return strtolower(trim((string) end($parts)));
        }

        $base = trim((string) ($node['base_expr'] ?? ''));
        if ($base === '') {
            return '';
        }

        // Fall back to the first whitespace-delimited token: table nodes carry
        // their alias and join condition in base_expr ("llx_user u ON …").
        $first = preg_split('/\s+/', $base)[0] ?? '';
        $segments = explode('.', trim($first, '`"\''));

        return strtolower(trim((string) end($segments), '`"\''));
    }

    /**
     * @param array{tables: array<int, string>, columns: array<int, string>, functions: array<int, string>, cteNames: array<int, string>, hasStar: bool, variables: array<int, string>} $found
     */
    private function assertPolicyHolds(array $found): void
    {
        if ($found['variables'] !== []) {
            throw new SqlValidationException(
                'SQL_FORBIDDEN_VARIABLE',
                'Server and user variables are not allowed.'
            );
        }

        $cteNames = array_map('strtolower', $found['cteNames']);

        // A CTE is exempt from the table policy so that `WITH x AS (…) SELECT
        // … FROM x` does not look like an unknown table. Left unchecked, that
        // exemption launders a denied table: naming the second CTE llx_const
        // makes every later reference to llx_const look like a CTE reference.
        // Reserving the prefix costs agents nothing — report_x is a fine name.
        foreach ($cteNames as $cte) {
            if (str_starts_with($cte, $this->policy->tablePrefix())) {
                throw new SqlValidationException(
                    'SQL_CTE_NAME_RESERVED',
                    sprintf(
                        'A CTE cannot be named "%s": names starting with "%s" are reserved for real tables. '
                            . 'Use a name like "report_x".',
                        $cte,
                        $this->policy->tablePrefix()
                    )
                );
            }
        }

        foreach ($found['tables'] as $table) {
            // A CTE is referenced like a table but is not one.
            if (in_array($table, $cteNames, true)) {
                continue;
            }
            if (!$this->policy->isTableAllowed($table)) {
                throw new SqlValidationException(
                    'SQL_FORBIDDEN_TABLE',
                    sprintf('Table "%s" is not available to read-only queries.', $table)
                );
            }
        }

        foreach ($found['columns'] as $column) {
            if (!$this->policy->isColumnAllowed($column)) {
                throw new SqlValidationException(
                    'SQL_FORBIDDEN_COLUMN',
                    sprintf('Column "%s" holds credentials and cannot be read.', $column)
                );
            }
        }

        foreach ($found['functions'] as $function) {
            if (!$this->policy->isFunctionAllowed($function)) {
                throw new SqlValidationException(
                    'SQL_FORBIDDEN_FUNCTION',
                    sprintf('Function "%s" is not allowed.', $function)
                );
            }
        }

        // Refused on every table, not just the ones known to hold secrets.
        // A list of sensitive tables only protects what was thought of, and a
        // third-party module table such as llx_x_config holding a key was
        // fully readable through SELECT *. Naming the columns also keeps the
        // response small, which the model benefits from anyway.
        if ($found['hasStar']) {
            throw new SqlValidationException(
                'SQL_STAR_NOT_ALLOWED',
                'SELECT * is not allowed: list the columns you need. '
                    . 'Use dolibarr_sql_schema to look them up. COUNT(*) is allowed.'
            );
        }
    }

    /**
     * Rebuild the statement with exactly one enforceable LIMIT clause.
     *
     * Appending the clause is not enough. `SELECT … -- trailing` would put the
     * limit behind a line comment and run unbounded, and `LIMIT 10 OFFSET 5`
     * does not match a `LIMIT n[,m]` rewrite, so a clamp could silently miss.
     * The existing clause is therefore located on the *masked* statement —
     * literals and comments blanked — cut off, and a fresh clause appended on
     * its own line, which also terminates any trailing line comment.
     *
     * The effective row count is never raised: `LIMIT 0` legitimately asks for
     * nothing and must stay 0, where the previous code rewrote it to the
     * maximum and returned 200 unrequested rows.
     *
     * @param array<string, mixed> $tree
     */
    private function applyLimit(string $sql, array $tree): string
    {
        $max = $this->policy->maxRows();
        $body = $sql;
        $rowcount = $max;
        $offset = '';

        if (isset($tree['LIMIT']) && is_array($tree['LIMIT'])) {
            $rowcount = min((int) ($tree['LIMIT']['rowcount'] ?? $max), $max);
            $offset = trim((string) ($tree['LIMIT']['offset'] ?? ''));
            $body = $this->stripTrailingLimit($sql);
        }

        $clause = $offset !== ''
            ? sprintf('LIMIT %d, %d', (int) $offset, $rowcount)
            : sprintf('LIMIT %d', $rowcount);

        // The newline matters: without it a trailing line comment in $body
        // would comment the clause out.
        return rtrim($body) . "\n" . $clause;
    }

    /**
     * Remove the trailing LIMIT clause, located on the masked statement.
     *
     * Working on the mask means a literal such as `WHERE nom = 'LIMIT 5'`
     * cannot be mistaken for the clause. Offsets match the original because
     * masking preserves length.
     */
    private function stripTrailingLimit(string $sql): string
    {
        $masked = $this->lexer->maskLiteralsAndComments($sql);

        // LIMIT n | LIMIT o, n | LIMIT n OFFSET o, followed only by blanks.
        $pattern = '/\bLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i';

        if (preg_match($pattern, $masked, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new SqlValidationException(
                'SQL_PARSE_ERROR',
                'The LIMIT clause could not be normalised. Simplify the query.'
            );
        }

        return substr($sql, 0, $m[0][1]);
    }
}
