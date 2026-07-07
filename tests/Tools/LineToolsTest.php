<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use DolibarrMcp\Tools\LineTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LineToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private LineTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new LineTools($this->client, new FieldMapper());
    }

    public function testAddLineUsesLinesForInvoices(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('invoices/10/lines', ['qty' => 1, 'subprice' => 100])
            ->willReturn(55);

        $result = json_decode($this->tools->addLine('invoices', 10, '{"qty": 1, "subprice": 100}'), true);
        $this->assertTrue($result['success']);
        $this->assertSame(55, $result['line_id']);
    }

    public function testAddLineUsesLineForProposals(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('proposals/5/line', $this->anything())
            ->willReturn(12);

        $this->tools->addLine('proposals', 5, '{"qty": 1}');
    }

    public function testAddLineReturnsErrorOnInvalidJson(): void
    {
        $result = json_decode($this->tools->addLine('invoices', 1, 'bad'), true);
        $this->assertFalse($result['success']);
    }

    public function testCreateFromMapsProposalType(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('orders/createfromproposal/99', [])
            ->willReturn(200);

        $result = json_decode($this->tools->createFrom('orders', 'proposal', 99), true);
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['id']);
    }

    public function testCreateFromMapsOrderType(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('invoices/createfromorder/50', []);

        $this->tools->createFrom('invoices', 'order', 50);
    }

    public function testAddLineToSupplierInvoiceMapsSubpriceToUnitPrice(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'supplierinvoices/10/lines',
                $this->callback(fn($data) => $data['pu_ht'] === 100 && !isset($data['subprice']))
            )
            ->willReturn(['success' => true, 'message' => 'Operation completed (empty response from API)']);

        $this->tools->addLine('supplierinvoices', 10, '{"subprice": 100, "qty": 1, "product_type": 1}');
    }

    public function testAddLineToSupplierInvoiceMapsDescToDescription(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'supplierinvoices/10/lines',
                $this->callback(fn($data) => $data['description'] === 'Test service' && !isset($data['desc']))
            )
            ->willReturn(['success' => true, 'message' => 'Operation completed (empty response from API)']);

        $this->tools->addLine('supplierinvoices', 10, '{"desc": "Test service", "qty": 1, "pu_ht": 50, "product_type": 1}');
    }

    public function testAddLineToCustomerInvoiceKeepsOriginalFields(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'invoices/10/lines',
                $this->callback(fn($data) => $data['subprice'] === 100 && $data['desc'] === 'Test')
            )
            ->willReturn(55);

        $this->tools->addLine('invoices', 10, '{"subprice": 100, "desc": "Test", "qty": 1}');
    }

    public function testAddLineNormalizesSingularResourceAndUsesPluralLinesEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('invoices/252/lines', ['qty' => 1, 'subprice' => 100])
            ->willReturn(55);

        $result = json_decode($this->tools->addLine('invoice', 252, '{"qty": 1, "subprice": 100}'), true);
        $this->assertTrue($result['success']);
    }

    public function testAddLineNormalizesSingularSupplierResourceAndMapsSupplierFields(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'supplierinvoices/10/lines',
                $this->callback(fn($data) => $data['pu_ht'] === 100 && $data['description'] === 'Test' && !isset($data['subprice']))
            )
            ->willReturn(56);

        $result = json_decode($this->tools->addLine('supplier_invoice', 10, '{"subprice": 100, "desc": "Test", "qty": 1}'), true);
        $this->assertTrue($result['success']);
    }

    public function testCreateFromNormalizesSingularTargetResource(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('invoices/createfromorder/9', [])
            ->willReturn(77);

        $result = json_decode($this->tools->createFrom('invoice', 'order', 9), true);
        $this->assertTrue($result['success']);
    }
}
