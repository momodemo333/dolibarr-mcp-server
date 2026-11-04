<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Config\EnvironmentInfo;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use RuntimeException;

/**
 * Project time tracking.
 *
 * The core endpoints POST tasks/{id}/addtimespent and PUT tasks/{id}/timespent/{tid}
 * (checked on Dolibarr 22.0.3) always store the line "with hour", read the date as
 * GMT and silently drop the product and the progress. When the host module adds
 * its own time spent endpoint (EnvironmentInfo::endpoint('timespent')), the tools
 * use it and get all of these right. Without it they fall back on the core
 * endpoints and refuse what those would silently lose, instead of reporting a
 * success that did not happen.
 */
class ProjectTools
{
    private const DATE_PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(:\d{2})?)?$/';

    public function __construct(
        private DolibarrClient $client,
        private ?EnvironmentInfo $environment = null,
    ) {}

    #[McpTool(
        name: 'dolibarr_add_time_spent',
        description: <<<'DESC'
Add a time spent line to a Dolibarr project task. Use this for project time tracking instead of generic dolibarr_create.
Required: numeric task rowid, date, duration in seconds.
Date: "YYYY-MM-DD" creates a date-only line (no start hour, like the Dolibarr timesheet does); "YYYY-MM-DD HH:MM[:SS]" creates a line with a start hour, in the Dolibarr server's local time. withHour forces the mode.
productId (service billed for this time, e.g. the user's usual service) and progress are stored when the server exposes the extended time spent endpoint (emMCP 1.8.0+); otherwise the tool refuses them with UNSUPPORTED_BY_CORE_API rather than dropping them silently.
To change an existing line use dolibarr_update_time_spent.
DESC
    )]
    public function addTimeSpent(
        #[Schema(description: 'Numeric Dolibarr task rowid. Resolve from dolibarr_list(resource: "tasks") or dolibarr_get(resource: "projects", id: <projectId>, subresource: "tasks") first. Do not use the task reference.')]
        int $taskId,
        #[Schema(description: 'Date of the work. "YYYY-MM-DD" for a date-only line (recommended when no start hour is known), or "YYYY-MM-DD HH:MM:SS" in Dolibarr local time for a line with a start hour.')]
        string $date,
        #[Schema(description: 'Duration in seconds. Examples: 900 = 15 minutes, 2700 = 45 minutes, 3600 = 1 hour.')]
        int $duration,
        #[Schema(description: 'Dolibarr user rowid the time belongs to. 0 = the connected user.')]
        int $userId = 0,
        #[Schema(description: 'Optional note/description for the time entry.')]
        ?string $note = null,
        #[Schema(description: 'Optional product/service rowid for this time entry (the service that will be billed).')]
        ?int $productId = null,
        #[Schema(description: 'Optional task progress percentage 0-100. Leave null to avoid changing progress.')]
        ?int $progress = null,
        #[Schema(description: 'Optional. true = line with start hour (the date must contain a time), false = date-only line. Default: deduced from the date.')]
        ?bool $withHour = null
    ): string {
        $parsed = self::parseDate($date, $withHour);
        if (isset($parsed['error'])) {
            return self::json(['success' => false, 'code' => 'INVALID_DATE_FORMAT', 'message' => $parsed['error'], 'received' => $date]);
        }
        if ($duration <= 0) {
            return self::json([
                'success' => false,
                'code' => 'INVALID_DURATION',
                'message' => 'Duration must be a positive number of seconds.',
                'received' => $duration,
            ]);
        }

        $endpoint = $this->environment?->endpoint('timespent');
        if ($endpoint !== null) {
            $payload = [
                'task_id' => $taskId,
                'date' => $parsed['date'],
                'with_hour' => $parsed['with_hour'],
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
                $line = $this->client->post($endpoint, $payload);
            } catch (RuntimeException $e) {
                return self::json([
                    'success' => false,
                    'code' => 'DOLIBARR_API_ERROR',
                    'message' => 'Dolibarr rejected the time spent creation. The time entry was not created.',
                    'task_id' => $taskId,
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                ]);
            }

            return self::json([
                'success' => true,
                'task_id' => $taskId,
                'time_spent' => $line,
                'message' => 'Time spent added.',
            ]);
        }

        // Core endpoint only: refuse what it would drop without a word.
        $unsupported = [];
        if (!$parsed['with_hour']) {
            $unsupported[] = 'date-only line (the core endpoint always stores a start hour)';
        }
        if ($productId !== null) {
            $unsupported[] = 'productId (ignored by the core endpoint)';
        }
        if ($progress !== null) {
            $unsupported[] = 'progress (ignored by the core endpoint)';
        }
        if ($unsupported !== []) {
            return self::json([
                'success' => false,
                'code' => 'UNSUPPORTED_BY_CORE_API',
                'message' => 'This Dolibarr only offers the core time spent endpoint, which cannot store: ' . implode('; ', $unsupported) . '. Nothing was created. Send a date with a time and no productId/progress, or install emMCP 1.8.0+.',
                'task_id' => $taskId,
            ]);
        }

        $payload = [
            'date' => $parsed['date'],
            'duration' => $duration,
        ];
        if ($userId > 0) {
            $payload['user_id'] = $userId;
        }
        if ($note !== null && $note !== '') {
            $payload['note'] = $note;
        }

        try {
            $result = $this->client->post("tasks/{$taskId}/addtimespent", $payload);

            return self::json([
                'success' => true,
                'task_id' => $taskId,
                'payload' => $payload,
                'result' => $result,
                'message' => 'Time spent added successfully.',
                'warning' => 'The core endpoint reads the date as GMT: the hour displayed in Dolibarr may be shifted by the time zone offset.',
            ]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $isEmpty500 = str_contains($message, 'Dolibarr API error (500):')
                && trim(str_replace('Dolibarr API error (500):', '', $message)) === '';

            return self::json([
                'success' => false,
                'code' => $isEmpty500 ? 'MCP_API_ENDPOINT_BUG' : 'DOLIBARR_API_ERROR',
                'message' => $isEmpty500
                    ? 'Dolibarr returned an empty HTTP 500 before processing /tasks/{id}/addtimespent. This matches a known Dolibarr/Restler datetime validation failure. The time entry was not confirmed as created. Do not bypass silently; document the incident and ask before using another access path.'
                    : 'Dolibarr API rejected the time spent creation. The time entry was not confirmed as created.',
                'task_id' => $taskId,
                'payload' => $payload,
                'error' => $message,
            ]);
        }
    }

    #[McpTool(
        name: 'dolibarr_update_time_spent',
        description: <<<'DESC'
Modify an existing time spent line (llx_element_time). Only the fields you pass are changed.
Fields: date ("YYYY-MM-DD" = date only, "YYYY-MM-DD HH:MM:SS" = with start hour, local time), withHour, duration (seconds), note, productId (0 removes the product), userId.
A line already invoiced is refused: it would no longer match its invoice.
Find the line id with dolibarr_get(resource: "tasks", id: <taskId>, subresource: "timespent") or read-only SQL on llx_element_time.
Full support needs the extended time spent endpoint (emMCP 1.8.0+); with the core endpoint only, taskId, date with a time, duration and note are all required and date-only lines or products are refused.
DESC
    )]
    public function updateTimeSpent(
        #[Schema(description: 'Time spent line rowid (llx_element_time.rowid).')]
        int $timeSpentId,
        #[Schema(description: 'Optional new date: "YYYY-MM-DD" (date only) or "YYYY-MM-DD HH:MM:SS" (with start hour, local time).')]
        ?string $date = null,
        #[Schema(description: 'Optional. true = keep/set a start hour, false = date-only line. Can be sent without date to switch the mode of the stored date.')]
        ?bool $withHour = null,
        #[Schema(description: 'Optional new duration in seconds.')]
        ?int $duration = null,
        #[Schema(description: 'Optional new note. An empty string clears it.')]
        ?string $note = null,
        #[Schema(description: 'Optional product/service rowid. 0 removes the product.')]
        ?int $productId = null,
        #[Schema(description: 'Optional user rowid the time belongs to.')]
        ?int $userId = null,
        #[Schema(description: 'Task rowid of the line. Only needed when the server has no extended time spent endpoint.')]
        ?int $taskId = null
    ): string {
        $parsed = null;
        if ($date !== null) {
            $parsed = self::parseDate($date, $withHour);
            if (isset($parsed['error'])) {
                return self::json(['success' => false, 'code' => 'INVALID_DATE_FORMAT', 'message' => $parsed['error'], 'received' => $date]);
            }
        }
        if ($duration !== null && $duration <= 0) {
            return self::json(['success' => false, 'code' => 'INVALID_DURATION', 'message' => 'Duration must be a positive number of seconds.', 'received' => $duration]);
        }

        $payload = [];
        if ($parsed !== null) {
            $payload['date'] = $parsed['date'];
            $payload['with_hour'] = $parsed['with_hour'];
        } elseif ($withHour !== null) {
            $payload['with_hour'] = $withHour;
        }
        if ($duration !== null) {
            $payload['duration'] = $duration;
        }
        if ($note !== null) {
            $payload['note'] = $note;
        }
        if ($productId !== null) {
            $payload['product_id'] = $productId;
        }
        if ($userId !== null && $userId > 0) {
            $payload['user_id'] = $userId;
        }
        if ($payload === []) {
            return self::json(['success' => false, 'code' => 'NOTHING_TO_UPDATE', 'message' => 'Pass at least one of date, withHour, duration, note, productId, userId.']);
        }

        $endpoint = $this->environment?->endpoint('timespent');
        if ($endpoint !== null) {
            try {
                $line = $this->client->put($endpoint . '/' . $timeSpentId, $payload);
            } catch (RuntimeException $e) {
                return self::json([
                    'success' => false,
                    'code' => str_contains($e->getMessage(), '(409)') ? 'ALREADY_INVOICED' : 'DOLIBARR_API_ERROR',
                    'message' => 'Dolibarr rejected the change. The time spent line was not modified.',
                    'time_spent_id' => $timeSpentId,
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                ]);
            }

            return self::json([
                'success' => true,
                'time_spent' => $line,
                'changed' => array_keys($payload),
                'message' => 'Time spent line updated.',
            ]);
        }

        // Core endpoint: it replaces date, duration, user and note in one go.
        $missing = [];
        if ($taskId === null || $taskId <= 0) {
            $missing[] = 'taskId';
        }
        if ($parsed === null) {
            $missing[] = 'date';
        }
        if ($duration === null) {
            $missing[] = 'duration';
        }
        if ($note === null) {
            $missing[] = 'note (the core endpoint would erase it)';
        }
        $unsupported = [];
        if ($parsed !== null && !$parsed['with_hour']) {
            $unsupported[] = 'date-only line';
        }
        if ($withHour === false) {
            $unsupported[] = 'withHour=false';
        }
        if ($productId !== null) {
            $unsupported[] = 'productId';
        }
        if ($missing !== [] || $unsupported !== []) {
            return self::json([
                'success' => false,
                'code' => 'UNSUPPORTED_BY_CORE_API',
                'message' => 'This Dolibarr only offers the core endpoint PUT tasks/{id}/timespent/{tid}.'
                    . ($missing !== [] ? ' Required with it: ' . implode(', ', $missing) . '.' : '')
                    . ($unsupported !== [] ? ' It cannot store: ' . implode(', ', array_unique($unsupported)) . '.' : '')
                    . ' Nothing was modified. Install emMCP 1.8.0+ for partial updates, date-only lines and products.',
                'time_spent_id' => $timeSpentId,
            ]);
        }

        $corePayload = ['date' => $parsed['date'], 'duration' => $duration, 'note' => $note];
        if ($userId !== null && $userId > 0) {
            $corePayload['user_id'] = $userId;
        }

        try {
            $result = $this->client->put("tasks/{$taskId}/timespent/{$timeSpentId}", $corePayload);
        } catch (RuntimeException $e) {
            return self::json([
                'success' => false,
                'code' => 'DOLIBARR_API_ERROR',
                'message' => 'Dolibarr rejected the change. The time spent line was not modified.',
                'time_spent_id' => $timeSpentId,
                'error' => $e->getMessage(),
            ]);
        }

        return self::json([
            'success' => true,
            'time_spent_id' => $timeSpentId,
            'result' => $result,
            'message' => 'Time spent line updated.',
            'warning' => 'The core endpoint reads the date as GMT and always stores a start hour.',
        ]);
    }

    /**
     * Normalise "YYYY-MM-DD" / "YYYY-MM-DD HH:MM[:SS]" and decide the hour mode.
     *
     * @return array{date: string, with_hour: bool}|array{error: string}
     */
    private static function parseDate(string $date, ?bool $withHour): array
    {
        $date = trim($date);
        if (!preg_match(self::DATE_PATTERN, $date, $m)) {
            return ['error' => 'Date must use format YYYY-MM-DD (date only) or YYYY-MM-DD HH:MM:SS (with hour), for example 2026-06-09 or 2026-06-09 09:00:00.'];
        }
        [$y, $mo, $d] = array_map('intval', explode('-', $m[1]));
        if (!checkdate($mo, $d, $y)) {
            return ['error' => 'Date is not a valid calendar date.'];
        }
        $hasTime = isset($m[2]) && $m[2] !== '';
        $withHour ??= $hasTime;
        if ($withHour && !$hasTime) {
            return ['error' => 'withHour is true but the date has no time: send YYYY-MM-DD HH:MM:SS.'];
        }

        if (!$withHour) {
            return ['date' => $m[1], 'with_hour' => false];
        }

        return ['date' => $m[1] . ' ' . $m[2] . (isset($m[3]) && $m[3] !== '' ? $m[3] : ':00'), 'with_hour' => true];
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
