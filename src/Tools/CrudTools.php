<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class CrudTools
{
    public function __construct(
        private DolibarrClient $client,
        private FieldMapper $fieldMapper,
    ) {}

    #[McpTool(
        name: 'dolibarr_list',
        description: 'List resources from any Dolibarr module (thirdparties, invoices, products, orders, contacts, categories, proposals, users, projects). Use dolibarr_api_explorer first to discover available endpoints and filter parameters. The filters id/rowid are normalized to SQL rowid filters because many Dolibarr list endpoints ignore id query params. PITFALL for contacts: To filter by thirdparty, use "thirdparty_ids" (not "socid" or "fk_soc") - example: {"thirdparty_ids": "1"} or {"thirdparty_ids": "1,2,3"}.'
    )]
    public function listResources(
        #[Schema(description: 'The resource type to list. Examples: thirdparties, invoices, products, orders, contacts, categories, proposals, users, projects')]
        string $resource,
        #[Schema(description: 'Filter by specific field values as JSON object. Example: {"mode": 1} for customers only, {"status": "1"} for active items, {"id": 123} or {"rowid": 123} to filter by Dolibarr rowid.')]
        ?string $filters = null,
        #[Schema(description: <<<'DESC'
SQL-style filter string for advanced filtering. Operators: like, =, !=, <, >, <=, >=, is (null), isnot (null). Combine with AND/OR. Syntax: (t.field:operator:'value'). Examples by module:
- Thirdparties: (t.nom:like:'%dupont%') AND (t.town:like:'%Paris%') — fields: t.nom, t.email, t.phone, t.zip, t.town, t.status, t.client, t.fournisseur, t.code_client, t.siren, t.siret, t.tva_intra
- Contacts: (t.lastname:like:'%dupont%') OR (t.firstname:like:'%jean%') — fields: t.lastname, t.firstname, t.email, t.phone_pro, t.fk_soc, t.poste
- Invoices: (t.datef:>:'2024-01-01') AND (t.fk_statut:=:'1') — fields: t.ref, t.datef, t.total_ttc, t.fk_statut, t.fk_soc, t.date_lim_reglement, t.paye
- Products: (t.label:like:'%cable%') AND (t.tosell:=:'1') — fields: t.ref, t.label, t.price, t.tosell, t.tobuy, t.fk_product_type
- Orders: (t.ref:like:'%CO%') — fields: t.ref, t.date_commande, t.total_ttc, t.fk_statut, t.fk_soc
- Proposals: (t.total_ttc:>:'1000') — fields: t.ref, t.datep, t.fin_validite, t.total_ttc, t.fk_statut, t.fk_soc
Note: LIKE is case-insensitive in most MySQL configurations. The IN operator is NOT supported.
DESC
        )]
        ?string $sqlfilters = null,
        #[Schema(description: 'Field to sort by. Use SQL column names: rowid, nom/name, datec (creation date), tms (modification date), datef (invoice/document date). IMPORTANT: Do NOT use date_creation (use datec instead)')]
        ?string $sortfield = null,
        #[Schema(description: 'Sort order: ASC or DESC')]
        ?string $sortorder = null,
        #[Schema(description: 'Maximum number of results to return. Default 50. Use smaller values (10-20) when working with AI chat systems to avoid context overflow')]
        int $limit = 50,
        #[Schema(description: 'Number of results to skip (for pagination)')]
        int $page = 0,
        #[Schema(description: 'Comma-separated list of fields to return, to reduce response size and avoid context overflow. Example: "id,nom,email,town" for thirdparties, "id,ref,total_ttc,status" for invoices. The "id" field is always included. If omitted, all fields are returned. Use this whenever listing many records to keep responses compact.')]
        ?string $fields = null
    ): string {
        $resource = $this->fieldMapper->normalizeResource($resource);
        $filters = ($filters === '' || $filters === 'null') ? null : $filters;
        $sqlfilters = ($sqlfilters === '' || $sqlfilters === 'null') ? null : $sqlfilters;
        $sortfield = ($sortfield === '' || $sortfield === 'null') ? null : $sortfield;
        $sortorder = ($sortorder === '' || $sortorder === 'null') ? null : $sortorder;
        $fields = ($fields === '' || $fields === 'null') ? null : $fields;

        if ($sortfield !== null) {
            $sortfield = $this->fieldMapper->correctSortField($sortfield);
        }

        $params = [
            'limit' => $limit,
            'page' => $page,
        ];

        $rowidSqlfilter = null;
        $sqlfilterParts = [];

        if ($filters !== null) {
            $decoded = json_decode($filters, true);
            if (is_array($decoded)) {
                $rowidSqlfilter = $this->extractRowidSqlfilter($decoded);
                if (is_array($rowidSqlfilter)) {
                    return json_encode($rowidSqlfilter, JSON_PRETTY_PRINT);
                }
                $params = array_merge($params, $decoded);
            } elseif (json_last_error() !== JSON_ERROR_NONE) {
                return json_encode([
                    'error' => true,
                    'code' => 'INVALID_FILTERS',
                    'message' => 'The filters parameter must be a valid JSON string. Example: {"status": "1"}',
                    'received' => $filters,
                ], JSON_PRETTY_PRINT);
            }
        }

        if ($sqlfilters !== null) {
            $sqlfilterParts[] = $sqlfilters;
        }
        if ($rowidSqlfilter !== null) {
            $sqlfilterParts[] = $rowidSqlfilter;
        }
        if (!empty($sqlfilterParts)) {
            $params['sqlfilters'] = $this->combineSqlfilters($sqlfilterParts);
        }
        if ($sortfield !== null) {
            $params['sortfield'] = $sortfield;
        }
        if ($sortorder !== null) {
            $params['sortorder'] = $sortorder;
        }

        $result = $this->client->get($resource, $params);

        if ($fields !== null && is_array($result)) {
            $result = $this->filterFields($result, $fields);
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[McpTool(
        name: 'dolibarr_get',
        description: 'Get a single resource by its numeric rowid from any Dolibarr module. If you only know the textual reference (e.g. "CO2306-0002", "F2024-0001"), first use dolibarr_list with sqlfilters to resolve it to a rowid. Use dolibarr_api_explorer to discover available endpoints.'
    )]
    public function getResource(
        #[Schema(description: 'The resource type. Examples: thirdparties, invoices, products, orders, contacts, projects')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the resource. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id,
        #[Schema(description: 'Optional sub-resource path. Example: "contacts" to get /thirdparties/{id}/contacts')]
        ?string $subresource = null,
        #[Schema(description: 'Comma-separated list of fields to return, to reduce response size. Example: "id,nom,email,town". The "id" field is always included. If omitted, all fields are returned.')]
        ?string $fields = null
    ): string {
        $resource = $this->fieldMapper->normalizeResource($resource);
        $subresource = ($subresource === '' || $subresource === 'null') ? null : $subresource;
        if ($subresource !== null) {
            $subresource = $this->fieldMapper->normalizeSubresource($subresource);
        }
        $fields = ($fields === '' || $fields === 'null') ? null : $fields;

        $endpoint = "{$resource}/{$id}";
        if ($subresource !== null) {
            $endpoint .= "/{$subresource}";
        }

        $result = $this->client->get($endpoint);

        if ($fields !== null && is_array($result)) {
            $result = $this->filterFields($result, $fields);
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[McpTool(
        name: 'dolibarr_create',
        description: 'Create a new resource in any Dolibarr module. Use dolibarr_api_explorer to discover required fields. Required fields by module: thirdparties needs "name", contacts need "lastname" + "socid", products need "ref" + "label", projects need "ref" + "title", proposals/orders/invoices need "socid". PITFALL for contacts: Use "socid" (not "fk_soc") to link contact to thirdparty - fk_soc is silently ignored by the API. PITFALL: Creating orders with multiple lines in one call may fail - create with 1 line then use dolibarr_add_line for additional lines.'
    )]
    public function createResource(
        #[Schema(description: 'The resource type. Examples: thirdparties, invoices, products, orders, contacts, projects')]
        string $resource,
        #[Schema(description: 'The data to create as JSON object. Use dolibarr_api_explorer to discover required fields. Example for thirdparty: {"name": "Company Name", "client": 1}')]
        string $data
    ): string {
        $resource = $this->fieldMapper->normalizeResource($resource);
        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid JSON data provided',
            ], JSON_PRETTY_PRINT);
        }

        $result = $this->client->post($resource, $decoded);

        return json_encode([
            'success' => true,
            'id' => $result,
            'message' => "Resource created successfully in {$resource}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_update',
        description: <<<'DESC'
Update an existing resource in any Dolibarr module. Two different behaviors depending on what you update:

ENTITY UPDATES (thirdparties, invoices, orders, products...): Only the fields you send are modified. Omitted fields keep their existing values. You can safely send only the changed fields.

LINE UPDATES (composite paths like "invoices/252/lines" or "supplierinvoices/10/lines" to update a line): ⚠️ DESTRUCTIVE — ALL fields must be provided. Any field you omit will be RESET TO ZERO/EMPTY (price, quantity, VAT, description — everything). MANDATORY WORKFLOW for line updates: 1) First use dolibarr_get to read the parent document and find the current line data (all fields). 2) Take ALL existing field values from the line. 3) Merge your changes into the complete field set. 4) Send the FULL data. You can use standard field names (subprice, desc) — the server auto-maps them to supplier fields (pu_ht, description) for supplier documents. Required line fields: desc, subprice, qty, tva_tx, product_type, remise_percent. Example: {"desc": "Updated desc", "subprice": 100, "qty": 2, "tva_tx": 21.000, "product_type": 1, "remise_percent": 0}. Note: updating document lines array via parent document does NOT work — use composite paths.
DESC
    )]
    public function updateResource(
        #[Schema(description: 'The resource type. Examples: thirdparties, invoices, products, orders, projects, or composite paths like "invoices/252/lines" or "supplierinvoices/10/lines"')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the resource. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id,
        #[Schema(description: 'The fields to update as JSON object. Example: {"name": "New Name", "email": "new@email.com"}')]
        string $data
    ): string {
        $resource = $this->fieldMapper->normalizeResource($resource);
        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid JSON data provided',
            ], JSON_PRETTY_PRINT);
        }

        // Auto-map field names for supplier line updates (composite paths like "supplierinvoices/10/lines")
        $baseResource = explode('/', $resource)[0];
        if (str_contains($resource, '/lines') || str_contains($resource, '/line')) {
            $decoded = $this->fieldMapper->mapLineFieldsForResource($decoded, $baseResource);
        }

        $endpoint = "{$resource}/{$id}";
        $this->client->put($endpoint, $decoded);

        return json_encode([
            'success' => true,
            'id' => $id,
            'message' => "Resource {$id} updated successfully in {$resource}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_delete',
        description: 'Delete a resource from any Dolibarr module. Use with caution - this action may be irreversible.'
    )]
    public function deleteResource(
        #[Schema(description: 'The resource type. Examples: thirdparties, invoices, products, orders, projects')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the resource. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id
    ): string {
        $resource = $this->fieldMapper->normalizeResource($resource);
        $endpoint = "{$resource}/{$id}";
        $this->client->delete($endpoint);

        return json_encode([
            'success' => true,
            'message' => "Resource {$id} deleted successfully from {$resource}",
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Filter array results to only include specified fields.
     * Always includes 'id' and 'rowid' if present.
     *
     * @param array<int|string, mixed> $result The API result (list of objects or single object)
     * @param string $fields Comma-separated field names
     * @return array<int|string, mixed>
     */
    private function filterFields(array $result, string $fields): array
    {
        $requestedFields = array_map('trim', explode(',', $fields));
        $requestedFields = array_filter($requestedFields, fn(string $f) => $f !== '');

        if (empty($requestedFields)) {
            return $result;
        }

        // Always include id/rowid for follow-up operations
        if (!in_array('id', $requestedFields) && !in_array('rowid', $requestedFields)) {
            array_unshift($requestedFields, 'id');
        }

        $allowedKeys = array_flip($requestedFields);

        // Detect if this is a list (array of objects) or a single object
        if (array_is_list($result)) {
            return array_map(
                fn(mixed $item) => is_array($item) ? array_intersect_key($item, $allowedKeys) : $item,
                $result
            );
        }

        // Single object
        return array_intersect_key($result, $allowedKeys);
    }

    /**
     * Dolibarr list endpoints commonly ignore id/rowid query parameters.
     *
     * @param array<string, mixed> $filters
     * @return string|array<string, mixed>|null
     */
    private function extractRowidSqlfilter(array &$filters): string|array|null
    {
        $rowid = null;

        if (array_key_exists('id', $filters)) {
            $rowid = $filters['id'];
            unset($filters['id']);
        }
        if (array_key_exists('rowid', $filters)) {
            $rowid = $filters['rowid'];
            unset($filters['rowid']);
        }

        if ($rowid === null || $rowid === '') {
            return null;
        }

        if (!is_int($rowid) && !(is_string($rowid) && ctype_digit($rowid))) {
            return [
                'error' => true,
                'code' => 'INVALID_ID_FILTER',
                'message' => 'The filters.id or filters.rowid value must be a numeric Dolibarr rowid.',
                'received' => $rowid,
            ];
        }

        return "(t.rowid:=:'".(int) $rowid."')";
    }

    /**
     * @param list<string> $sqlfilters
     */
    private function combineSqlfilters(array $sqlfilters): string
    {
        if (count($sqlfilters) === 1) {
            return $sqlfilters[0];
        }

        return implode(' AND ', array_map(
            fn(string $sqlfilter): string => '(' . $sqlfilter . ')',
            $sqlfilters
        ));
    }
}
