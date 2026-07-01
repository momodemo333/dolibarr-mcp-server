<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Tools\ExtrafieldTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExtrafieldToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private ExtrafieldTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new ExtrafieldTools($this->client);
    }

    public function testUpdateExtrafieldPutsToCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with('setup/extrafields/thirdparty/myfield', ['label' => 'New Label'])
            ->willReturn('OK');

        $result = json_decode($this->tools->updateExtrafield('thirdparty', 'myfield', '{"label": "New Label"}'), true);
        $this->assertTrue($result['success']);
    }

    public function testUpdateExtrafieldThrowsOnInvalidJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools->updateExtrafield('thirdparty', 'myfield', 'bad');
    }

    public function testDeleteExtrafieldCallsCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('setup/extrafields/thirdparty/myfield')
            ->willReturn('OK');

        $result = json_decode($this->tools->deleteExtrafield('thirdparty', 'myfield'), true);
        $this->assertTrue($result['success']);
    }
}
