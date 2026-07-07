<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use DolibarrMcp\Tools\CrudTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CrudToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private CrudTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new CrudTools($this->client, new FieldMapper());
    }

    public function testListResourcesCallsClientWithParams(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties', ['limit' => 10, 'page' => 0, 'sortfield' => 'nom', 'sortorder' => 'ASC'])
            ->willReturn([['id' => 1, 'name' => 'Test']]);

        $result = json_decode($this->tools->listResources('thirdparties', null, null, 'nom', 'ASC', 10), true);
        $this->assertCount(1, $result);
    }

    public function testListResourcesCorrectsSortField(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties', $this->callback(fn($params) => $params['sortfield'] === 'datec'));

        $this->tools->listResources('thirdparties', null, null, 'date_creation');
    }

    public function testListResourcesMergesJsonFilters(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties', $this->callback(fn($params) => $params['mode'] === 1 && $params['limit'] === 50));

        $this->tools->listResources('thirdparties', '{"mode": 1}');
    }

    public function testListResourcesTranslatesIdFilterToRowidSqlfilter(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties', [
                'limit' => 50,
                'page' => 0,
                'sqlfilters' => "(t.rowid:=:'42')",
            ])
            ->willReturn([['id' => 42, 'name' => 'Acme']]);

        $result = json_decode($this->tools->listResources('thirdparties', '{"id": 42}'), true);
        $this->assertSame(42, $result[0]['id']);
    }

    public function testListResourcesCombinesRowidFilterWithSqlfilters(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties', [
                'limit' => 50,
                'page' => 0,
                'sqlfilters' => "((t.client:=:'1')) AND ((t.rowid:=:'42'))",
            ])
            ->willReturn([['id' => 42, 'name' => 'Acme']]);

        $this->tools->listResources('thirdparties', '{"rowid": 42}', "(t.client:=:'1')");
    }

    public function testListResourcesReturnsErrorOnInvalidJson(): void
    {
        $result = json_decode($this->tools->listResources('thirdparties', 'not-json'), true);
        $this->assertTrue($result['error']);
        $this->assertSame('INVALID_FILTERS', $result['code']);
    }

    public function testGetResourceBuildsEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties/42')
            ->willReturn(['id' => 42]);

        $result = json_decode($this->tools->getResource('thirdparties', 42), true);
        $this->assertSame(42, $result['id']);
    }

    public function testGetResourceWithSubresource(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties/42/contacts')
            ->willReturn([]);

        $this->tools->getResource('thirdparties', 42, 'contacts');
    }

    public function testCreateResourcePostsData(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('thirdparties', ['name' => 'Acme'])
            ->willReturn(123);

        $result = json_decode($this->tools->createResource('thirdparties', '{"name": "Acme"}'), true);
        $this->assertTrue($result['success']);
        $this->assertSame(123, $result['id']);
    }

    public function testCreateResourceReturnsErrorOnInvalidJson(): void
    {
        $result = json_decode($this->tools->createResource('thirdparties', 'bad'), true);
        $this->assertFalse($result['success']);
    }

    public function testUpdateResourcePutsData(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with('thirdparties/42', ['name' => 'New']);

        $result = json_decode($this->tools->updateResource('thirdparties', 42, '{"name": "New"}'), true);
        $this->assertTrue($result['success']);
    }

    public function testDeleteResourceCallsDelete(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('thirdparties/42');

        $result = json_decode($this->tools->deleteResource('thirdparties', 42), true);
        $this->assertTrue($result['success']);
    }

    public function testUpdateSupplierLinesMapsFieldNames(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with(
                'supplierinvoices/10/lines/55',
                $this->callback(fn($data) => $data['pu_ht'] === 100 && $data['description'] === 'Test' && !isset($data['subprice']) && !isset($data['desc']))
            );

        $this->tools->updateResource('supplierinvoices/10/lines', 55, '{"subprice": 100, "desc": "Test", "qty": 1}');
    }

    public function testUpdateCustomerLinesKeepsOriginalFields(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with(
                'invoices/10/lines/55',
                $this->callback(fn($data) => $data['subprice'] === 100 && $data['desc'] === 'Test')
            );

        $this->tools->updateResource('invoices/10/lines', 55, '{"subprice": 100, "desc": "Test", "qty": 1}');
    }

    public function testUpdateEntityDoesNotMapFields(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with(
                'supplierinvoices/10',
                $this->callback(fn($data) => $data['ref_supplier'] === 'NEW-REF')
            );

        $this->tools->updateResource('supplierinvoices', 10, '{"ref_supplier": "NEW-REF"}');
    }

    // ========== Fields parameter tests ==========

    public function testListResourcesFieldsNull(): void
    {
        $this->client->method('get')->willReturn([
            ['id' => 1, 'nom' => 'Acme', 'email' => 'a@b.com', 'town' => 'Paris', 'phone' => '123'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties'), true);
        $this->assertArrayHasKey('phone', $result[0]);
        $this->assertArrayHasKey('email', $result[0]);
    }

    public function testListResourcesFieldsFiltersColumns(): void
    {
        $this->client->method('get')->willReturn([
            ['id' => 1, 'nom' => 'Acme', 'email' => 'a@b.com', 'town' => 'Paris', 'phone' => '123', 'address' => '1 rue X'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: 'nom,email'), true);
        $this->assertSame(['id' => 1, 'nom' => 'Acme', 'email' => 'a@b.com'], $result[0]);
    }

    public function testListResourcesFieldsAlwaysIncludesId(): void
    {
        $this->client->method('get')->willReturn([
            ['id' => 5, 'nom' => 'Test', 'town' => 'Lyon'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: 'nom'), true);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('nom', $result[0]);
        $this->assertArrayNotHasKey('town', $result[0]);
    }

    public function testListResourcesFieldsTrimsSpaces(): void
    {
        $this->client->method('get')->willReturn([
            ['id' => 1, 'nom' => 'A', 'email' => 'b', 'town' => 'C'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: ' nom , email '), true);
        $this->assertSame(['id' => 1, 'nom' => 'A', 'email' => 'b'], $result[0]);
    }

    public function testListResourcesFieldsIgnoresUnknownFields(): void
    {
        $this->client->method('get')->willReturn([
            ['id' => 1, 'nom' => 'A'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: 'nom,nonexistent'), true);
        $this->assertSame(['id' => 1, 'nom' => 'A'], $result[0]);
    }

    public function testListResourcesFieldsEmptyResult(): void
    {
        $this->client->method('get')->willReturn([]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: 'nom'), true);
        $this->assertSame([], $result);
    }

    public function testListResourcesFieldsEmptyStringIgnored(): void
    {
        $this->client->method('get')->willReturn([
            ['id' => 1, 'nom' => 'A', 'town' => 'B'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: ''), true);
        $this->assertArrayHasKey('town', $result[0]);
    }

    public function testListResourcesFieldsCombinedWithSqlfilters(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('thirdparties', $this->callback(fn($p) => $p['sqlfilters'] === "(t.nom:like:'%test%')"))
            ->willReturn([
                ['id' => 1, 'nom' => 'Test Corp', 'email' => 'x@y.com', 'town' => 'Paris', 'status' => '1'],
            ]);

        $result = json_decode($this->tools->listResources('thirdparties', sqlfilters: "(t.nom:like:'%test%')", fields: 'nom,town'), true);
        $this->assertSame(['id' => 1, 'nom' => 'Test Corp', 'town' => 'Paris'], $result[0]);
    }

    public function testGetResourceWithFields(): void
    {
        $this->client->method('get')->willReturn([
            'id' => 42, 'nom' => 'Acme', 'email' => 'a@b.com', 'phone' => '123', 'town' => 'Lyon',
        ]);

        $result = json_decode($this->tools->getResource('thirdparties', 42, fields: 'nom,town'), true);
        $this->assertSame(['id' => 42, 'nom' => 'Acme', 'town' => 'Lyon'], $result);
    }

    public function testGetResourceWithFieldsNull(): void
    {
        $this->client->method('get')->willReturn([
            'id' => 42, 'nom' => 'Acme', 'phone' => '123',
        ]);

        $result = json_decode($this->tools->getResource('thirdparties', 42), true);
        $this->assertArrayHasKey('phone', $result);
    }

    public function testListResourcesFieldsWithRowidInsteadOfId(): void
    {
        $this->client->method('get')->willReturn([
            ['rowid' => 10, 'nom' => 'X', 'town' => 'Y'],
        ]);

        $result = json_decode($this->tools->listResources('thirdparties', fields: 'rowid,nom'), true);
        $this->assertArrayHasKey('rowid', $result[0]);
        $this->assertArrayHasKey('nom', $result[0]);
        $this->assertArrayNotHasKey('town', $result[0]);
    }
}
