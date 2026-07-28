<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Sql\SqlCapabilityInterface;
use DolibarrMcp\Sql\SqlExecutionResult;
use DolibarrMcp\Sql\SqlPolicy;
use DolibarrMcp\Tools\Gated\SqlTools;
use PHPUnit\Framework\TestCase;

class SqlToolsTest extends TestCase
{
    public function testQueryRefusesWithoutCapability(): void
    {
        $out = $this->decode((new SqlTools(null))->queryDatabase('SELECT rowid FROM llx_societe'));

        $this->assertFalse($out['success']);
        $this->assertSame('SQL_CAPABILITY_UNAVAILABLE', $out['code']);
    }

    public function testSchemaRefusesWithoutCapability(): void
    {
        $out = $this->decode((new SqlTools(null))->describeDatabaseSchema());

        $this->assertFalse($out['success']);
        $this->assertSame('SQL_CAPABILITY_UNAVAILABLE', $out['code']);
    }

    public function testQueryReturnsRowsOnSuccess(): void
    {
        $capability = $this->capability();
        $capability->expects($this->once())
            ->method('runSelect')
            ->with($this->stringContains('LIMIT'))
            ->willReturn(new SqlExecutionResult(['nom'], [['nom' => 'ACME']], 1, false, 12, 40));

        $out = $this->decode((new SqlTools($capability))->queryDatabase('SELECT nom FROM llx_societe'));

        $this->assertTrue($out['success']);
        $this->assertSame([['nom' => 'ACME']], $out['rows']);
        $this->assertSame(['nom'], $out['columns']);
        $this->assertSame(1, $out['row_count']);
        $this->assertFalse($out['truncated']);
    }

    public function testQueryReportsTruncation(): void
    {
        $capability = $this->capability();
        $capability->method('runSelect')
            ->willReturn(new SqlExecutionResult(['a'], [['a' => 1]], 1, true, 5, 10));

        $out = $this->decode((new SqlTools($capability))->queryDatabase('SELECT a FROM llx_societe'));

        $this->assertTrue($out['truncated']);
        $this->assertArrayHasKey('notice', $out);
    }

    /**
     * The capability must never be reached when validation fails: the guarantee
     * is that nothing invalid is handed to the database layer at all.
     */
    public function testCapabilityIsNotReachedWhenValidationFails(): void
    {
        $capability = $this->capability();
        $capability->expects($this->never())->method('runSelect');

        $out = $this->decode((new SqlTools($capability))->queryDatabase('DELETE FROM llx_societe'));

        $this->assertFalse($out['success']);
        $this->assertSame('SQL_NOT_READ_ONLY', $out['code']);
    }

    public function testValidationCodesReachTheCaller(): void
    {
        $cases = [
            'SELECT api_key FROM llx_user' => 'SQL_FORBIDDEN_COLUMN',
            'SELECT rowid FROM llx_const' => 'SQL_FORBIDDEN_TABLE',
            'SELECT * FROM llx_user' => 'SQL_STAR_NOT_ALLOWED',
            'SELECT rowid FROM other_db.llx_societe' => 'SQL_QUALIFIED_TABLE',
            'SELECT /*+ MAX_EXECUTION_TIME(0) */ nom FROM llx_societe' => 'SQL_OPTIMIZER_HINT',
            'SELECT smtp_password FROM llx_x_config' => 'SQL_FORBIDDEN_COLUMN',
            'SELECT SLEEP(5)' => 'SQL_FORBIDDEN_FUNCTION',
            'SELECT 1; DROP TABLE llx_societe' => 'SQL_MULTI_STATEMENT',
        ];

        foreach ($cases as $sql => $expected) {
            $out = $this->decode((new SqlTools($this->capability()))->queryDatabase($sql));
            $this->assertSame($expected, $out['code'], $sql);
        }
    }

    /**
     * Driver messages routinely carry host names and credentials. None of that
     * may reach the model, which relays it to the end user.
     */
    public function testExecutionErrorIsNotLeakedVerbatim(): void
    {
        $capability = $this->capability();
        $capability->method('runSelect')
            ->willThrowException(new \RuntimeException("Access denied for user 'root'@'db-01' (using password: YES)"));

        $raw = (new SqlTools($capability))->queryDatabase('SELECT nom FROM llx_societe');
        $out = $this->decode($raw);

        $this->assertFalse($out['success']);
        $this->assertSame('SQL_EXECUTION_ERROR', $out['code']);
        $this->assertStringNotContainsString('password', strtolower($raw));
        $this->assertStringNotContainsString('root', strtolower($raw));
        $this->assertStringNotContainsString('db-01', strtolower($raw));
    }

    public function testSchemaReturnsTables(): void
    {
        $capability = $this->capability();
        $capability->expects($this->once())
            ->method('describeSchema')
            ->with('llx_facture')
            ->willReturn([
                'tables' => [
                    'llx_facture' => [
                        ['name' => 'rowid', 'type' => 'int(11)', 'nullable' => false, 'key' => 'PRI'],
                    ],
                ],
                'truncated' => false,
            ]);

        $out = $this->decode((new SqlTools($capability))->describeDatabaseSchema('llx_facture'));

        $this->assertTrue($out['success']);
        $this->assertArrayHasKey('llx_facture', $out['tables']);
    }

    /**
     * A refused query never reaches the database, so without this the trail
     * would only ever show what succeeded — the opposite of what an
     * administrator investigating an incident needs.
     */
    public function testRefusalsAreAudited(): void
    {
        $capability = $this->capability();
        $capability->expects($this->once())
            ->method('auditRefusal')
            ->with('DELETE FROM llx_societe', 'SQL_NOT_READ_ONLY', 'query');

        (new SqlTools($capability))->queryDatabase('DELETE FROM llx_societe');
    }

    /**
     * The caller is already being refused; an audit failure must not replace
     * the reason with a different error.
     */
    public function testAuditFailureDoesNotMaskTheRefusal(): void
    {
        $capability = $this->capability();
        $capability->method('auditRefusal')->willThrowException(new \RuntimeException('audit down'));

        $out = $this->decode((new SqlTools($capability))->queryDatabase('DELETE FROM llx_societe'));

        $this->assertSame('SQL_NOT_READ_ONLY', $out['code']);
    }

    public function testSchemaAccessIsAudited(): void
    {
        $capability = $this->capability();
        $capability->method('describeSchema')->willReturn(['tables' => ['llx_facture' => []], 'truncated' => false]);
        $capability->expects($this->once())
            ->method('auditSchemaAccess')
            ->with('llx_facture', 1, $this->isType('int'));

        (new SqlTools($capability))->describeDatabaseSchema('llx_facture');
    }

    public function testSchemaErrorIsNotLeakedVerbatim(): void
    {
        $capability = $this->capability();
        $capability->method('describeSchema')
            ->willThrowException(new \RuntimeException("Unknown database 'secret_db'"));

        $raw = (new SqlTools($capability))->describeDatabaseSchema();

        $this->assertStringNotContainsString('secret_db', $raw);
        $this->assertSame('SQL_EXECUTION_ERROR', $this->decode($raw)['code']);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&SqlCapabilityInterface
     */
    private function capability()
    {
        $capability = $this->createMock(SqlCapabilityInterface::class);
        $capability->method('getPolicy')->willReturn(new SqlPolicy());

        return $capability;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'tool must return a JSON object');

        return $decoded;
    }
}
