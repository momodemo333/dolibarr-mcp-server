<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class ActionTools
{
    public function __construct(private DolibarrClient $client) {}

    #[McpTool(
        name: 'dolibarr_action',
        description: 'Execute a specific action on a resource (validate, close, setinvoiced, settodraft, reopen, payments). TRIGGERS: Use "notrigger" in data to control automatic actions (emails, stock updates, accounting entries, webhooks). notrigger=0 runs triggers (default behavior), notrigger=1 skips them. PITFALL: proposals REQUIRE data={"notrigger": 0} for validation. PAYMENTS: Use action="payments" to record a payment on invoices or supplierinvoices. Customer invoices require: datepaye (timestamp), paymentid (mode ID), closepaidinvoices ("yes"/"no"), accountid (bank account ID). Supplier invoices require: datepaye, payment_mode_id (NOT paymentid), closepaidinvoices, accountid. Optional: num_payment, comment, amount (supplier only, defaults to remaining balance). Payment mode IDs: VIR=2, PRE=3, LIQ=4, CB=6, CHQ=7.'
    )]
    public function executeAction(
        #[Schema(description: 'The resource type. Examples: thirdparties, invoices, orders, proposals')]
        string $resource,
        #[Schema(description: 'Numeric Dolibarr rowid of the resource. Do NOT invent or guess this number — it must come from a prior dolibarr_list result. NOT the textual reference (e.g. "CO2306-0002" or "F2024-0001"). If you only have the reference, first call dolibarr_list with sqlfilters: (t.ref:=:\'<your-ref>\') to retrieve the rowid, then call this tool with that exact rowid.')]
        int $id,
        #[Schema(description: 'The action to execute. Examples: validate, close, setinvoiced, settodraft, reopen, payments')]
        string $action,
        #[Schema(description: 'Additional data for the action as JSON object (optional)')]
        ?string $data = null
    ): string {
        $data = ($data === '' || $data === 'null') ? null : $data;

        $endpoint = "{$resource}/{$id}/{$action}";

        $decoded = [];
        if ($data !== null) {
            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
        }

        $result = $this->client->post($endpoint, $decoded);

        return json_encode([
            'success' => true,
            'result' => $result,
            'message' => "Action '{$action}' executed on {$resource}/{$id}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
