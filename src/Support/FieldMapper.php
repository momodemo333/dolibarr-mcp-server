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
     * Standard Dolibarr REST endpoints (core API modules, Dolibarr 16-21).
     *
     * Canonicalization only rewrites a resource name when the rewrite lands
     * on an entry of this list, so custom-module endpoints — including
     * legitimately singular ones — always pass through untouched.
     */
    private const KNOWN_ENDPOINTS = [
        'accountancy', 'agendaevents', 'bankaccounts', 'boms', 'categories',
        'contacts', 'contracts', 'documents', 'donations', 'expensereports',
        'interventions', 'invoices', 'knowledgemanagement', 'login', 'members',
        'memberstypes', 'mos', 'multicurrencies', 'orders', 'partnerships',
        'products', 'projects', 'proposals', 'receptions', 'recruitments',
        'salaries', 'setup', 'shipments', 'status', 'stockmovements',
        'subscriptions', 'supplierinvoices', 'supplierorders',
        'supplierproposals', 'tasks', 'thirdparties', 'tickets', 'users',
        'warehouses', 'workstations',
    ];

    /**
     * Sub-collections used as second-level path segments (resource/{id}/x).
     */
    private const KNOWN_SUBRESOURCES = [
        'lines', 'contacts', 'tasks', 'roles', 'categories', 'shipments',
    ];

    /**
     * Aliases the suffix rule cannot derive: French business nouns (models
     * mirror the user's language) and irregular shorthands.
     */
    private const RESOURCE_ALIASES = [
        'propal' => 'proposals',
        'propals' => 'proposals',
        'devis' => 'proposals',
        'facture' => 'invoices',
        'factures' => 'invoices',
        'commande' => 'orders',
        'commandes' => 'orders',
        'produit' => 'products',
        'produits' => 'products',
        'projet' => 'projects',
        'projets' => 'projects',
        'tiers' => 'thirdparties',
        'societe' => 'thirdparties',
        'societes' => 'thirdparties',
        'société' => 'thirdparties',
        'sociétés' => 'thirdparties',
        'contrat' => 'contracts',
        'contrats' => 'contracts',
        'tache' => 'tasks',
        'taches' => 'tasks',
        'tâche' => 'tasks',
        'tâches' => 'tasks',
        'utilisateur' => 'users',
        'utilisateurs' => 'users',
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

    /**
     * Canonicalize an LLM-supplied resource path for the Dolibarr REST API.
     *
     * Models infer resource names from natural language and commonly produce
     * singular nouns ("project"), capitalized names ("Projects"), underscore
     * variants ("supplier_invoices"), French nouns ("facture") or leading
     * slashes — while Dolibarr endpoints are lowercase concatenated plurals.
     * Only the first path segment is rewritten, and only when the rewrite
     * lands on a known core endpoint; unknown names (custom modules) pass
     * through lowercased, never blindly pluralized.
     */
    public function normalizeResource(string $resource): string
    {
        $resource = trim($resource, "/ \t\n\r\0\x0B");
        if ($resource === '') {
            return $resource;
        }

        $parts = explode('/', $resource);
        $parts[0] = $this->canonicalizeCollection($parts[0], self::KNOWN_ENDPOINTS);

        return implode('/', $parts);
    }

    /**
     * Canonicalize a sub-collection name (the "contacts" of
     * thirdparties/{id}/contacts) with the same rules as normalizeResource().
     */
    public function normalizeSubresource(string $subresource): string
    {
        return $this->canonicalizeCollection(
            trim($subresource, "/ \t\n\r\0\x0B"),
            self::KNOWN_SUBRESOURCES
        );
    }

    /**
     * @param list<string> $known
     */
    private function canonicalizeCollection(string $name, array $known): string
    {
        $lower = mb_strtolower($name);
        if (in_array($lower, $known, true)) {
            return $lower;
        }

        $alias = self::RESOURCE_ALIASES[$lower] ?? null;
        if ($alias !== null && in_array($alias, $known, true)) {
            return $alias;
        }

        $compact = str_replace(['_', '-'], '', $lower);
        foreach ([$compact, self::pluralize($compact)] as $candidate) {
            if (in_array($candidate, $known, true)) {
                return $candidate;
            }
        }

        // Unknown name (custom module endpoint?): pass through lowercased —
        // Restler route keys are lowercase, so lowering never breaks a valid
        // route, but inventing a plural could.
        return $lower;
    }

    private static function pluralize(string $noun): string
    {
        if ($noun === '' || str_ends_with($noun, 's')) {
            return $noun;
        }
        if (str_ends_with($noun, 'y')) {
            return substr($noun, 0, -1) . 'ies';
        }

        return $noun . 's';
    }
}
