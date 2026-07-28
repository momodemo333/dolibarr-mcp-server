<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Sql;

use DolibarrMcp\Sql\SqlPolicy;
use PHPUnit\Framework\TestCase;

class SqlPolicyTest extends TestCase
{
    public function testDeniedTables(): void
    {
        $p = new SqlPolicy();
        foreach ([
            'llx_const',
            'llx_session',
            'llx_oauth_token',
            'llx_emmcp_oauth_token',
            'llx_emmcp_oauth_client',
            'llx_emmcp_sql_audit',
            'llx_emmcp_sql_permissions',
            'llx_dalfred_activity_log',
            'llx_dalfred_toolkit_permissions',
        ] as $table) {
            $this->assertFalse($p->isTableAllowed($table), $table);
            $this->assertFalse($p->isTableAllowed(strtoupper($table)), $table . ' uppercase');
        }
    }

    public function testDeniedSystemDatabases(): void
    {
        $p = new SqlPolicy();
        foreach (['information_schema', 'mysql', 'performance_schema', 'sys'] as $db) {
            $this->assertFalse($p->isTableAllowed($db), $db);
        }
    }

    public function testAllowedTablesRequirePrefix(): void
    {
        $p = new SqlPolicy();
        $this->assertTrue($p->isTableAllowed('llx_societe'));
        $this->assertTrue($p->isTableAllowed('llx_facture'));
        // Allowed as a table: its sensitive columns are filtered separately,
        // which is what keeps "revenue per salesperson" style joins working.
        $this->assertTrue($p->isTableAllowed('llx_user'));
        $this->assertTrue($p->isTableAllowed('llx_mymodule_stuff'));
        $this->assertFalse($p->isTableAllowed('societe'));
        $this->assertFalse($p->isTableAllowed('other_prefix_societe'));
    }

    public function testCustomPrefix(): void
    {
        $p = new SqlPolicy('dolib_');
        $this->assertTrue($p->isTableAllowed('dolib_societe'));
        $this->assertFalse($p->isTableAllowed('llx_societe'));
        $this->assertFalse($p->isTableAllowed('dolib_const'), 'denylist must follow the prefix');
        $this->assertFalse($p->isTableAllowed('dolib_emmcp_oauth_token'));
    }

    public function testDeniedColumns(): void
    {
        $p = new SqlPolicy();
        foreach ([
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
        ] as $column) {
            $this->assertFalse($p->isColumnAllowed($column), $column);
            $this->assertFalse($p->isColumnAllowed(strtoupper($column)), $column . ' uppercase');
        }
    }

    /**
     * An exact-match list only covers the names we happened to think of. Every
     * one of these was readable before patterns were added, and they are the
     * ordinary spellings used by third-party Dolibarr modules.
     *
     * @dataProvider secretColumnPatternProvider
     */
    public function testDeniedColumnPatterns(string $column): void
    {
        $p = new SqlPolicy();
        $this->assertFalse($p->isColumnAllowed($column), $column);
        $this->assertFalse($p->isColumnAllowed(strtoupper($column)), $column . ' uppercase');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secretColumnPatternProvider(): array
    {
        $columns = [
            'password', 'passwd', 'passphrase', 'user_password', 'smtp_password',
            'db_password', 'mail_password', 'password_hash', 'motdepasse',
            'apikey', 'api_key_hash', 'apikey_secret', 'public_api_key',
            'access_token', 'auth_token', 'bearer_token', 'oauth_token',
            'refresh_token_hash', 'session_token', 'reset_token',
            'webhook_secret', 'shared_secret', 'secret_key', 'client_secret',
            'privatekey', 'private_key_pem', 'signature_key', 'signing_key',
            'credential', 'credentials_json', 'totp_secret',
        ];

        $cases = [];
        foreach ($columns as $column) {
            $cases[$column] = [$column];
        }

        return $cases;
    }

    public function testAllowedColumns(): void
    {
        $p = new SqlPolicy();
        foreach ([
            'rowid', 'login', 'lastname', 'firstname', 'nom', 'total_ht',
            'date_creation', 'ref', 'fk_soc', 'fk_statut', 'note_private',
            'email', 'address', 'town', 'description', 'label', 'amount',
            // Near misses that must NOT trip the patterns.
            'passage', 'passenger_count', 'compte_general', 'keyword', 'nb_lignes',
        ] as $column) {
            $this->assertTrue($p->isColumnAllowed($column), $column);
        }
    }

    /**
     * Documented fail-closed false positives: these names are refused although
     * they may be innocent, because narrowing the pattern would let real
     * secrets through. Asserted so the trade-off stays visible and deliberate.
     */
    public function testAcceptedFalsePositives(): void
    {
        $p = new SqlPolicy();
        foreach (['token_count', 'secretary_name', 'passwordless_login'] as $column) {
            $this->assertFalse($p->isColumnAllowed($column), $column);
        }
    }

    public function testDeniedFunctions(): void
    {
        $p = new SqlPolicy();
        foreach ([
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
        ] as $fn) {
            $this->assertFalse($p->isFunctionAllowed($fn), $fn);
            $this->assertFalse($p->isFunctionAllowed(strtoupper($fn)), $fn . ' uppercase');
        }
    }

    public function testAllowedFunctions(): void
    {
        $p = new SqlPolicy();
        foreach (['sum', 'count', 'avg', 'min', 'max', 'date_format', 'concat', 'coalesce'] as $fn) {
            $this->assertTrue($p->isFunctionAllowed($fn), $fn);
        }
    }

    public function testDefaultLimits(): void
    {
        $p = new SqlPolicy();
        $this->assertSame(200, $p->maxRows());
        $this->assertSame(5, $p->timeoutSeconds());
        $this->assertSame(262144, $p->maxBytes());
        $this->assertSame(8000, $p->maxSqlLength());
    }

    public function testLimitsAreOverridable(): void
    {
        $p = new SqlPolicy('llx_', ['maxRows' => 50, 'timeoutSeconds' => 3, 'maxBytes' => 1024]);
        $this->assertSame(50, $p->maxRows());
        $this->assertSame(3, $p->timeoutSeconds());
        $this->assertSame(1024, $p->maxBytes());
    }

    public function testLimitsAreClampedToHardCeilings(): void
    {
        $p = new SqlPolicy('llx_', ['maxRows' => 999999, 'timeoutSeconds' => 600]);
        $this->assertSame(5000, $p->maxRows());
        $this->assertSame(30, $p->timeoutSeconds());
    }

    public function testLimitsHaveFloors(): void
    {
        $p = new SqlPolicy('llx_', ['maxRows' => 0, 'timeoutSeconds' => 0]);
        $this->assertSame(1, $p->maxRows());
        $this->assertSame(1, $p->timeoutSeconds());
    }

    public function testDenylistsAreExposedForAdminDisplay(): void
    {
        $p = new SqlPolicy();
        $this->assertContains('llx_const', $p->deniedTables());
        $this->assertContains('api_key', $p->deniedColumns());
    }
}
