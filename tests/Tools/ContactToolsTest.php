<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use DolibarrMcp\Tools\ContactTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContactToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private ContactTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new ContactTools($this->client, new FieldMapper());
    }

    public function testLinkContactAddsWithoutSourceFirst(): void
    {
        // New behavior: tries without source in URL first (compatible Doli 18-19, 23+)
        $this->client->expects($this->once())
            ->method('post')
            ->with('orders/10/contact/5/BILLING', [])
            ->willReturn(1);

        $result = json_decode($this->tools->linkContact('orders', 10, 5, 'BILLING'), true);
        $this->assertTrue($result['success']);
    }

    public function testLinkContactFallsBackWithSourceOn404(): void
    {
        // When first attempt returns 404, falls back to URL with source (Doli 20-22 format)
        $matcher = $this->exactly(2);
        $this->client->expects($matcher)
            ->method('post')
            ->willReturnCallback(function (string $endpoint) use ($matcher) {
                if ($matcher->numberOfInvocations() === 1) {
                    $this->assertSame('proposals/10/contact/5/CUSTOMER', $endpoint);
                    throw new \RuntimeException('Dolibarr API error (404): Not found');
                }
                $this->assertSame('proposals/10/contact/5/CUSTOMER/external', $endpoint);
                return 1;
            });

        $result = json_decode($this->tools->linkContact('proposals', 10, 5, 'CUSTOMER'), true);
        $this->assertTrue($result['success']);
    }

    public function testLinkContactRemovesWithoutSource(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('orders/10/contact/5/BILLING');

        $result = json_decode($this->tools->linkContact('orders', 10, 5, 'BILLING', 'remove'), true);
        $this->assertTrue($result['success']);
    }

    public function testLinkContactRejectsInvalidType(): void
    {
        $result = json_decode($this->tools->linkContact('orders', 10, 5, 'INVALID'), true);
        $this->assertFalse($result['success']);
    }

    public function testLinkContactRejectsInvalidAction(): void
    {
        $result = json_decode($this->tools->linkContact('orders', 10, 5, 'BILLING', 'destroy'), true);
        $this->assertFalse($result['success']);
    }

    public function testLinkContactRejectsInvalidSource(): void
    {
        $result = json_decode($this->tools->linkContact('orders', 10, 5, 'BILLING', 'add', 'unknown'), true);
        $this->assertFalse($result['success']);
    }

    public function testGetDocumentContactsReturnsContacts(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('orders/10/contacts')
            ->willReturn([['id' => 5, 'type' => 'BILLING']]);

        $result = json_decode($this->tools->getDocumentContacts('orders', 10), true);
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['contacts']);
    }

    public function testGetDocumentContactsHandles404(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Dolibarr API error (404): Not found'));

        $result = json_decode($this->tools->getDocumentContacts('orders', 999), true);
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['contacts']);
    }

    public function testLinkContactNormalizesUnderscoreResource(): void
    {
        // The tool description itself advertises "supplier_invoices" — the real
        // Dolibarr endpoint is "supplierinvoices".
        $this->client->expects($this->once())
            ->method('post')
            ->with('supplierinvoices/10/contact/5/BILLING', [])
            ->willReturn(1);

        $result = json_decode($this->tools->linkContact('supplier_invoices', 10, 5, 'BILLING'), true);
        $this->assertTrue($result['success']);
    }

    public function testGetDocumentContactsNormalizesSingularResource(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('orders/10/contacts')
            ->willReturn([]);

        $result = json_decode($this->tools->getDocumentContacts('order', 10), true);
        $this->assertTrue($result['success']);
    }
}
