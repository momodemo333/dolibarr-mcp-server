<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Tools\ProjectTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProjectToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private ProjectTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new ProjectTools($this->client);
    }

    public function testAddTimeSpentPostsToTaskEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('tasks/5/addtimespent', [
                'date' => '2026-06-09 09:00:00',
                'duration' => 2700,
                'user_id' => 1,
                'note' => 'Work done',
            ])
            ->willReturn(['success' => ['code' => 200, 'message' => 'Time spent added']]);

        $result = json_decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 2700, 1, 'Work done'), true);

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['task_id']);
        $this->assertSame(2700, $result['payload']['duration']);
    }

    public function testAddTimeSpentRejectsInvalidDateFormat(): void
    {
        $this->client->expects($this->never())->method('post');

        $result = json_decode($this->tools->addTimeSpent(5, '2026-06-09', 2700), true);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_DATE_FORMAT', $result['code']);
    }

    public function testAddTimeSpentRejectsInvalidDuration(): void
    {
        $this->client->expects($this->never())->method('post');

        $result = json_decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 0), true);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_DURATION', $result['code']);
    }

    public function testAddTimeSpentDocumentsEmpty500AsEndpointBug(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->willThrowException(new RuntimeException('Dolibarr API error (500): '));

        $result = json_decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 2700, 1), true);

        $this->assertFalse($result['success']);
        $this->assertSame('MCP_API_ENDPOINT_BUG', $result['code']);
        $this->assertStringContainsString('Do not bypass silently', $result['message']);
    }
}
