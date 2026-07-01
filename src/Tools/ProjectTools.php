<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use RuntimeException;

class ProjectTools
{
    public function __construct(
        private DolibarrClient $client,
    ) {}

    #[McpTool(
        name: 'dolibarr_add_time_spent',
        description: <<<'DESC'
Add a time spent line to a Dolibarr project task using the official /tasks/{id}/addtimespent API endpoint. Use this for project time tracking instead of generic dolibarr_create. Required: numeric task rowid, local date/time (YYYY-MM-DD HH:MM:SS), duration in seconds. If Dolibarr's Restler datetime validation bug returns an empty HTTP 500, the tool reports a clear MCP_API_ENDPOINT_BUG and does NOT claim success.
DESC
    )]
    public function addTimeSpent(
        #[Schema(description: 'Numeric Dolibarr task rowid. Resolve from dolibarr_list(resource: "tasks") or dolibarr_get(resource: "projects", id: <projectId>, subresource: "tasks") first. Do not use the task reference.')]
        int $taskId,
        #[Schema(description: 'Date and time for the time entry in Dolibarr local time, format YYYY-MM-DD HH:MM:SS. Example: 2026-06-09 09:00:00')]
        string $date,
        #[Schema(description: 'Duration in seconds. Examples: 900 = 15 minutes, 2700 = 45 minutes, 3600 = 1 hour.')]
        int $duration,
        #[Schema(description: 'Dolibarr user rowid for the time entry. Use 0 for the API user/current user. For the current API user can often be represented by 0; resolve users when unsure.')]
        int $userId = 0,
        #[Schema(description: 'Optional note/description for the time entry.')]
        ?string $note = null,
        #[Schema(description: 'Optional product/service rowid associated with this time entry. Leave null unless explicitly needed.')]
        ?int $productId = null,
        #[Schema(description: 'Optional task progress percentage 0-100. Leave null to avoid changing progress.')]
        ?int $progress = null
    ): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)) {
            return json_encode([
                'success' => false,
                'code' => 'INVALID_DATE_FORMAT',
                'message' => 'Date must use format YYYY-MM-DD HH:MM:SS, for example 2026-06-09 09:00:00.',
                'received' => $date,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($duration <= 0) {
            return json_encode([
                'success' => false,
                'code' => 'INVALID_DURATION',
                'message' => 'Duration must be a positive number of seconds.',
                'received' => $duration,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $payload = [
            'date' => $date,
            'duration' => $duration,
        ];

        if ($userId > 0) {
            $payload['user_id'] = $userId;
        }
        if ($note !== null && $note !== '') {
            $payload['note'] = $note;
        }
        if ($productId !== null) {
            $payload['product_id'] = $productId;
        }
        if ($progress !== null) {
            $payload['progress'] = $progress;
        }

        try {
            $result = $this->client->post("tasks/{$taskId}/addtimespent", $payload);

            return json_encode([
                'success' => true,
                'task_id' => $taskId,
                'payload' => $payload,
                'result' => $result,
                'message' => 'Time spent added successfully.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $isEmpty500 = str_contains($message, 'Dolibarr API error (500):')
                && trim(str_replace('Dolibarr API error (500):', '', $message)) === '';

            return json_encode([
                'success' => false,
                'code' => $isEmpty500 ? 'MCP_API_ENDPOINT_BUG' : 'DOLIBARR_API_ERROR',
                'message' => $isEmpty500
                    ? 'Dolibarr returned an empty HTTP 500 before processing /tasks/{id}/addtimespent. This matches a known Dolibarr/Restler datetime validation failure. The time entry was not confirmed as created. Do not bypass silently; document the incident and ask before using another access path.'
                    : 'Dolibarr API rejected the time spent creation. The time entry was not confirmed as created.',
                'task_id' => $taskId,
                'payload' => $payload,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}
