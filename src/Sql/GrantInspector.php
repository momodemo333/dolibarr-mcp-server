<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

/**
 * Checks that a database account really is read-only, from its own grants.
 *
 * Requiring a *dedicated* account is not the same as requiring a *restricted*
 * one: an administrator can create a separate account and grant it everything,
 * which satisfies "not the application account" while leaving the feature able
 * to write. The only evidence that the server will enforce read-only is what
 * SHOW GRANTS reports, so it is parsed rather than assumed.
 *
 * The rule is an allowlist: `USAGE ON *.*` (which grants nothing beyond the
 * ability to connect) and `SELECT` limited to the current database. Everything
 * else — a second privilege on the same line, SELECT on *.*, SELECT on another
 * database, GRANT OPTION, roles, PROXY, routine-level grants — is refused, as
 * is any line that cannot be parsed.
 *
 * WARNING: grant lines contain the account's password hash
 * (`IDENTIFIED BY PASSWORD '*…'`). No message produced here, and nothing in the
 * caller, may include a grant line.
 */
class GrantInspector
{
    /**
     * Privileges that are safe on their own.
     *
     * USAGE is MySQL's "no privileges" marker; SELECT is what the feature
     * needs. Anything absent from this list is refused, so a privilege added
     * by a future server version is refused by default rather than ignored.
     */
    private const READ_ONLY_PRIVILEGES = ['USAGE', 'SELECT'];

    /**
     * @param array<int, string> $grantLines Raw SHOW GRANTS output
     * @param string             $database   Database the module reads
     *
     * @throws SqlValidationException when the account is not demonstrably read-only
     */
    public function assertReadOnly(array $grantLines, string $database): void
    {
        if ($grantLines === []) {
            throw $this->refuse('its privileges could not be listed');
        }

        $canSelect = false;

        foreach ($grantLines as $line) {
            $normalized = trim(preg_replace('/\s+/', ' ', (string) $line) ?? '');
            if ($normalized === '') {
                continue;
            }

            // "GRANT <privileges> ON <scope> TO <grantee>[ trailing clauses]".
            // A role grant ("GRANT `r` TO `u`@`%`") has no ON and fails here,
            // which is what we want: a role can carry anything.
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s+(.*)$/i', $normalized, $m) !== 1) {
                throw $this->refuse('one of its grants could not be interpreted');
            }

            [$privilegesPart, $scope, $trailing] = [$m[1], $m[2], $m[3]];

            // WITH GRANT OPTION lets the account widen itself at will.
            if (preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $trailing) === 1) {
                throw $this->refuse('it holds GRANT OPTION');
            }

            // "ON PROCEDURE x", "ON FUNCTION x", "ON TABLE x": the scope token
            // is a keyword, and the real object follows. Routine grants imply
            // EXECUTE, and are refused outright.
            if (preg_match('/^(PROCEDURE|FUNCTION)$/i', $scope) === 1) {
                throw $this->refuse('it holds privileges on stored routines');
            }

            $privileges = $this->splitPrivileges($privilegesPart);
            if ($privileges === []) {
                throw $this->refuse('one of its grants could not be interpreted');
            }

            foreach ($privileges as $privilege) {
                if (!in_array($privilege, self::READ_ONLY_PRIVILEGES, true)) {
                    throw $this->refuse('it holds privileges beyond SELECT');
                }
            }

            $scopeKind = $this->classifyScope($scope, $database);

            foreach ($privileges as $privilege) {
                if ($privilege === 'USAGE') {
                    // USAGE grants nothing; its scope is irrelevant.
                    continue;
                }

                // SELECT, then, and it must be confined to our database.
                if ($scopeKind !== 'current-database') {
                    throw $this->refuse(
                        $scopeKind === 'global'
                            ? 'it holds SELECT on every database'
                            : 'it holds SELECT on another database'
                    );
                }
                $canSelect = true;
            }
        }

        if (!$canSelect) {
            throw $this->refuse('it has no SELECT privilege on this database');
        }
    }

    /**
     * Split "SELECT, INSERT" into a normalised list.
     *
     * ALL PRIVILEGES is deliberately left as one unknown token so it fails the
     * allowlist rather than being read as the word "ALL".
     *
     * @return array<int, string>
     */
    private function splitPrivileges(string $part): array
    {
        $privileges = [];

        foreach (explode(',', $part) as $chunk) {
            $chunk = strtoupper(trim($chunk));

            // Column-level grants read "SELECT (col1, col2)"; the parenthesised
            // list is not a privilege name. Splitting on commas breaks it into
            // fragments, which then fail the allowlist — the safe outcome, and
            // one we do not need to support.
            $chunk = trim(preg_replace('/\(.*$/', '', $chunk) ?? '');

            if ($chunk === '') {
                continue;
            }
            $privileges[] = $chunk;
        }

        return $privileges;
    }

    /**
     * @return string 'global', 'current-database' or 'other'
     */
    private function classifyScope(string $scope, string $database): string
    {
        $scope = trim($scope);

        if ($scope === '*.*') {
            return 'global';
        }

        // `db`.* or db.* or `db`.`table`
        if (preg_match('/^`?([^`.]+)`?\.(\*|`?[^`.]+`?)$/', $scope, $m) !== 1) {
            return 'other';
        }

        return strcasecmp($m[1], $database) === 0 ? 'current-database' : 'other';
    }

    /**
     * Build the refusal. The reason never quotes the grant line, which carries
     * the account's password hash.
     */
    private function refuse(string $reason): SqlValidationException
    {
        return new SqlValidationException(
            'SQL_ACCOUNT_NOT_READ_ONLY',
            'The configured database account is not usable for read-only access: ' . $reason . '. '
                . 'Grant it USAGE and SELECT on this database only.'
        );
    }
}
