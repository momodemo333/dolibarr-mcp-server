<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Tools\FileGenerationTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileGenerationToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private FileGenerationTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools  = new FileGenerationTools($this->client);
    }

    public function testCreateFileSendsPostToCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'dalfred/generated_files/create',
                $this->callback(fn(array $d) =>
                    $d['filename'] === 'report'
                    && $d['format']   === 'csv'
                    && $d['content']  === "a,b\n1,2"
                )
            )
            ->willReturn([
                'success'      => true,
                'filename'     => 'report.csv',
                'size'         => 7,
                'download_url' => '/custom/dalfred/download.php?f=report.csv',
            ]);

        $raw = $this->tools->createFile('report', 'csv', "a,b\n1,2");
        $res = json_decode($raw, true);

        $this->assertTrue($res['success']);
        $this->assertSame('report.csv', $res['filename']);
        $this->assertSame('/custom/dalfred/download.php?f=report.csv', $res['download_url']);
        $this->assertStringContainsString('[report.csv]', $res['agent_hint']);
    }

    public function testCreateFileSurfacesApiErrorAsToolResult(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->willThrowException(new \RuntimeException('Dolibarr API error (403): FileGenerationDisabled'));

        $raw = $this->tools->createFile('x', 'txt', 'hello');
        $res = json_decode($raw, true);

        $this->assertFalse($res['success']);
        $this->assertSame('FileGenerationDisabled', $res['error']);
    }

    public function testCreateFilePropagatesUrlRootPrefixFromApi(): void
    {
        // When Dolibarr is installed under a subpath (e.g. /erp), the API
        // returns a download_url that already includes that prefix. The tool
        // must propagate it verbatim into the agent_hint Markdown link so the
        // browser resolves it against the right origin.
        $this->client->expects($this->once())
            ->method('post')
            ->willReturn([
                'success'      => true,
                'filename'     => 'report.csv',
                'size'         => 7,
                'download_url' => '/erp/custom/dalfred/download.php?f=report.csv',
            ]);

        $raw = $this->tools->createFile('report', 'csv', "a,b\n1,2");
        $res = json_decode($raw, true);

        $this->assertSame('/erp/custom/dalfred/download.php?f=report.csv', $res['download_url']);
        $this->assertStringContainsString('(/erp/custom/dalfred/download.php?f=report.csv)', $res['agent_hint']);
    }

    public function testCreateFileRejectsUnknownFormatLocally(): void
    {
        // Tool should reject before hitting the API — saves a round trip.
        $this->client->expects($this->never())->method('post');

        $raw = $this->tools->createFile('x', 'php', 'evil');
        $res = json_decode($raw, true);

        $this->assertFalse($res['success']);
        $this->assertSame('InvalidFormat', $res['error']);
    }
}
