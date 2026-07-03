<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class ContactTools
{
    public function __construct(private DolibarrClient $client) {}

    #[McpTool(
        name: 'dolibarr_link_contact',
        description: 'Link or unlink a contact to/from a document (order, invoice, proposal). Assign contacts with roles: BILLING (invoice contact), SHIPPING (delivery contact), CUSTOMER (commercial contact). The "source" parameter is critical for proposals: use "external" for customer/supplier contacts, "internal" for employees. Default is "external". Supported documents: orders, invoices, proposals, supplier_orders, supplier_invoices, contracts.'
    )]
    public function linkContact(
        #[Schema(description: 'The document type. Examples: orders, invoices, proposals, supplier_orders, supplier_invoices, contracts')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the document. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id,
        #[Schema(description: 'Numeric Dolibarr rowid of the contact to link/unlink. Do NOT invent or guess this number — it must come from a prior dolibarr_list call. If you only know the contact name or email, first call dolibarr_list with resource: "contacts" and sqlfilters to retrieve the rowid.')]
        int $contactid,
        #[Schema(description: 'The contact role type: BILLING (invoice contact), SHIPPING (delivery contact), CUSTOMER (commercial contact)')]
        string $type,
        #[Schema(description: 'Action to perform: "add" to link the contact, "remove" to unlink')]
        string $action = 'add',
        #[Schema(description: 'Contact source: "external" for customer/supplier contacts, "internal" for company employees. Required for proposals, optional for other documents. Default: external')]
        ?string $source = null
    ): string {
        $type = strtoupper($type);

        $validTypes = ['BILLING', 'SHIPPING', 'CUSTOMER'];
        if (!in_array($type, $validTypes)) {
            return json_encode([
                'success' => false,
                'error' => "Invalid contact type '{$type}'. Valid types are: " . implode(', ', $validTypes),
            ], JSON_PRETTY_PRINT);
        }

        $action = strtolower($action);
        if (!in_array($action, ['add', 'remove'])) {
            return json_encode([
                'success' => false,
                'error' => "Invalid action '{$action}'. Valid actions are: add, remove",
            ], JSON_PRETTY_PRINT);
        }

        if ($source !== null) {
            $source = strtolower($source);
            if (!in_array($source, ['external', 'internal'])) {
                return json_encode([
                    'success' => false,
                    'error' => "Invalid source '{$source}'. Valid sources are: external, internal",
                ], JSON_PRETTY_PRINT);
            }
        }

        $endpoint = "{$resource}/{$id}/contact/{$contactid}/{$type}";
        $effectiveSource = $source ?? 'external';

        if ($action === 'add') {
            // Dolibarr API changed the contact endpoint URL across versions:
            // - Doli 18-19, 23+: POST {resource}/{id}/contact/{contactid}/{type} (no source in URL)
            // - Doli 20-22: POST {resource}/{id}/contact/{contactid}/{type}/{source} (source in URL)
            // Strategy: try without source first (works on most versions), fallback with source on 404
            try {
                $result = $this->client->post($endpoint, []);
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), '404')) {
                    // Fallback: include source in URL path (Doli 20-22 format)
                    $result = $this->client->post("{$endpoint}/{$effectiveSource}", []);
                } else {
                    throw $e;
                }
            }
            $message = "Contact {$contactid} linked to {$resource}/{$id} as {$type} (source: {$effectiveSource})";
        } else {
            $result = $this->client->delete($endpoint);
            $message = "Contact {$contactid} unlinked from {$resource}/{$id} (was {$type})";
        }

        return json_encode([
            'success' => true,
            'result' => $result,
            'message' => $message,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_get_contacts',
        description: 'Get all contacts linked to a document (order, invoice, proposal). Returns contacts with their roles.'
    )]
    public function getDocumentContacts(
        #[Schema(description: 'The document type. Examples: orders, invoices, proposals, supplier_orders, supplier_invoices, contracts')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the document. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id
    ): string {
        $endpoint = "{$resource}/{$id}/contacts";

        try {
            $result = $this->client->get($endpoint);
            return json_encode([
                'success' => true,
                'document' => "{$resource}/{$id}",
                'contacts' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return json_encode([
                    'success' => true,
                    'document' => "{$resource}/{$id}",
                    'contacts' => [],
                    'message' => 'No contacts linked to this document',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            throw $e;
        }
    }
}
