<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Sql;

use DolibarrMcp\Sql\SqlPolicy;
use DolibarrMcp\Sql\SqlReadOnlyValidator;
use DolibarrMcp\Sql\SqlValidationException;
use PHPUnit\Framework\TestCase;

class SqlReadOnlyValidatorTest extends TestCase
{
    private SqlReadOnlyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SqlReadOnlyValidator(new SqlPolicy());
    }

    /**
     * @dataProvider rejectedProvider
     */
    public function testRejects(string $sql, string $expectedCode): void
    {
        try {
            $this->validator->validate($sql);
            $this->fail('Expected rejection for: ' . $sql);
        } catch (SqlValidationException $e) {
            $this->assertSame($expectedCode, $e->code(), 'wrong code for: ' . $sql);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            // --- writes and non-SELECT statements ---
            'update'        => ["UPDATE llx_societe SET nom='x'", 'SQL_NOT_READ_ONLY'],
            'insert'        => ["INSERT INTO llx_societe (nom) VALUES ('x')", 'SQL_NOT_READ_ONLY'],
            'delete'        => ['DELETE FROM llx_societe', 'SQL_NOT_READ_ONLY'],
            'replace'       => ["REPLACE INTO llx_societe (nom) VALUES ('x')", 'SQL_NOT_READ_ONLY'],
            'drop'          => ['DROP TABLE llx_societe', 'SQL_NOT_READ_ONLY'],
            'create'        => ['CREATE TABLE llx_x (a INT)', 'SQL_NOT_READ_ONLY'],
            'alter'         => ['ALTER TABLE llx_societe ADD COLUMN x INT', 'SQL_NOT_READ_ONLY'],
            'truncate'      => ['TRUNCATE TABLE llx_societe', 'SQL_NOT_READ_ONLY'],
            'call'          => ['CALL myproc()', 'SQL_NOT_READ_ONLY'],
            'set'           => ['SET @x = 1', 'SQL_NOT_READ_ONLY'],
            'show'          => ['SHOW TABLES', 'SQL_NOT_READ_ONLY'],
            // The parser has no GRANT grammar and throws; a parse failure is a
            // refusal, which is the property that matters here.
            'grant'         => ['GRANT SELECT ON *.* TO x', 'SQL_PARSE_ERROR'],
            'into outfile'  => ["SELECT rowid FROM llx_societe INTO OUTFILE '/tmp/x'", 'SQL_NOT_READ_ONLY'],
            'into dumpfile' => ["SELECT rowid FROM llx_societe INTO DUMPFILE '/tmp/x'", 'SQL_NOT_READ_ONLY'],

            // Locking reads. The parser files LOCK IN SHARE MODE under an
            // OPTIONS section, which the whitelist used to accept, so a
            // locking statement went through on a "read-only" path.
            'lock in share mode' => ['SELECT rowid FROM llx_societe LOCK IN SHARE MODE', 'SQL_NOT_READ_ONLY'],
            'for share'          => ['SELECT rowid FROM llx_societe FOR SHARE', 'SQL_NOT_READ_ONLY'],

            // --- writes hidden inside brackets ---
            //
            // A parenthesised statement is parsed as a BRACKET section whose
            // sub_tree holds the real command. Checking only the top-level
            // keys accepted every one of these as read-only.
            'bracketed update'   => ["(UPDATE llx_societe SET nom = 'x')", 'SQL_NOT_READ_ONLY'],
            'bracketed delete'   => ['(DELETE FROM llx_societe)', 'SQL_NOT_READ_ONLY'],
            'bracketed insert'   => ["(INSERT INTO llx_societe (nom) VALUES ('x'))", 'SQL_NOT_READ_ONLY'],
            'bracketed drop'     => ['(DROP TABLE llx_societe)', 'SQL_NOT_READ_ONLY'],
            'bracketed truncate' => ['(TRUNCATE TABLE llx_societe)', 'SQL_NOT_READ_ONLY'],
            'double bracketed'   => ["((UPDATE llx_societe SET nom = 'x'))", 'SQL_NOT_READ_ONLY'],
            'bracketed set'      => ['(SET @x = 1)', 'SQL_NOT_READ_ONLY'],

            // --- denied tables, reached through every route ---
            'denied table'           => ['SELECT rowid FROM llx_const', 'SQL_FORBIDDEN_TABLE'],
            'denied table subquery'  => ['SELECT nom FROM llx_societe WHERE rowid IN (SELECT rowid FROM llx_const)', 'SQL_FORBIDDEN_TABLE'],
            'denied table cte'       => ['WITH x AS (SELECT rowid r FROM llx_const) SELECT r FROM x', 'SQL_FORBIDDEN_TABLE'],
            'denied table union'     => ['SELECT rowid FROM llx_societe UNION SELECT rowid FROM llx_const', 'SQL_FORBIDDEN_TABLE'],
            'denied table backtick'  => ['SELECT rowid FROM `llx_const`', 'SQL_FORBIDDEN_TABLE'],
            'denied table mixedcase' => ['SELECT rowid FROM LLX_Const', 'SQL_FORBIDDEN_TABLE'],

            // --- database-qualified tables ---
            //
            // Keeping only the terminal segment made "other_db.llx_societe"
            // pass the policy and read another Dolibarr sharing the server.
            // Qualification is refused outright rather than trusting MySQL
            // grants, which the module does not control.
            'cross database'          => ['SELECT rowid FROM other_db.llx_societe', 'SQL_QUALIFIED_TABLE'],
            'cross database denied'   => ['SELECT rowid FROM other_db.llx_const', 'SQL_QUALIFIED_TABLE'],
            'own database qualified'  => ['SELECT rowid FROM gsedem.llx_societe', 'SQL_QUALIFIED_TABLE'],
            'qualified backtick'      => ['SELECT rowid FROM `other_db`.`llx_societe`', 'SQL_QUALIFIED_TABLE'],
            'qualified in subquery'   => ['SELECT nom FROM llx_societe WHERE rowid IN (SELECT rowid FROM other_db.llx_facture)', 'SQL_QUALIFIED_TABLE'],
            'qualified in join'       => ['SELECT s.nom FROM llx_societe s JOIN other_db.llx_facture f ON f.fk_soc = s.rowid', 'SQL_QUALIFIED_TABLE'],
            'qualified in union'      => ['SELECT rowid FROM llx_facture UNION SELECT rowid FROM other_db.llx_facture', 'SQL_QUALIFIED_TABLE'],
            'fully qualified column'  => ['SELECT other_db.llx_user.login FROM llx_societe', 'SQL_QUALIFIED_COLUMN'],
            'denied table join'      => ['SELECT s.nom FROM llx_societe s JOIN llx_session x ON x.rowid = s.rowid', 'SQL_FORBIDDEN_TABLE'],
            'oauth token table'      => ['SELECT token_hash FROM llx_emmcp_oauth_token', 'SQL_FORBIDDEN_TABLE'],
            'unprefixed table'       => ['SELECT rowid FROM societe', 'SQL_FORBIDDEN_TABLE'],
            // Caught by the qualification rule before the table denylist is
            // even consulted — a stricter path to the same refusal.
            'information_schema'     => ['SELECT table_name FROM information_schema.tables', 'SQL_QUALIFIED_TABLE'],
            'mysql user table'       => ['SELECT user FROM mysql.user', 'SQL_QUALIFIED_TABLE'],

            // --- denied columns, reached through every route ---
            'denied column'          => ['SELECT api_key FROM llx_user', 'SQL_FORBIDDEN_COLUMN'],
            'denied column aliased'  => ['SELECT u.api_key FROM llx_user u', 'SQL_FORBIDDEN_COLUMN'],
            'denied column backtick' => ['SELECT `api_key` FROM llx_user', 'SQL_FORBIDDEN_COLUMN'],
            'denied column where'    => ["SELECT rowid FROM llx_user WHERE pass_crypted = 'x'", 'SQL_FORBIDDEN_COLUMN'],
            'denied column cte'      => ['WITH x AS (SELECT api_key k FROM llx_user) SELECT k FROM x', 'SQL_FORBIDDEN_COLUMN'],
            'denied column order'    => ['SELECT rowid FROM llx_user ORDER BY api_key', 'SQL_FORBIDDEN_COLUMN'],
            'denied column union'    => ['SELECT nom FROM llx_societe UNION SELECT api_key FROM llx_user', 'SQL_FORBIDDEN_COLUMN'],

            // --- star projection, refused everywhere ---
            //
            // Restricting this to a known list of sensitive tables only
            // protected what we had thought of: a third-party module table
            // such as llx_x_config holding a secret was fully readable with
            // SELECT *. The projection is refused outright now, and the agent
            // is told to name its columns using dolibarr_sql_schema.
            'star sensitive'         => ['SELECT * FROM llx_user', 'SQL_STAR_NOT_ALLOWED'],
            'star qualified'         => ['SELECT u.* FROM llx_user u', 'SQL_STAR_NOT_ALLOWED'],
            'star join sensitive'    => ['SELECT * FROM llx_facture f JOIN llx_user u ON u.rowid = f.fk_user_author', 'SQL_STAR_NOT_ALLOWED'],
            'star in subquery'       => ['SELECT nom FROM llx_societe WHERE rowid IN (SELECT * FROM llx_user)', 'SQL_STAR_NOT_ALLOWED'],
            'star on plain table'    => ['SELECT * FROM llx_facture', 'SQL_STAR_NOT_ALLOWED'],
            'star qualified plain'   => ['SELECT f.* FROM llx_facture f', 'SQL_STAR_NOT_ALLOWED'],
            'star third party table' => ['SELECT * FROM llx_x_config', 'SQL_STAR_NOT_ALLOWED'],
            'star in cte'            => ['WITH x AS (SELECT * FROM llx_facture) SELECT ref FROM x', 'SQL_STAR_NOT_ALLOWED'],
            'star among columns'     => ['SELECT rowid, * FROM llx_facture', 'SQL_STAR_NOT_ALLOWED'],

            // --- denied functions ---
            'sleep'     => ['SELECT SLEEP(5)', 'SQL_FORBIDDEN_FUNCTION'],
            'sleep join'=> ['SELECT nom FROM llx_societe WHERE SLEEP(5) = 0', 'SQL_FORBIDDEN_FUNCTION'],
            'benchmark' => ["SELECT BENCHMARK(1000000, MD5('x'))", 'SQL_FORBIDDEN_FUNCTION'],
            'load_file' => ["SELECT LOAD_FILE('/etc/passwd')", 'SQL_FORBIDDEN_FUNCTION'],
            'get_lock'  => ["SELECT GET_LOCK('a', 10)", 'SQL_FORBIDDEN_FUNCTION'],

            // --- server variables leak configuration ---
            'system variable'  => ['SELECT @@datadir', 'SQL_FORBIDDEN_VARIABLE'],
            'version variable' => ['SELECT @@version', 'SQL_FORBIDDEN_VARIABLE'],
            'user variable'    => ['SELECT @x FROM llx_societe', 'SQL_FORBIDDEN_VARIABLE'],

            // --- lexer rejections must propagate unchanged ---
            'multi statement'    => ['SELECT 1; DROP TABLE llx_societe', 'SQL_MULTI_STATEMENT'],
            'executable comment' => ['SELECT 1 /*!32302 SELECT api_key FROM llx_user */', 'SQL_EXECUTABLE_COMMENT'],
        ];
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testAccepts(string $sql): void
    {
        $out = $this->validator->validate($sql);
        $this->assertNotSame('', trim($out));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'simple'          => ['SELECT rowid, nom FROM llx_societe'],
            'join'            => ['SELECT f.ref, s.nom FROM llx_facture f JOIN llx_societe s ON s.rowid = f.fk_soc'],
            'user join'       => ['SELECT u.login, COUNT(*) n FROM llx_facture f JOIN llx_user u ON u.rowid = f.fk_user_author GROUP BY u.login'],
            'cte'             => ['WITH ca AS (SELECT fk_soc, SUM(total_ht) t FROM llx_facture GROUP BY fk_soc) SELECT s.nom, ca.t FROM ca JOIN llx_societe s ON s.rowid = ca.fk_soc'],
            'cte recursive'   => ['WITH RECURSIVE n AS (SELECT 1 AS x UNION ALL SELECT x+1 FROM n WHERE x < 5) SELECT x FROM n'],
            'union'           => ['SELECT rowid FROM llx_facture UNION SELECT rowid FROM llx_commande'],
            'union all'       => ['SELECT rowid FROM llx_facture UNION ALL SELECT rowid FROM llx_commande'],
            'subquery'        => ['SELECT nom FROM llx_societe WHERE rowid IN (SELECT fk_soc FROM llx_facture)'],
            'having'          => ['SELECT fk_soc, SUM(total_ht) t FROM llx_facture GROUP BY fk_soc HAVING SUM(total_ht) > 1000'],
            'count star'      => ['SELECT COUNT(*) FROM llx_facture'],
            'count star alias' => ['SELECT COUNT(*) AS n FROM llx_facture'],
            'count star mixed' => ['SELECT fk_soc, COUNT(*) n FROM llx_facture GROUP BY fk_soc'],
            // DISTINCT must survive the OPTIONS removal: the parser does not
            // file it under that section.
            'distinct'         => ['SELECT DISTINCT nom FROM llx_societe'],
            'count distinct'   => ['SELECT COUNT(DISTINCT fk_soc) FROM llx_facture'],
            'keyword alias'   => ['SELECT date_creation AS `create` FROM llx_facture'],
            'literal keyword' => ["SELECT nom FROM llx_societe WHERE nom = 'DROP SA'"],
            // The locking-clause check runs on text, so these prove it reads
            // the masked statement and not the raw one.
            'lock words in literal'  => ["SELECT nom FROM llx_societe WHERE nom = 'FOR UPDATE'"],
            'lock words in comment'  => ['SELECT nom FROM llx_societe /* FOR SHARE */'],
            'lock words in ident'    => ['SELECT `lock in share mode` FROM llx_facture'],
            'literal semicol' => ["SELECT nom FROM llx_societe WHERE nom = 'a;b'"],
            'third party mod' => ['SELECT rowid FROM llx_mymodule_data'],
            'user safe cols'  => ['SELECT rowid, login, lastname FROM llx_user'],
            'left join'       => ['SELECT s.nom, f.ref FROM llx_societe s LEFT JOIN llx_facture f ON f.fk_soc = s.rowid'],
            'date function'   => ["SELECT DATE_FORMAT(datec, '%Y-%m') m, COUNT(*) c FROM llx_facture GROUP BY m"],
        ];
    }

    /**
     * COUNT(*) carries a "*" argument that is not a projection. Treating it as
     * one would refuse the single most common reporting query.
     */
    public function testCountStarIsNotAProjectionStar(): void
    {
        $out = $this->validator->validate('SELECT COUNT(*) FROM llx_user');
        $this->assertStringContainsStringIgnoringCase('count', $out);
    }

    public function testCountStarOnJoinWithSensitiveTable(): void
    {
        $out = $this->validator->validate(
            'SELECT u.login, COUNT(*) c FROM llx_facture f JOIN llx_user u ON u.rowid = f.fk_user_author GROUP BY u.login'
        );
        $this->assertStringContainsStringIgnoringCase('count', $out);
    }

    /**
     * Shadowing a denied table with a CTE was accepted on the grounds that
     * MySQL resolves the name to the CTE, so nothing leaked. That reasoning
     * only held for the trivial case: chaining a second CTE lets the first one
     * read the real table and the shadow hide it. The name is refused instead.
     */
    public function testCteMayNotShadowADeniedTableName(): void
    {
        try {
            $this->validator->validate('WITH llx_const AS (SELECT 1 AS a) SELECT a FROM llx_const');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_CTE_NAME_RESERVED', $e->code());
        }
    }

    public function testStarIsRefusedEvenOnACte(): void
    {
        try {
            $this->validator->validate('WITH x AS (SELECT 1 AS a) SELECT * FROM x');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_STAR_NOT_ALLOWED', $e->code());
        }
    }

    /**
     * COUNT(*) is the one place a star is not a projection, and refusing it
     * would break the most common reporting query there is.
     */
    public function testCountStarSurvivesTheStarBan(): void
    {
        $out = $this->validator->validate('SELECT COUNT(*) FROM llx_user');
        $this->assertStringContainsStringIgnoringCase('count', $out);
    }

    /**
     * A star inside any other function is not a legitimate aggregate form, so
     * it is refused rather than waved through by the COUNT exemption.
     */
    public function testStarInsideAnotherFunctionIsRefused(): void
    {
        try {
            $this->validator->validate('SELECT SUM(*) FROM llx_facture');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_STAR_NOT_ALLOWED', $e->code());
        }
    }

    /**
     * A CTE may not borrow a physical table name. Otherwise the CTE exemption,
     * which exists so `WITH x AS (…) SELECT … FROM x` does not look like an
     * unknown table, becomes a way to launder a denied one.
     */
    /**
     * A parenthesised SELECT is legitimate SQL and stays supported, so the
     * BRACKET fix is a recursive check rather than a blanket refusal.
     */
    public function testBracketedSelectIsStillAccepted(): void
    {
        $out = $this->validator->validate('(SELECT rowid, nom FROM llx_societe)');
        $this->assertStringContainsStringIgnoringCase('select', $out);
    }

    public function testBracketedUnionIsStillAccepted(): void
    {
        $out = $this->validator->validate('(SELECT rowid FROM llx_facture) UNION (SELECT rowid FROM llx_commande)');
        $this->assertStringContainsStringIgnoringCase('union', $out);
    }

    /**
     * The policy must see inside brackets too, not just the section whitelist:
     * a denied table or column hidden there would otherwise be invisible.
     */
    public function testPolicyDescendsIntoBrackets(): void
    {
        foreach ([
            '(SELECT rowid FROM llx_const)' => 'SQL_FORBIDDEN_TABLE',
            '(SELECT api_key FROM llx_user)' => 'SQL_FORBIDDEN_COLUMN',
            '(SELECT * FROM llx_facture)' => 'SQL_STAR_NOT_ALLOWED',
            '(SELECT rowid FROM other_db.llx_societe)' => 'SQL_QUALIFIED_TABLE',
            '(SELECT SLEEP(5))' => 'SQL_FORBIDDEN_FUNCTION',
        ] as $sql => $expected) {
            try {
                $this->validator->validate($sql);
                $this->fail('Expected rejection for: ' . $sql);
            } catch (SqlValidationException $e) {
                $this->assertSame($expected, $e->code(), $sql);
            }
        }
    }

    public function testCteMayNotShadowATableName(): void
    {
        $attack = 'WITH x AS (SELECT name, value FROM llx_const), '
            . 'llx_const AS (SELECT name, value FROM x) '
            . 'SELECT name, value FROM llx_const';

        try {
            $this->validator->validate($attack);
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertContains($e->code(), ['SQL_CTE_NAME_RESERVED', 'SQL_FORBIDDEN_TABLE'], $e->code());
        }
    }

    public function testCteNameMayNotUseTheTablePrefix(): void
    {
        try {
            $this->validator->validate('WITH llx_report AS (SELECT 1 AS a) SELECT a FROM llx_report');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_CTE_NAME_RESERVED', $e->code());
        }
    }

    public function testOrdinaryCteNamesStillWork(): void
    {
        $out = $this->validator->validate(
            'WITH report_ca AS (SELECT fk_soc, SUM(total_ht) t FROM llx_facture GROUP BY fk_soc) '
            . 'SELECT fk_soc, t FROM report_ca'
        );
        $this->assertStringContainsStringIgnoringCase('report_ca', $out);
    }

    /**
     * Qualified function names would reach a UDF in another database, so the
     * check cannot be a plain comparison against the denylist.
     */
    public function testQualifiedFunctionIsRefused(): void
    {
        try {
            $this->validator->validate('SELECT otherdb.evil_udf(1) FROM llx_societe');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_FORBIDDEN_FUNCTION', $e->code());
        }
    }

    public function testInjectsLimitWhenAbsent(): void
    {
        $out = $this->validator->validate('SELECT rowid FROM llx_societe');
        $this->assertMatchesRegularExpression('/LIMIT\s+200$/i', trim($out));
    }

    public function testClampsExistingLimitAboveMaximum(): void
    {
        $out = $this->validator->validate('SELECT rowid FROM llx_societe LIMIT 999999');
        $this->assertMatchesRegularExpression('/LIMIT\s+200$/i', trim($out));
        $this->assertStringNotContainsString('999999', $out);
    }

    public function testKeepsSmallerExistingLimit(): void
    {
        $out = $this->validator->validate('SELECT rowid FROM llx_societe LIMIT 10');
        $this->assertMatchesRegularExpression('/LIMIT\s+10$/i', trim($out));
    }

    public function testPreservesOffsetWhenClamping(): void
    {
        $out = $this->validator->validate('SELECT rowid FROM llx_societe LIMIT 50, 999999');
        $this->assertMatchesRegularExpression('/LIMIT\s+50\s*,\s*200$/i', trim($out));
    }

    /**
     * The limit is appended, so a trailing line comment used to swallow it:
     * "… -- trailing LIMIT 200" runs unbounded. The effective clause must end
     * up on its own line.
     *
     * @dataProvider trailingCommentProvider
     */
    public function testLimitSurvivesATrailingComment(string $sql): void
    {
        $out = $this->validator->validate($sql);

        $lines = preg_split('/\R/', trim($out));
        $lastLine = trim((string) end($lines));

        $this->assertMatchesRegularExpression(
            '/^LIMIT\s+\d+(\s*,\s*\d+)?$/i',
            $lastLine,
            'the LIMIT clause must not sit behind a line comment: ' . $out
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function trailingCommentProvider(): array
    {
        return [
            'dash comment'       => ['SELECT rowid FROM llx_societe -- trailing'],
            'hash comment'       => ['SELECT rowid FROM llx_societe # trailing'],
            'dash then spaces'   => ['SELECT rowid FROM llx_societe -- trailing   '],
            'comment after limit' => ['SELECT rowid FROM llx_societe LIMIT 5 -- trailing'],
            'hash after limit'   => ['SELECT rowid FROM llx_societe LIMIT 5 # trailing'],
        ];
    }

    /**
     * A caller-supplied limit may only ever be lowered. LIMIT 0 legitimately
     * asks for no rows; rewriting it to the maximum would return 200 rows the
     * caller did not ask for.
     *
     * @dataProvider limitPreservationProvider
     */
    public function testLimitIsNeverRaised(string $sql, int $expectedRowcount): void
    {
        $out = $this->validator->validate($sql);

        $this->assertSame(
            1,
            preg_match('/\bLIMIT\s+(?:(\d+)\s*,\s*)?(\d+)\s*$/i', trim($out), $m),
            'no parsable LIMIT in: ' . $out
        );
        $this->assertSame($expectedRowcount, (int) $m[2], $out);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function limitPreservationProvider(): array
    {
        return [
            'limit zero'        => ['SELECT rowid FROM llx_societe LIMIT 0', 0],
            'limit zero comma'  => ['SELECT rowid FROM llx_societe LIMIT 0,0', 0],
            'offset then zero'  => ['SELECT rowid FROM llx_societe LIMIT 5,0', 0],
            'limit one'         => ['SELECT rowid FROM llx_societe LIMIT 1', 1],
            'offset keyword'    => ['SELECT rowid FROM llx_societe LIMIT 10 OFFSET 5', 10],
            'offset keyword big' => ['SELECT rowid FROM llx_societe LIMIT 999999 OFFSET 5', 200],
            'no limit'          => ['SELECT rowid FROM llx_societe', 200],
        ];
    }

    public function testOffsetKeywordFormIsClampedAndKeepsOffset(): void
    {
        $out = trim($this->validator->validate('SELECT rowid FROM llx_societe LIMIT 999999 OFFSET 42'));

        $this->assertSame(1, preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i', $out, $m), $out);
        $this->assertSame(42, (int) $m[1], 'offset must be preserved: ' . $out);
        $this->assertSame(200, (int) $m[2], $out);
    }

    /**
     * Only one LIMIT may survive; a leftover clause would make the final
     * statement invalid or ambiguous.
     */
    public function testExactlyOneLimitClauseRemains(): void
    {
        foreach ([
            'SELECT rowid FROM llx_societe',
            'SELECT rowid FROM llx_societe LIMIT 999999',
            'SELECT rowid FROM llx_societe LIMIT 10 OFFSET 5',
            'SELECT rowid FROM llx_societe -- x',
        ] as $sql) {
            $out = $this->validator->validate($sql);
            $masked = (new \DolibarrMcp\Sql\SqlLexer())->maskLiteralsAndComments($out);
            $this->assertSame(1, preg_match_all('/\bLIMIT\b/i', $masked), 'in: ' . $out);
        }
    }

    public function testStripsTrailingSemicolon(): void
    {
        $out = $this->validator->validate('SELECT rowid FROM llx_societe;');
        $this->assertStringNotContainsString(';', $out);
    }

    /**
     * The fallback must not widen what reaches the policy.
     *
     * A malformed statement that trips the position pass is refused outright
     * unless it carries the one construct the fallback exists for, so nothing
     * new gets a second chance at being parsed loosely.
     */
    public function testMalformedSqlDoesNotGetTheFallback(): void
    {
        foreach ([
            'SELECT FROM WHERE',
            'GRANT SELECT ON *.* TO x',
        ] as $sql) {
            try {
                $this->validator->validate($sql);
                $this->fail('accepted: ' . $sql);
            } catch (SqlValidationException $e) {
                $this->assertSame('SQL_PARSE_ERROR', $e->code(), $sql);
            }
        }
    }

    /**
     * Type parameters containing a comma are valid SQL and must be accepted.
     *
     * The parser's position-calculation pass throws on them — it cannot locate
     * the comma inside DECIMAL(20,6) — so these queries used to come back as
     * SQL_PARSE_ERROR. Reported from a real session doing accounting maths.
     */
    public function testAcceptsCastWithScaleAndPrecision(): void
    {
        foreach ([
            'SELECT CAST(amount AS DECIMAL(20,6)) AS a FROM llx_bank',
            'SELECT SUM(CAST(amount AS DECIMAL(20,6))) AS total FROM llx_bank',
            'SELECT CONVERT(amount, DECIMAL(20,6)) AS a FROM llx_bank',
        ] as $sql) {
            $this->assertNotSame('', $this->validator->validate($sql), $sql);
        }
    }

    /**
     * The fallback must not become a way in: a write that also trips the
     * position pass still has to be refused.
     */
    public function testFallbackStillRefusesWrites(): void
    {
        foreach ([
            "UPDATE llx_societe SET nom = CAST(1 AS DECIMAL(20,6))",
            "INSERT INTO llx_societe (nom) VALUES (CAST(1 AS DECIMAL(20,6)))",
            "DROP TABLE llx_societe",
        ] as $sql) {
            try {
                $this->validator->validate($sql);
                $this->fail('accepted: ' . $sql);
            } catch (SqlValidationException $e) {
                $this->assertNotSame('', $e->code());
            }
        }
    }

    public function testRespectsCustomPolicyLimits(): void
    {
        $validator = new SqlReadOnlyValidator(new SqlPolicy('llx_', ['maxRows' => 25]));
        $out = $validator->validate('SELECT rowid FROM llx_societe');
        $this->assertMatchesRegularExpression('/LIMIT\s+25$/i', trim($out));
    }

    public function testEnforcesPolicySqlLength(): void
    {
        $validator = new SqlReadOnlyValidator(new SqlPolicy('llx_', ['maxSqlLength' => 64]));
        try {
            $validator->validate('SELECT ' . str_repeat('rowid, ', 20) . 'rowid FROM llx_societe');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_TOO_LONG', $e->code());
        }
    }
}
