<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Support;

use DolibarrMcp\Support\FieldMapper;
use PHPUnit\Framework\TestCase;

class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper();
    }

    public function testCorrectSortFieldMapsCreationDate(): void
    {
        $this->assertSame('datec', $this->mapper->correctSortField('date_creation'));
        $this->assertSame('datec', $this->mapper->correctSortField('DATE_CREATION'));
        $this->assertSame('datec', $this->mapper->correctSortField('created_at'));
        $this->assertSame('datec', $this->mapper->correctSortField('creation_date'));
    }

    public function testCorrectSortFieldMapsModificationDate(): void
    {
        $this->assertSame('tms', $this->mapper->correctSortField('date_modification'));
        $this->assertSame('tms', $this->mapper->correctSortField('updated_at'));
        $this->assertSame('tms', $this->mapper->correctSortField('modified'));
    }

    public function testCorrectSortFieldMapsInvoiceDate(): void
    {
        $this->assertSame('datef', $this->mapper->correctSortField('date_invoice'));
        $this->assertSame('datef', $this->mapper->correctSortField('invoice_date'));
        $this->assertSame('datef', $this->mapper->correctSortField('date_facture'));
    }

    public function testCorrectSortFieldReturnsOriginalWhenNoMapping(): void
    {
        $this->assertSame('rowid', $this->mapper->correctSortField('rowid'));
        $this->assertSame('nom', $this->mapper->correctSortField('nom'));
        $this->assertSame('datec', $this->mapper->correctSortField('datec'));
    }

    public function testGetLineEndpointPluralForOrdersAndInvoices(): void
    {
        $this->assertSame('lines', $this->mapper->getLineEndpoint('orders'));
        $this->assertSame('lines', $this->mapper->getLineEndpoint('invoices'));
        $this->assertSame('lines', $this->mapper->getLineEndpoint('supplierorders'));
        $this->assertSame('lines', $this->mapper->getLineEndpoint('supplierinvoices'));
    }

    public function testGetLineEndpointSingularForProposals(): void
    {
        $this->assertSame('line', $this->mapper->getLineEndpoint('proposals'));
    }

    public function testGetLineEndpointPluralForContracts(): void
    {
        $this->assertSame('lines', $this->mapper->getLineEndpoint('contracts'));
    }

    public function testGetCreateFromActionMapsKnownTypes(): void
    {
        $this->assertSame('createfromproposal', $this->mapper->getCreateFromAction('proposal'));
        $this->assertSame('createfromproposal', $this->mapper->getCreateFromAction('propal'));
        $this->assertSame('createfromorder', $this->mapper->getCreateFromAction('order'));
        $this->assertSame('createfromorder', $this->mapper->getCreateFromAction('commande'));
        $this->assertSame('createfromcontract', $this->mapper->getCreateFromAction('contract'));
    }

    public function testGetCreateFromActionFallbackForUnknownType(): void
    {
        $this->assertSame('createfrominvoice', $this->mapper->getCreateFromAction('invoice'));
    }

    public function testGetCreateFromActionIsCaseInsensitive(): void
    {
        $this->assertSame('createfromproposal', $this->mapper->getCreateFromAction('PROPOSAL'));
        $this->assertSame('createfromorder', $this->mapper->getCreateFromAction('Order'));
    }

    public function testIsSupplierResourceDetectsSupplierTypes(): void
    {
        $this->assertTrue($this->mapper->isSupplierResource('supplierinvoices'));
        $this->assertTrue($this->mapper->isSupplierResource('supplierorders'));
        $this->assertFalse($this->mapper->isSupplierResource('invoices'));
        $this->assertFalse($this->mapper->isSupplierResource('orders'));
        $this->assertFalse($this->mapper->isSupplierResource('proposals'));
    }

    public function testMapLineFieldsMapsSubpriceToSupplierField(): void
    {
        $data = ['subprice' => 100, 'qty' => 2, 'tva_tx' => 21.0];
        $result = $this->mapper->mapLineFieldsForResource($data, 'supplierinvoices');

        $this->assertArrayHasKey('pu_ht', $result);
        $this->assertArrayNotHasKey('subprice', $result);
        $this->assertSame(100, $result['pu_ht']);
        $this->assertSame(2, $result['qty']);
    }

    public function testMapLineFieldsMapsDescToDescription(): void
    {
        $data = ['desc' => 'Test line', 'qty' => 1];
        $result = $this->mapper->mapLineFieldsForResource($data, 'supplierorders');

        $this->assertArrayHasKey('description', $result);
        $this->assertArrayNotHasKey('desc', $result);
        $this->assertSame('Test line', $result['description']);
    }

    public function testMapLineFieldsDoesNotMapForCustomerResources(): void
    {
        $data = ['subprice' => 100, 'desc' => 'Test'];
        $result = $this->mapper->mapLineFieldsForResource($data, 'invoices');

        $this->assertArrayHasKey('subprice', $result);
        $this->assertArrayHasKey('desc', $result);
        $this->assertArrayNotHasKey('pu_ht', $result);
        $this->assertArrayNotHasKey('description', $result);
    }

    public function testMapLineFieldsPreservesSupplierFieldsIfAlreadyUsed(): void
    {
        $data = ['pu_ht' => 150, 'subprice' => 100, 'description' => 'Already correct'];
        $result = $this->mapper->mapLineFieldsForResource($data, 'supplierinvoices');

        $this->assertSame(150, $result['pu_ht']);
        $this->assertSame('Already correct', $result['description']);
        // subprice is kept as-is since pu_ht was already provided
        $this->assertSame(100, $result['subprice']);
    }

    public function testMapLineFieldsMapsBothFieldsForSupplier(): void
    {
        $data = ['desc' => 'My service', 'subprice' => 50, 'qty' => 1, 'tva_tx' => 21.0, 'product_type' => 1];
        $result = $this->mapper->mapLineFieldsForResource($data, 'supplierinvoices');

        $this->assertSame('My service', $result['description']);
        $this->assertSame(50, $result['pu_ht']);
        $this->assertSame(1, $result['qty']);
        $this->assertSame(21.0, $result['tva_tx']);
        $this->assertSame(1, $result['product_type']);
    }
}
