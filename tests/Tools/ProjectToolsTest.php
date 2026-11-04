<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Config\EnvironmentInfo;
use DolibarrMcp\Tools\ProjectTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProjectToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private ProjectTools $tools;
    private ProjectTools $extended;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new ProjectTools($this->client);
        $this->extended = new ProjectTools(
            $this->client,
            new EnvironmentInfo(endpoints: ['timespent' => 'emmcp/timespent']),
        );
    }

    private function decode(string $json): array
    {
        return json_decode($json, true);
    }

    // --- core endpoint only ---------------------------------------------------

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

        $result = $this->decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 2700, 1, 'Work done'));

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['task_id']);
        $this->assertSame(2700, $result['payload']['duration']);
    }

    public function testAddTimeSpentCompletesSecondsForTheCoreEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('tasks/5/addtimespent', ['date' => '2026-06-09 09:30:00', 'duration' => 900])
            ->willReturn([]);

        $this->assertTrue($this->decode($this->tools->addTimeSpent(5, '2026-06-09 09:30', 900))['success']);
    }

    public function testAddTimeSpentRejectsInvalidDateFormat(): void
    {
        $this->client->expects($this->never())->method('post');

        $result = $this->decode($this->tools->addTimeSpent(5, '09/06/2026', 2700));

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_DATE_FORMAT', $result['code']);
    }

    public function testAddTimeSpentRejectsImpossibleCalendarDate(): void
    {
        $this->client->expects($this->never())->method('post');

        $this->assertSame('INVALID_DATE_FORMAT', $this->decode($this->extended->addTimeSpent(5, '2026-02-30', 900))['code']);
    }

    public function testAddTimeSpentRejectsInvalidDuration(): void
    {
        $this->client->expects($this->never())->method('post');

        $result = $this->decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 0));

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_DURATION', $result['code']);
    }

    public function testCoreOnlyRefusesProductInsteadOfDroppingIt(): void
    {
        // Regression: productId used to be sent, ignored by Dolibarr, and reported as a success.
        $this->client->expects($this->never())->method('post');

        $result = $this->decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 900, 0, null, 6));

        $this->assertFalse($result['success']);
        $this->assertSame('UNSUPPORTED_BY_CORE_API', $result['code']);
        $this->assertStringContainsString('productId', $result['message']);
    }

    public function testCoreOnlyRefusesDateOnlyLine(): void
    {
        $this->client->expects($this->never())->method('post');

        $result = $this->decode($this->tools->addTimeSpent(5, '2026-06-09', 900));

        $this->assertSame('UNSUPPORTED_BY_CORE_API', $result['code']);
        $this->assertStringContainsString('date-only', $result['message']);
    }

    public function testAddTimeSpentDocumentsEmpty500AsEndpointBug(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->willThrowException(new RuntimeException('Dolibarr API error (500): '));

        $result = $this->decode($this->tools->addTimeSpent(5, '2026-06-09 09:00:00', 2700, 1));

        $this->assertFalse($result['success']);
        $this->assertSame('MCP_API_ENDPOINT_BUG', $result['code']);
        $this->assertStringContainsString('Do not bypass silently', $result['message']);
    }

    // --- host endpoint --------------------------------------------------------

    public function testDateOnlyWithProductGoesThroughTheHostEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('emmcp/timespent', [
                'task_id' => 5,
                'date' => '2026-11-04',
                'with_hour' => false,
                'duration' => 1800,
                'note' => 'Bilan',
                'product_id' => 6,
            ])
            ->willReturn(['id' => 99, 'with_hour' => 0, 'product_id' => 6]);

        $result = $this->decode($this->extended->addTimeSpent(5, '2026-11-04', 1800, 0, 'Bilan', 6));

        $this->assertTrue($result['success']);
        $this->assertSame(99, $result['time_spent']['id']);
        $this->assertSame(6, $result['time_spent']['product_id']);
    }

    public function testWithHourFalseDropsTheTimeOfADateTime(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('emmcp/timespent', $this->callback(fn(array $p) => $p['date'] === '2026-11-04' && $p['with_hour'] === false))
            ->willReturn(['id' => 1]);

        $this->extended->addTimeSpent(5, '2026-11-04 00:00:00', 900, 0, null, null, null, false);
    }

    public function testWithHourTrueRequiresATime(): void
    {
        $this->client->expects($this->never())->method('post');

        $this->assertSame('INVALID_DATE_FORMAT', $this->decode($this->extended->addTimeSpent(5, '2026-11-04', 900, 0, null, null, null, true))['code']);
    }

    public function testHostEndpointErrorIsReportedAsNotCreated(): void
    {
        $this->client->method('post')->willThrowException(new RuntimeException('Dolibarr API error (400): product_id 999 does not exist'));

        $result = $this->decode($this->extended->addTimeSpent(5, '2026-11-04', 900, 0, null, 999));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not created', $result['message']);
    }

    // --- update ---------------------------------------------------------------

    public function testUpdateSendsOnlyTheChangedFields(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with('emmcp/timespent/77', ['note' => 'Revu', 'product_id' => 1])
            ->willReturn(['id' => 77, 'product_id' => 1]);

        $result = $this->decode($this->extended->updateTimeSpent(77, null, null, null, 'Revu', 1));

        $this->assertTrue($result['success']);
        $this->assertSame(['note', 'product_id'], $result['changed']);
    }

    public function testUpdateCanSwitchToDateOnlyWithoutADate(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with('emmcp/timespent/77', ['with_hour' => false])
            ->willReturn(['id' => 77]);

        $this->assertTrue($this->decode($this->extended->updateTimeSpent(77, null, false))['success']);
    }

    public function testUpdateWithNothingIsRefused(): void
    {
        $this->client->expects($this->never())->method('put');

        $this->assertSame('NOTHING_TO_UPDATE', $this->decode($this->extended->updateTimeSpent(77))['code']);
    }

    public function testUpdateOfAnInvoicedLineIsReported(): void
    {
        $this->client->method('put')->willThrowException(new RuntimeException('Dolibarr API error (409): Conflict: already invoiced'));

        $this->assertSame('ALREADY_INVOICED', $this->decode($this->extended->updateTimeSpent(77, null, null, 600))['code']);
    }

    public function testCoreOnlyUpdateRequiresTheFullLine(): void
    {
        $this->client->expects($this->never())->method('put');

        $result = $this->decode($this->tools->updateTimeSpent(77, null, null, 600));

        $this->assertSame('UNSUPPORTED_BY_CORE_API', $result['code']);
        $this->assertStringContainsString('taskId', $result['message']);
        $this->assertStringContainsString('note', $result['message']);
    }

    public function testCoreOnlyUpdateRefusesProduct(): void
    {
        $this->client->expects($this->never())->method('put');

        $result = $this->decode($this->tools->updateTimeSpent(77, '2026-11-04 09:00:00', null, 600, 'x', 1, null, 5));

        $this->assertSame('UNSUPPORTED_BY_CORE_API', $result['code']);
        $this->assertStringContainsString('productId', $result['message']);
    }

    public function testCoreOnlyUpdateWithTheFullLineUsesTheCoreEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with('tasks/5/timespent/77', ['date' => '2026-11-04 09:00:00', 'duration' => 600, 'note' => 'x'])
            ->willReturn([]);

        $this->assertTrue($this->decode($this->tools->updateTimeSpent(77, '2026-11-04 09:00:00', null, 600, 'x', null, null, 5))['success']);
    }
}
