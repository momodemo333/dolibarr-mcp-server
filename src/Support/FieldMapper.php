<?php

declare(strict_types=1);

namespace DolibarrMcp\Support;

/**
 * Handles Dolibarr API field name inconsistencies and mappings.
 *
 * Dolibarr's REST API has quirks: JSON responses use "date_creation"
 * but SQL columns are "datec", some endpoints use French names
 * (facture vs invoice), line endpoints vary (line vs lines), etc.
 * This class centralizes all those mappings.
 */
class FieldMapper
{
    /**
     * Sortfield corrections: API JSON field names → actual SQL column names.
     */
    private const SORTFIELD_MAP = [
        // Creation date variations → datec
        'date_creation' => 'datec',
        'date_create' => 'datec',
        'created' => 'datec',
        'created_at' => 'datec',
        'creation_date' => 'datec',
        'createdat' => 'datec',
        // Modification date variations → tms
        'date_modification' => 'tms',
        'date_update' => 'tms',
        'modified' => 'tms',
        'updated' => 'tms',
        'updated_at' => 'tms',
        'updatedat' => 'tms',
        'modification_date' => 'tms',
        // Invoice/document date variations → datef
        'date_invoice' => 'datef',
        'date_facture' => 'datef',
        'invoice_date' => 'datef',
    ];

    /**
     * Resources that use plural "lines" endpoint (vs singular "line").
     */
    private const PLURAL_LINE_RESOURCES = [
        'orders',
        'invoices',
        'supplierorders',
        'supplierinvoices',
        'contracts',
    ];

    /**
     * Supplier resources that use different field names for line data.
     * The Dolibarr API uses "pu_ht" instead of "subprice" and "description"
     * instead of "desc" for supplier documents (supplierinvoices, supplierorders).
     */
    private const SUPPLIER_RESOURCES = [
        'supplierinvoices',
        'supplierorders',
    ];

    /**
     * Field name mapping: standard (customer) → supplier field names.
     * Applied automatically when adding/updating lines on supplier documents.
     */
    private const SUPPLIER_LINE_FIELD_MAP = [
        'subprice' => 'pu_ht',
        'desc' => 'description',
    ];

    /**
     * Source type → API endpoint mapping for createFrom operations.
     */
    private const CREATE_FROM_MAP = [
        'proposal' => 'createfromproposal',
        'propal' => 'createfromproposal',
        'order' => 'createfromorder',
        'commande' => 'createfromorder',
        'shipping' => 'createfromcontract',
        'contract' => 'createfromcontract',
    ];

    /**
     * Correct a sortfield name to its actual SQL column name.
     * Returns the original value if no correction is needed.
     */
    public function correctSortField(string $sortfield): string
    {
        return self::SORTFIELD_MAP[strtolower($sortfield)] ?? $sortfield;
    }

    /**
     * Get the line endpoint suffix for a resource type.
     * Orders/invoices use "lines" (plural), proposals use "line" (singular).
     */
    public function getLineEndpoint(string $resource): string
    {
        return in_array($resource, self::PLURAL_LINE_RESOURCES) ? 'lines' : 'line';
    }

    /**
     * Check if a resource is a supplier type.
     */
    public function isSupplierResource(string $resource): bool
    {
        return in_array($resource, self::SUPPLIER_RESOURCES);
    }

    /**
     * Map line field names for supplier documents.
     *
     * Dolibarr's supplier API uses different field names than the customer API:
     * - "subprice" → "pu_ht" (unit price excl. tax)
     * - "desc" → "description" (line description)
     *
     * This method transparently remaps fields so LLMs can use the same
     * field names (subprice, desc) for both customer and supplier documents.
     *
     * @param array<string, mixed> $data Line data with standard field names
     * @param string $resource The resource type
     * @return array<string, mixed> Line data with supplier field names if applicable
     */
    public function mapLineFieldsForResource(array $data, string $resource): array
    {
        if (!$this->isSupplierResource($resource)) {
            return $data;
        }

        $mapped = [];
        foreach ($data as $key => $value) {
            $mappedKey = self::SUPPLIER_LINE_FIELD_MAP[$key] ?? $key;
            // Don't overwrite if the supplier field name is already provided
            if ($mappedKey !== $key && array_key_exists($mappedKey, $data)) {
                $mapped[$key] = $value;
            } else {
                $mapped[$mappedKey] = $value;
            }
        }

        return $mapped;
    }

    /**
     * Get the createFrom endpoint action for a source type.
     */
    public function getCreateFromAction(string $sourceType): string
    {
        return self::CREATE_FROM_MAP[strtolower($sourceType)] ?? "createfrom{$sourceType}";
    }
}
