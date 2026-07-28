<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Sql;

use DolibarrMcp\Sql\SqlLexer;
use DolibarrMcp\Sql\SqlValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The lexer is the fail-closed first pass: it works on raw text, before any
 * parsing, and its job is to make the SQL string safe to hand to a parser.
 *
 * Two behaviours of greenlion/php-sql-parser make this pass mandatory:
 *  - "SELECT 1; DROP TABLE x" is silently merged into sections [SELECT, DROP]
 *  - "/*!32302 ... *\/" content is silently dropped by the parser while MySQL
 *    executes it — the exact bypass that affects Dalfred's current toolkit.
 */
class SqlLexerTest extends TestCase
{
    private SqlLexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new SqlLexer();
    }

    /**
     * @dataProvider rejectedProvider
     */
    public function testRejects(string $sql, string $expectedCode): void
    {
        try {
            $this->lexer->assertSingleReadOnlyStatement($sql);
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
            'multi statement'         => ['SELECT 1; DROP TABLE llx_societe', 'SQL_MULTI_STATEMENT'],
            'multi with newline'      => ["SELECT 1;\nDELETE FROM llx_societe", 'SQL_MULTI_STATEMENT'],
            'multi after comment'     => ["SELECT 1; -- x\nDROP TABLE llx_societe", 'SQL_MULTI_STATEMENT'],
            'multi empty then stmt'   => ['SELECT 1;;DROP TABLE llx_societe', 'SQL_MULTI_STATEMENT'],
            'bang comment'            => ['SELECT 1 /*!32302 , (SELECT api_key FROM llx_user) */', 'SQL_EXECUTABLE_COMMENT'],
            'bang comment bare'       => ['SELECT /*! 1 */', 'SQL_EXECUTABLE_COMMENT'],
            'bang comment nested'     => ['SELECT 1 /* x /*! y */', 'SQL_EXECUTABLE_COMMENT'],
            'bang comment no space'   => ['SELECT 1/*!*/', 'SQL_EXECUTABLE_COMMENT'],

            // Optimizer hints are honoured by the server and dropped by the
            // parser, so they can silently undo the very limits this pass and
            // the gateway install.
            'hint max_execution_time' => ['SELECT /*+ MAX_EXECUTION_TIME(0) */ nom FROM llx_societe', 'SQL_OPTIMIZER_HINT'],
            'hint set_var'            => ['SELECT /*+ SET_VAR(max_execution_time=0) */ nom FROM llx_societe', 'SQL_OPTIMIZER_HINT'],
            'hint bare'               => ['SELECT /*+*/ 1', 'SQL_OPTIMIZER_HINT'],
            'hint nested'             => ['SELECT 1 /* x /*+ y */', 'SQL_OPTIMIZER_HINT'],
            'hint after select'       => ['SELECT/*+ NO_RANGE_OPTIMIZATION(t) */ 1', 'SQL_OPTIMIZER_HINT'],

            // MariaDB honours its own /*M! ... */ form, which the parser drops
            // exactly like /*! — same divergence, different spelling.
            'mariadb comment'         => ['SELECT 1 /*M! , (SELECT api_key FROM llx_user) */', 'SQL_EXECUTABLE_COMMENT'],
            'mariadb comment lower'   => ['SELECT 1 /*m! , 2 */', 'SQL_EXECUTABLE_COMMENT'],
            'mariadb comment version' => ['SELECT 1 /*M!100000 , 2 */', 'SQL_EXECUTABLE_COMMENT'],
            'mariadb comment nested'  => ['SELECT 1 /* x /*M! y */', 'SQL_EXECUTABLE_COMMENT'],
            'unterminated block'      => ['SELECT 1 /* never closed', 'SQL_UNTERMINATED_COMMENT'],
            'unterminated string'     => ["SELECT 'abc", 'SQL_UNTERMINATED_STRING'],
            'unterminated dquote'     => ['SELECT "abc', 'SQL_UNTERMINATED_STRING'],
            'unterminated backtick'   => ['SELECT `abc', 'SQL_UNTERMINATED_IDENTIFIER'],
            'invalid utf8'            => ["SELECT \xC0\x80", 'SQL_INVALID_ENCODING'],
            'empty'                   => ['   ', 'SQL_EMPTY'],
            'only line comment'       => ['-- just a comment', 'SQL_EMPTY'],
            'only block comment'      => ['/* nothing here */', 'SQL_EMPTY'],
            'only semicolon'          => ['  ;  ', 'SQL_EMPTY'],
        ];
    }

    public function testRejectsOverlongStatement(): void
    {
        $lexer = new SqlLexer(50);
        try {
            $lexer->assertSingleReadOnlyStatement('SELECT ' . str_repeat('a', 100) . ' FROM llx_societe');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_TOO_LONG', $e->code());
        }
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testAccepts(string $sql): void
    {
        $this->lexer->assertSingleReadOnlyStatement($sql);
        $this->addToAssertionCount(1);
    }

    /**
     * Regression corpus: these are the false positives of Dalfred's current
     * regex-based validator. None of them may be rejected.
     *
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'semicolon in string'      => ["SELECT nom FROM llx_societe WHERE nom = 'a;b'"],
            'trailing semicolon'       => ['SELECT 1;'],
            'trailing semicolon space' => ["SELECT 1 ;   \n  "],
            'semicolon then comment'   => ['SELECT 1; -- done'],
            'semicolon then block cmt' => ['SELECT 1; /* done */'],
            'keyword in literal'       => ["SELECT nom FROM llx_societe WHERE nom = 'DROP SA'"],
            'escaped quote backslash'  => ["SELECT nom FROM llx_societe WHERE nom = 'O\\'Brien;x'"],
            'doubled single quote'     => ["SELECT nom FROM llx_societe WHERE nom = 'O''Brien;x'"],
            'doubled backtick'         => ['SELECT `we``ird` FROM llx_facture'],
            'backtick identifier'      => ['SELECT `create` FROM `llx_facture`'],
            'line comment hash'        => ["SELECT 1 # comment ; DROP\n"],
            'line comment dashes'      => ["SELECT 1 -- comment ; DROP\n"],
            'block comment'            => ['SELECT /* hello ; world */ 1'],
            // MySQL requires "/*+" with no gap; a spaced plus is an ordinary
            // comment and must not be caught by the hint rule.
            'plus inside comment'      => ['SELECT /* + not a hint */ 1'],
            'plus in expression'       => ['SELECT total_ht /* c */ + 1 FROM llx_facture'],
            // A bare M is an ordinary comment; only "M!" is the MariaDB form.
            'm without bang'           => ['SELECT /*M is just a letter */ 1'],
            'double quoted string'     => ['SELECT nom FROM llx_societe WHERE nom = "a;b"'],
            'hex literal'              => ['SELECT 0x414243 FROM llx_societe'],
            'cte'                      => ['WITH x AS (SELECT 1 a) SELECT a FROM x'],
            'division not comment'     => ['SELECT total_ht / 2 FROM llx_facture'],
            'accented literal'         => ["SELECT nom FROM llx_societe WHERE nom = 'Éléonore'"],
        ];
    }
}
