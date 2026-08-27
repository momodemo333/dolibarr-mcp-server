<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Sql;

use DolibarrMcp\Sql\SqlPolicy;
use DolibarrMcp\Sql\SqlReadOnlyValidator;
use DolibarrMcp\Sql\SqlValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The validator as the only barrier.
 *
 * Host modules no longer require a dedicated SELECT-only MySQL account: the SQL
 * gateway runs on the Dolibarr connection. READ ONLY transactions stop DML but
 * not DDL, so the server no longer enforces read-only on its own — this class
 * does. Every payload below must be refused, and every legitimate query must
 * survive, or the feature is not safe to ship.
 *
 * Two halves, both load-bearing:
 *  - attacks(): what must never reach the driver;
 *  - legitimate(): the complex reads users actually write. A validator that
 *    refuses those gets worked around, and a worked-around validator protects
 *    nothing.
 */
class SqlReadOnlyHardeningTest extends TestCase
{
    private SqlReadOnlyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SqlReadOnlyValidator(new SqlPolicy());
    }

    /**
     * @dataProvider attackProvider
     */
    public function testRefusesAttack(string $sql): void
    {
        try {
            $result = $this->validator->validate($sql);
            $this->fail('Payload was accepted: ' . $sql . "\n  -> " . $result);
        } catch (SqlValidationException $e) {
            $this->assertNotSame('', $e->code());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function attackProvider(): array
    {
        return [
            // --- DDL. A READ ONLY transaction does NOT stop these: they cause an
            // implicit commit and run. Nothing but this validator refuses them.
            'rename table'       => ['RENAME TABLE llx_societe TO llx_societe_x'],
            'create index'       => ['CREATE INDEX idx_x ON llx_societe (nom)'],
            'drop index'         => ['DROP INDEX idx_x ON llx_societe'],
            'create view'        => ['CREATE VIEW v AS SELECT rowid FROM llx_societe'],
            'create view select' => ['CREATE OR REPLACE VIEW v AS SELECT 1'],
            'drop database'      => ['DROP DATABASE gsedem'],
            'create database'    => ['CREATE DATABASE x'],
            'create temp table'  => ['CREATE TEMPORARY TABLE t AS SELECT rowid FROM llx_societe'],
            'analyze table'      => ['ANALYZE TABLE llx_societe'],
            'optimize table'     => ['OPTIMIZE TABLE llx_societe'],
            'repair table'       => ['REPAIR TABLE llx_societe'],
            'create trigger'     => ['CREATE TRIGGER t BEFORE INSERT ON llx_societe FOR EACH ROW SET @x=1'],
            'create procedure'   => ['CREATE PROCEDURE p() BEGIN SELECT 1; END'],
            'alter user'         => ["ALTER USER 'x'@'%' IDENTIFIED BY 'y'"],
            'create user'        => ["CREATE USER 'x'@'%'"],
            'drop user'          => ["DROP USER 'x'@'%'"],
            'revoke'             => ['REVOKE SELECT ON *.* FROM x'],
            'flush'              => ['FLUSH PRIVILEGES'],
            'reset'              => ['RESET MASTER'],
            'kill'               => ['KILL 1'],
            'use database'       => ['USE mysql'],
            'load data infile'   => ["LOAD DATA INFILE '/etc/passwd' INTO TABLE llx_societe"],
            'handler open'       => ['HANDLER llx_societe OPEN'],
            'do'                 => ['DO SLEEP(5)'],
            'lock tables'        => ['LOCK TABLES llx_societe WRITE'],
            'start transaction'  => ['START TRANSACTION'],
            'commit'             => ['COMMIT'],
            'prepare'            => ["PREPARE s FROM 'SELECT 1'"],
            'execute'            => ['EXECUTE s'],
            'insert select'      => ['INSERT INTO llx_societe (nom) SELECT nom FROM llx_societe'],
            'update join'        => ["UPDATE llx_societe s JOIN llx_facture f ON f.fk_soc=s.rowid SET s.nom='x'"],

            // --- statement smuggling ---
            'trailing ddl'       => ['SELECT 1; DROP TABLE llx_societe'],
            'trailing ddl nosp'  => ['SELECT 1;DROP TABLE llx_societe'],
            'ddl then select'    => ['DROP TABLE llx_societe; SELECT 1'],
            'three statements'   => ['SELECT 1; SELECT 2; UPDATE llx_societe SET nom=1'],
            'semicolon in comment tail' => ["SELECT 1 -- x\n; DROP TABLE llx_societe"],

            // --- executable comments: the parser drops them, the server runs them ---
            'exec comment'       => ['SELECT 1 /*! , (SELECT 1) */'],
            'versioned comment'  => ['SELECT 1 /*!50000 UNION SELECT rowid FROM llx_const */'],
            'exec comment ddl'   => ['SELECT 1 /*!DROP TABLE llx_societe*/'],

            // --- exfiltration to disk ---
            'into outfile'       => ["SELECT rowid FROM llx_societe INTO OUTFILE '/tmp/x'"],
            'into dumpfile'      => ["SELECT rowid FROM llx_societe INTO DUMPFILE '/tmp/x'"],
            'load_file'          => ["SELECT LOAD_FILE('/etc/passwd')"],

            // --- locking reads: writes in disguise ---
            'for update'         => ['SELECT rowid FROM llx_societe FOR UPDATE'],
            'lock in share mode' => ['SELECT rowid FROM llx_societe LOCK IN SHARE MODE'],

            // --- denial of service ---
            'sleep'              => ['SELECT SLEEP(30)'],
            'sleep in where'     => ['SELECT rowid FROM llx_societe WHERE SLEEP(30)'],
            'benchmark'          => ["SELECT BENCHMARK(100000000, MD5('x'))"],
            'get_lock'           => ["SELECT GET_LOCK('x', 30)"],

            // --- reaching sensitive data by other routes ---
            'const table'        => ['SELECT value FROM llx_const'],
            'user password'      => ['SELECT pass_crypted FROM llx_user'],
            'user api key'       => ['SELECT api_key FROM llx_user'],
            'aliased api key'    => ['SELECT u.api_key AS k FROM llx_user u'],
            'password fragment'  => ['SELECT some_password_col FROM llx_user'],
            'information_schema' => ['SELECT table_name FROM information_schema.tables'],
            'mysql user table'   => ['SELECT user FROM mysql.user'],
            'union to const'     => ['SELECT rowid FROM llx_societe UNION SELECT rowid FROM llx_const'],
            'subquery to const'  => ['SELECT (SELECT value FROM llx_const LIMIT 1) AS v'],
        ];
    }

    /**
     * @dataProvider legitimateProvider
     */
    public function testAcceptsLegitimateQuery(string $sql): void
    {
        $result = $this->validator->validate($sql);
        $this->assertNotSame('', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function legitimateProvider(): array
    {
        return [
            'simple select' => [
                'SELECT rowid, nom FROM llx_societe',
            ],
            'join with aggregate' => [
                'SELECT s.nom, SUM(f.total_ht) AS ca FROM llx_societe s'
                    . ' JOIN llx_facture f ON f.fk_soc = s.rowid GROUP BY s.nom',
            ],
            'left join with having' => [
                'SELECT s.rowid, COUNT(f.rowid) AS n FROM llx_societe s'
                    . ' LEFT JOIN llx_facture f ON f.fk_soc = s.rowid'
                    . ' GROUP BY s.rowid HAVING COUNT(f.rowid) > 3',
            ],
            'union all' => [
                'SELECT rowid, nom FROM llx_societe'
                    . ' UNION ALL SELECT rowid, ref FROM llx_facture',
            ],
            'union of three' => [
                'SELECT nom FROM llx_societe UNION SELECT ref FROM llx_facture'
                    . ' UNION SELECT ref FROM llx_commande',
            ],
            'cte' => [
                'WITH ca AS (SELECT fk_soc, SUM(total_ht) t FROM llx_facture GROUP BY fk_soc)'
                    . ' SELECT s.nom, ca.t FROM llx_societe s JOIN ca ON ca.fk_soc = s.rowid',
            ],
            'two ctes' => [
                'WITH a AS (SELECT rowid FROM llx_societe), b AS (SELECT fk_soc FROM llx_facture)'
                    . ' SELECT a.rowid FROM a JOIN b ON b.fk_soc = a.rowid',
            ],
            'nested subquery' => [
                'SELECT nom FROM llx_societe WHERE rowid IN'
                    . ' (SELECT fk_soc FROM llx_facture WHERE total_ht >'
                    . ' (SELECT AVG(total_ht) FROM llx_facture))',
            ],
            'exists' => [
                'SELECT s.nom FROM llx_societe s WHERE EXISTS'
                    . ' (SELECT 1 FROM llx_facture f WHERE f.fk_soc = s.rowid)',
            ],
            'case expression' => [
                "SELECT nom, CASE WHEN client = 1 THEN 'customer' ELSE 'other' END AS kind"
                    . ' FROM llx_societe',
            ],
            'derived table' => [
                'SELECT t.fk_soc, t.n FROM (SELECT fk_soc, COUNT(*) n FROM llx_facture'
                    . ' GROUP BY fk_soc) t WHERE t.n > 2',
            ],
            'date functions' => [
                'SELECT YEAR(datef) AS y, SUM(total_ht) FROM llx_facture GROUP BY YEAR(datef)',
            ],
            'order and limit' => [
                'SELECT nom FROM llx_societe ORDER BY nom ASC LIMIT 10',
            ],
            'distinct' => [
                'SELECT DISTINCT fk_soc FROM llx_facture',
            ],
            'self join' => [
                'SELECT a.nom, b.nom FROM llx_societe a JOIN llx_societe b ON a.parent = b.rowid',
            ],

            // The trap Morgan called out: write keywords living inside string
            // literals, identifiers and aliases. The lexer knows it is inside a
            // quoted run, so none of these look like statements.
            'update word in literal' => [
                "SELECT rowid FROM llx_societe WHERE nom = 'update stock'",
            ],
            'delete word in literal' => [
                "SELECT rowid FROM llx_societe WHERE note_private = 'please delete from list'",
            ],
            'drop table words in literal' => [
                "SELECT rowid FROM llx_facture WHERE note_public = 'drop table llx_societe'",
            ],
            'insert word in like' => [
                "SELECT rowid FROM llx_societe WHERE nom LIKE '%insert into%'",
            ],
            'semicolon inside literal' => [
                "SELECT rowid FROM llx_societe WHERE nom = 'a;b'",
            ],
            'write word as alias' => [
                'SELECT nom AS update_label FROM llx_societe',
            ],
            'write word in column name' => [
                'SELECT date_update FROM llx_societe',
            ],
            'comment before query' => [
                "-- monthly revenue\nSELECT SUM(total_ht) FROM llx_facture",
            ],
            'block comment inside' => [
                'SELECT /* revenue */ SUM(total_ht) FROM llx_facture',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // SELECT * — decided against the real columns, not assumed dangerous.
    // ------------------------------------------------------------------

    /** A resolver standing in for the host's information_schema lookup. */
    private function resolver(array $columnsByTable): callable
    {
        return static fn (string $table): array => $columnsByTable[$table] ?? [];
    }

    public function testStarIsRefusedWithoutAResolver(): void
    {
        // Fail closed: with no way to know what the star stands for, it cannot
        // be allowed.
        $this->expectException(SqlValidationException::class);
        $this->validator->validate('SELECT * FROM llx_societe');
    }

    public function testStarIsAllowedWhenEveryColumnIsHarmless(): void
    {
        $validator = new SqlReadOnlyValidator(
            new SqlPolicy(),
            null,
            $this->resolver(['llx_societe' => ['rowid', 'nom', 'email', 'siren']])
        );

        $this->assertNotSame('', $validator->validate('SELECT * FROM llx_societe'));
    }

    public function testStarIsRefusedWhenATableHoldsACredentialColumn(): void
    {
        $validator = new SqlReadOnlyValidator(
            new SqlPolicy(),
            null,
            $this->resolver(['llx_user' => ['rowid', 'login', 'pass_crypted']])
        );

        try {
            $validator->validate('SELECT * FROM llx_user');
            $this->fail('SELECT * exposed a credential column');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_STAR_NOT_ALLOWED', $e->code());
            // The message must name the offending column, or the caller cannot
            // tell which table to stop starring.
            $this->assertStringContainsString('pass_crypted', $e->getMessage());
        }
    }

    public function testStarIsRefusedWhenAnyJoinedTableHoldsACredentialColumn(): void
    {
        $validator = new SqlReadOnlyValidator(
            new SqlPolicy(),
            null,
            $this->resolver([
                'llx_facture' => ['rowid', 'ref', 'total_ht'],
                'llx_user' => ['rowid', 'api_key'],
            ])
        );

        $this->expectException(SqlValidationException::class);
        $validator->validate(
            'SELECT * FROM llx_facture f JOIN llx_user u ON u.rowid = f.fk_user_valid'
        );
    }

    public function testStarStillCannotReachADeniedTable(): void
    {
        // The table ban runs before the star is ever resolved.
        $validator = new SqlReadOnlyValidator(
            new SqlPolicy(),
            null,
            $this->resolver(['llx_const' => ['rowid', 'name', 'value']])
        );

        $this->expectException(SqlValidationException::class);
        $validator->validate('SELECT * FROM llx_const');
    }

    public function testCountStarNeedsNoResolver(): void
    {
        $this->assertNotSame('', $this->validator->validate('SELECT COUNT(*) FROM llx_societe'));
    }
}
