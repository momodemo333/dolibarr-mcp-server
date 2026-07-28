<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Sql;

use DolibarrMcp\Sql\GrantInspector;
use DolibarrMcp\Sql\SqlValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Grant lines are taken verbatim from MariaDB 11.0 / MySQL 8 output.
 *
 * Note that the USAGE line carries the account's password hash, which is why
 * nothing here — and nothing in the caller — may log a grant line.
 */
class GrantInspectorTest extends TestCase
{
    private GrantInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new GrantInspector();
    }

    /**
     * @dataProvider acceptedProvider
     *
     * @param array<int, string> $grants
     */
    public function testAccepts(array $grants): void
    {
        $this->inspector->assertReadOnly($grants, 'gsedem');
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: array<int, string>}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'usage plus select on database' => [[
                "GRANT USAGE ON *.* TO `ro`@`%` IDENTIFIED BY PASSWORD '*B69027D44F6E5EDC07F1AEAD1477967B16F28227'",
                'GRANT SELECT ON `gsedem`.* TO `ro`@`%`',
            ]],
            'unquoted database' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SELECT ON gsedem.* TO `ro`@`%`',
            ]],
            'select on a single table' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SELECT ON `gsedem`.`llx_societe` TO `ro`@`%`',
            ]],
            'usage with tls requirement' => [[
                'GRANT USAGE ON *.* TO `ro`@`%` REQUIRE SSL',
                'GRANT SELECT ON `gsedem`.* TO `ro`@`%`',
            ]],
            'usage with resource limits' => [[
                'GRANT USAGE ON *.* TO `ro`@`%` WITH MAX_QUERIES_PER_HOUR 1000 MAX_USER_CONNECTIONS 5',
                'GRANT SELECT ON `gsedem`.* TO `ro`@`%`',
            ]],
            'mysql8 auth clause' => [[
                "GRANT USAGE ON *.* TO `ro`@`%` IDENTIFIED WITH 'caching_sha2_password' AS '\$A\$005\$abc'",
                'GRANT SELECT ON `gsedem`.* TO `ro`@`%`',
            ]],
            'lowercase keywords' => [[
                'grant usage on *.* to `ro`@`%`',
                'grant select on `gsedem`.* to `ro`@`%`',
            ]],
        ];
    }

    /**
     * @dataProvider refusedProvider
     *
     * @param array<int, string> $grants
     */
    public function testRefuses(array $grants, string $why): void
    {
        try {
            $this->inspector->assertReadOnly($grants, 'gsedem');
            $this->fail('Expected rejection: ' . $why);
        } catch (SqlValidationException $e) {
            $this->assertSame('SQL_ACCOUNT_NOT_READ_ONLY', $e->code(), $why);
        }
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'write privilege alongside select' => [[
                'GRANT USAGE ON *.* TO `rw`@`%`',
                'GRANT SELECT, INSERT ON `gsedem`.* TO `rw`@`%`',
            ], 'INSERT must not be granted'],
            'global select' => [[
                'GRANT SELECT ON *.* TO `g`@`%`',
            ], 'SELECT on *.* exposes every database'],
            'select on another database' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SELECT ON `otherdb`.* TO `ro`@`%`',
            ], 'reading another database'],
            'all privileges' => [[
                'GRANT ALL PRIVILEGES ON `gsedem`.* TO `x`@`%`',
            ], 'ALL PRIVILEGES'],
            'grant option' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SELECT ON `gsedem`.* TO `ro`@`%` WITH GRANT OPTION',
            ], 'GRANT OPTION lets the account widen itself'],
            'file privilege' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT FILE ON *.* TO `ro`@`%`',
            ], 'FILE reads server files'],
            'execute privilege' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SELECT, EXECUTE ON `gsedem`.* TO `ro`@`%`',
            ], 'EXECUTE runs routines'],
            'create privilege' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SELECT, CREATE ON `gsedem`.* TO `ro`@`%`',
            ], 'DDL'],
            'super privilege' => [[
                'GRANT SUPER ON *.* TO `ro`@`%`',
            ], 'admin privilege'],
            'dynamic privilege' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT SYSTEM_VARIABLES_ADMIN ON *.* TO `ro`@`%`',
            ], 'MySQL 8 dynamic privilege'],
            'role granted' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT `reporting_role` TO `ro`@`%`',
            ], 'a role can carry anything'],
            'proxy' => [[
                'GRANT PROXY ON `root`@`localhost` TO `ro`@`%`',
            ], 'PROXY impersonates another account'],
            'routine scope' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'GRANT EXECUTE ON PROCEDURE `gsedem`.`p` TO `ro`@`%`',
            ], 'routine-level grant'],
            'unparsable line' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
                'something entirely unexpected',
            ], 'fail closed on anything not understood'],
            'no grants at all' => [[], 'an empty grant list proves nothing'],
            'only usage' => [[
                'GRANT USAGE ON *.* TO `ro`@`%`',
            ], 'USAGE alone cannot even read'],
        ];
    }

    /**
     * The account may legitimately hold SELECT on the current database through
     * several lines; what matters is that no line grants anything else.
     */
    public function testSeveralSelectLinesOnTheSameDatabase(): void
    {
        $this->inspector->assertReadOnly([
            'GRANT USAGE ON *.* TO `ro`@`%`',
            'GRANT SELECT ON `gsedem`.`llx_societe` TO `ro`@`%`',
            'GRANT SELECT ON `gsedem`.`llx_facture` TO `ro`@`%`',
        ], 'gsedem');
        $this->addToAssertionCount(1);
    }

    /**
     * The message reaches an administrator and the server log, and grant lines
     * carry password hashes, so no line may appear in it.
     */
    public function testMessageNeverEchoesTheGrantLine(): void
    {
        $secretish = "GRANT USAGE ON *.* TO `ro`@`%` IDENTIFIED BY PASSWORD '*DEADBEEFCAFE'";

        try {
            $this->inspector->assertReadOnly([$secretish, 'GRANT ALL PRIVILEGES ON `gsedem`.* TO `ro`@`%`'], 'gsedem');
            $this->fail('Expected rejection');
        } catch (SqlValidationException $e) {
            $this->assertStringNotContainsString('DEADBEEFCAFE', $e->getMessage());
            $this->assertStringNotContainsString('IDENTIFIED', $e->getMessage());
        }
    }
}
