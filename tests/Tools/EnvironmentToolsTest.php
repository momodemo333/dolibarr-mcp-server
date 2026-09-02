<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Config\EnvironmentInfo;
use DolibarrMcp\Config\Version;
use DolibarrMcp\Tools\EnvironmentTools;
use PHPUnit\Framework\TestCase;

class EnvironmentToolsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'tool did not return JSON');

        return $decoded;
    }

    public function testReportsWhatTheHostSupplied(): void
    {
        $info = new EnvironmentInfo(
            dolibarrVersion: '21.0.1',
            hostModule: 'dalfred',
            hostVersion: '2.30.0',
            enabledModules: ['facture', 'societe', 'projet'],
            entity: 1,
            multicompany: false,
            capabilities: ['readonly_sql' => true],
        );

        $out = $this->decode((new EnvironmentTools($info))->describeEnvironment());

        $this->assertTrue($out['success']);
        $this->assertSame('21.0.1', $out['dolibarr_version']);
        $this->assertSame('dalfred', $out['host_module']['name']);
        $this->assertSame('2.30.0', $out['host_module']['version']);
        $this->assertSame(['facture', 'societe', 'projet'], $out['enabled_modules']);
        $this->assertSame(1, $out['entity']);
        $this->assertFalse($out['multicompany']);
        $this->assertTrue($out['capabilities']['readonly_sql']);
    }

    public function testReportsTheRunningVersions(): void
    {
        $out = $this->decode((new EnvironmentTools(new EnvironmentInfo()))->describeEnvironment());

        // PHP's own version needs no host to be known, and the server version
        // must match what initialize() advertises — they share one constant.
        $this->assertSame(PHP_VERSION, $out['php_version']);
        $this->assertSame(Version::SERVER, $out['mcp_server_version']);
    }

    /**
     * A host that supplies nothing must still get a usable answer: the tool is
     * meant to remove guesswork, so failing here would defeat its purpose.
     */
    public function testAnswersEvenWithoutHostInformation(): void
    {
        $out = $this->decode((new EnvironmentTools())->describeEnvironment());

        $this->assertTrue($out['success']);
        $this->assertNull($out['dolibarr_version']);
        $this->assertArrayNotHasKey('host_module', $out);
        $this->assertArrayNotHasKey('enabled_modules', $out);
    }

    public function testOmitsEmptyModuleListRatherThanReturningAnEmptyArray(): void
    {
        $info = new EnvironmentInfo(dolibarrVersion: '18.0.5', enabledModules: []);
        $out = $this->decode((new EnvironmentTools($info))->describeEnvironment());

        $this->assertSame('18.0.5', $out['dolibarr_version']);
        $this->assertArrayNotHasKey('enabled_modules', $out);
    }
}
