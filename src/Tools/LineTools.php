<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Support\FieldMapper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class LineTools
{
    public function __construct(
        private DolibarrClient $client,
        private FieldMapper $fieldMapper,
    ) {}

    #[McpTool(
        name: 'dolibarr_add_line',
        description: <<<'DESC'
Add a line to a document (proposal, order, invoice, supplierorder, supplierinvoice, contract). Use this to add product/service lines. You can always use the standard field names (subprice, desc) — the server automatically maps them to the correct API fields for supplier documents (pu_ht, description). Key fields: fk_product (product ID), qty, subprice (unit price HT), tva_tx (VAT rate, e.g., 21.000), desc (description), remise_percent (discount %). CRITICAL: "product_type" (0=product, 1=service) is REQUIRED even when using fk_product. PITFALL: subprice=0 causes error 500 - use subprice=1 with remise_percent=100 for free items. To delete lines: use dolibarr_delete with composite paths like "invoices/252/lines" or "supplierinvoices/10/lines". To UPDATE lines: use dolibarr_update with composite paths — but WARNING: you MUST send ALL line fields or omitted values will be reset to zero. Always read the existing line data first with dolibarr_get before updating.
DESC
    )]
    public function addLine(
        #[Schema(description: 'The document type. Examples: proposals, orders, invoices, supplierorders, supplierinvoices, contracts')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the document. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id,
        #[Schema(description: 'The line data as JSON object. Key fields: fk_product (product ID), qty, subprice (unit price HT), tva_tx (VAT rate), desc (description), remise_percent (discount %). For supplier documents, subprice and desc are auto-mapped to pu_ht and description.')]
        string $data
    ): string {
        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid JSON data provided',
            ], JSON_PRETTY_PRINT);
        }

        $resource = $this->fieldMapper->normalizeResource($resource);
        $decoded = $this->fieldMapper->mapLineFieldsForResource($decoded, $resource);

        $lineEndpoint = $this->fieldMapper->getLineEndpoint($resource);
        $endpoint = "{$resource}/{$id}/{$lineEndpoint}";
        $result = $this->client->post($endpoint, $decoded);

        // line_id <= 0 means the line was NOT created
        if (is_int($result) && $result <= 0) {
            return json_encode([
                'success' => false,
                'line_id' => $result,
                'error' => "Failed to add line to {$resource}/{$id}. The API returned line_id={$result} which indicates failure. Check that all required fields are provided correctly (especially product_type, qty, tva_tx).",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => true,
            'line_id' => $result,
            'message' => "Line added successfully to {$resource}/{$id}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_create_from',
        description: 'Create a document from another document (order from proposal, invoice from order, etc.). This copies all lines automatically.'
    )]
    public function createFrom(
        #[Schema(description: 'The target resource type to create. Examples: orders, invoices')]
        string $resource,
        #[Schema(description: 'The source document type. Examples: proposal, order, shipping')]
        string $sourceType,
        #[Schema(description: 'Numeric Dolibarr rowid of the source document. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "PR2024-0001" or "CO2306-0002"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $sourceId
    ): string {
        $resource = $this->fieldMapper->normalizeResource($resource);
        $action = $this->fieldMapper->getCreateFromAction($sourceType);
        $endpoint = "{$resource}/{$action}/{$sourceId}";

        $result = $this->client->post($endpoint, []);

        return json_encode([
            'success' => true,
            'id' => $result,
            'message' => "Created {$resource} from {$sourceType} #{$sourceId}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
