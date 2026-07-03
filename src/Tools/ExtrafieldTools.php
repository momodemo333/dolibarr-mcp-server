<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class ExtrafieldTools
{
    public function __construct(private DolibarrClient $client) {}

    #[McpTool(
        name: 'dolibarr_extrafield_update',
        description: 'Update an extrafield definition. Use dolibarr_list with resource "setup/extrafields" to see existing extrafields first.'
    )]
    public function updateExtrafield(
        #[Schema(description: 'Element type: user, thirdparty, product, commande, facture, propal, etc.')]
        string $elementtype,
        #[Schema(description: 'Attribute name (code) of the extrafield to update')]
        string $attrname,
        #[Schema(description: 'Fields to update as JSON object. Example: {"label": "New Label", "list": "1", "required": "1"}')]
        string $data
    ): string {
        $endpoint = "setup/extrafields/{$elementtype}/{$attrname}";

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid JSON data provided');
        }

        $result = $this->client->put($endpoint, $decoded);

        return json_encode([
            'success' => true,
            'result' => $result,
            'message' => "Extrafield '{$attrname}' updated for {$elementtype}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_extrafield_delete',
        description: 'Delete an extrafield definition. Warning: this will remove the field and all its data.'
    )]
    public function deleteExtrafield(
        #[Schema(description: 'Element type: user, thirdparty, product, commande, facture, propal, etc.')]
        string $elementtype,
        #[Schema(description: 'Attribute name (code) of the extrafield to delete')]
        string $attrname
    ): string {
        $endpoint = "setup/extrafields/{$elementtype}/{$attrname}";

        $result = $this->client->delete($endpoint);

        return json_encode([
            'success' => true,
            'result' => $result,
            'message' => "Extrafield '{$attrname}' deleted from {$elementtype}",
        ], JSON_PRETTY_PRINT);
    }
}
