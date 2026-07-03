<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

class FileGenerationTools
{
    private const ALLOWED_FORMATS = ['txt', 'csv', 'md', 'json', 'html'];

    public function __construct(private DolibarrClient $client) {}

    #[McpTool(
        name: 'dolibarr_files_create',
        description: <<<'DESC'
Create a file (TXT, CSV, MD, JSON or HTML) in the user's personal Dalfred storage area.
The file becomes downloadable through a forced-attachment link. Returns a `download_url`
and an `agent_hint` you should include verbatim in your reply to the user, e.g.
"J'ai créé le fichier : [report.csv](/custom/dalfred/download.php?f=report.csv)".
HTML files are never rendered inline by the browser — they always download.
Use this when the user asks for a report, an export or any text-based artifact
to download. Do NOT use it for binary formats (PDF, Excel, images).
DESC
    )]
    public function createFile(
        #[Schema(description: 'Logical filename (extension optional — it will be forced from `format`). Spaces and special chars are sanitised.')]
        string $filename,
        #[Schema(description: 'Format / extension. One of: txt, csv, md, json, html')]
        string $format,
        #[Schema(description: 'File content (UTF-8 text). Max size is configured by the administrator (default 5 MB).')]
        string $content
    ): string {
        $format = strtolower($format);
        if (!in_array($format, self::ALLOWED_FORMATS, true)) {
            return json_encode([
                'success' => false,
                'error'   => 'InvalidFormat',
                'message' => 'Allowed formats: ' . implode(', ', self::ALLOWED_FORMATS),
            ], JSON_PRETTY_PRINT);
        }

        try {
            $result = $this->client->post('dalfred/generated_files/create', [
                'filename' => $filename,
                'format'   => $format,
                'content'  => $content,
            ]);
        } catch (\Throwable $e) {
            // Try to extract the stable error code from the API message.
            $msg  = $e->getMessage();
            $code = 'WriteFailed';
            foreach (['FileGenerationDisabled', 'InvalidFormat', 'InvalidFilename', 'FileTooLarge', 'NotAuthenticated'] as $known) {
                if (strpos($msg, $known) !== false) { $code = $known; break; }
            }
            return json_encode([
                'success' => false,
                'error'   => $code,
                'message' => $msg,
            ], JSON_PRETTY_PRINT);
        }

        $result['agent_hint'] = sprintf(
            "I created the file: [%s](%s). Reply to the user including this Markdown link so they can download it.",
            $result['filename'] ?? 'file',
            $result['download_url'] ?? '#'
        );

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
