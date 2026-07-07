<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use DolibarrMcp\Tools\ActionTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActionToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private ActionTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new ActionTools($this->client, new FieldMapper());
    }

    public function testExecuteActionPostsToCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('invoices/10/validate', ['notrigger' => 0])
            ->willReturn(10);

        $result = json_decode($this->tools->executeAction('invoices', 10, 'validate', '{"notrigger": 0}'), true);
        $this->assertTrue($result['success']);
    }

    public function testExecuteActionWithoutData(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('orders/5/close', [])
            ->willReturn(5);

        $result = json_decode($this->tools->executeAction('orders', 5, 'close'), true);
        $this->assertTrue($result['success']);
    }

    public function testExecuteActionHandlesInvalidJsonGracefully(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('orders/5/validate', []);

        $this->tools->executeAction('orders', 5, 'validate', 'not-json');
    }

    public function testExecuteActionNormalizesSingularResource(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('invoices/42/validate', []);

        $this->tools->executeAction('invoice', 42, 'validate');
    }

    public function testExecuteActionNormalizesFrenchResource(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('proposals/7/validate', ['notrigger' => 0]);

        $this->tools->executeAction('devis', 7, 'validate', '{"notrigger": 0}');
    }
}
