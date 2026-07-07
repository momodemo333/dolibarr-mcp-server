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

    public function testNormalizeResourceKeepsCanonicalNames(): void
    {
        $this->assertSame('thirdparties', $this->mapper->normalizeResource('thirdparties'));
        $this->assertSame('invoices', $this->mapper->normalizeResource('invoices'));
        $this->assertSame('setup', $this->mapper->normalizeResource('setup'));
    }

    public function testNormalizeResourcePluralizesSingularCoreEndpoints(): void
    {
        $this->assertSame('projects', $this->mapper->normalizeResource('project'));
        $this->assertSame('invoices', $this->mapper->normalizeResource('invoice'));
        $this->assertSame('thirdparties', $this->mapper->normalizeResource('thirdparty'));
        $this->assertSame('categories', $this->mapper->normalizeResource('category'));
        $this->assertSame('warehouses', $this->mapper->normalizeResource('warehouse'));
        $this->assertSame('bankaccounts', $this->mapper->normalizeResource('bankaccount'));
        $this->assertSame('members', $this->mapper->normalizeResource('member'));
        $this->assertSame('tickets', $this->mapper->normalizeResource('ticket'));
        $this->assertSame('shipments', $this->mapper->normalizeResource('shipment'));
        $this->assertSame('expensereports', $this->mapper->normalizeResource('expensereport'));
        $this->assertSame('stockmovements', $this->mapper->normalizeResource('stockmovement'));
    }

    public function testNormalizeResourceLowercasesAnyName(): void
    {
        $this->assertSame('projects', $this->mapper->normalizeResource('Projects'));
        $this->assertSame('projects', $this->mapper->normalizeResource('Project'));
        $this->assertSame('invoices', $this->mapper->normalizeResource('INVOICES'));
        $this->assertSame('mycustomthing', $this->mapper->normalizeResource('MyCustomThing'));
    }

    public function testNormalizeResourceHandlesUnderscoreVariants(): void
    {
        $this->assertSame('supplierinvoices', $this->mapper->normalizeResource('supplier_invoices'));
        $this->assertSame('supplierinvoices', $this->mapper->normalizeResource('supplier_invoice'));
        $this->assertSame('supplierorders', $this->mapper->normalizeResource('supplier_orders'));
        $this->assertSame('bankaccounts', $this->mapper->normalizeResource('bank_account'));
    }

    public function testNormalizeResourceHandlesFrenchNouns(): void
    {
        $this->assertSame('invoices', $this->mapper->normalizeResource('facture'));
        $this->assertSame('invoices', $this->mapper->normalizeResource('factures'));
        $this->assertSame('thirdparties', $this->mapper->normalizeResource('tiers'));
        $this->assertSame('thirdparties', $this->mapper->normalizeResource('société'));
        $this->assertSame('proposals', $this->mapper->normalizeResource('devis'));
        $this->assertSame('proposals', $this->mapper->normalizeResource('propal'));
        $this->assertSame('orders', $this->mapper->normalizeResource('commande'));
        $this->assertSame('projects', $this->mapper->normalizeResource('projet'));
        $this->assertSame('tasks', $this->mapper->normalizeResource('tâche'));
    }

    public function testNormalizeResourceOnlyRewritesFirstSegmentOfCompositePaths(): void
    {
        $this->assertSame('invoices/252/lines', $this->mapper->normalizeResource('invoice/252/lines'));
        $this->assertSame('supplierinvoices/10/lines', $this->mapper->normalizeResource('supplier_invoice/10/lines'));
        // Inner segments are never touched (they may be element types, ids, actions...)
        $this->assertSame('setup/extrafields/thirdparty', $this->mapper->normalizeResource('setup/extrafields/thirdparty'));
    }

    public function testNormalizeResourceStripsLeadingAndTrailingSlashes(): void
    {
        $this->assertSame('thirdparties', $this->mapper->normalizeResource('/thirdparties'));
        $this->assertSame('invoices/252/lines', $this->mapper->normalizeResource('/invoice/252/lines/'));
    }

    public function testNormalizeResourcePassesUnknownNamesThroughLowercasedOnly(): void
    {
        // Custom-module endpoints must never be rewritten into non-existent plurals
        $this->assertSame('dalfredapi', $this->mapper->normalizeResource('dalfredapi'));
        $this->assertSame('myresource', $this->mapper->normalizeResource('myresource'));
    }

    public function testNormalizeSubresourcePluralizesKnownCollections(): void
    {
        $this->assertSame('lines', $this->mapper->normalizeSubresource('line'));
        $this->assertSame('contacts', $this->mapper->normalizeSubresource('contact'));
        $this->assertSame('tasks', $this->mapper->normalizeSubresource('task'));
        $this->assertSame('lines', $this->mapper->normalizeSubresource('Lines'));
        // Unknown subresources pass through lowercased
        $this->assertSame('representatives', $this->mapper->normalizeSubresource('representatives'));
    }

    public function testGetLineEndpointWorksWithNormalizedResources(): void
    {
        $this->assertSame('lines', $this->mapper->getLineEndpoint($this->mapper->normalizeResource('invoice')));
        $this->assertSame('lines', $this->mapper->getLineEndpoint($this->mapper->normalizeResource('supplier_invoice')));
        $this->assertTrue($this->mapper->isSupplierResource($this->mapper->normalizeResource('supplierinvoice')));
    }
}
