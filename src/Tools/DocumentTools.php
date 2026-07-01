<?php

declare(strict_types=1);

namespace DolibarrMcp\Tools;

use DolibarrMcp\Client\DolibarrClient;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

class DocumentTools
{
    public function __construct(private DolibarrClient $client) {}

    #[McpTool(
        name: 'dolibarr_documents_list',
        description: 'List documents attached to a Dolibarr element (thirdparty, invoice, order, proposal, etc.). Use English module names: thirdparty, invoice, order, proposal, supplier_invoice, supplier_order, product, member, project, expensereport, contract. Provide either id or ref to identify the element.'
    )]
    public function listDocuments(
        #[Schema(description: 'Module type: thirdparty, invoice, order, proposal, supplier_invoice, supplier_order, product, member, project, expensereport, contract')]
        string $modulepart,
        #[Schema(description: 'Element ID (provide either id or ref)')]
        ?int $id = null,
        #[Schema(description: 'Element reference (provide either id or ref)')]
        ?string $ref = null,
        #[Schema(description: 'Sort field: fullname, relativename, name, date, size')]
        ?string $sortfield = null,
        #[Schema(description: 'Sort order: asc or desc')]
        ?string $sortorder = null
    ): string {
        $ref = ($ref === '' || $ref === 'null') ? null : $ref;
        $sortfield = ($sortfield === '' || $sortfield === 'null') ? null : $sortfield;
        $sortorder = ($sortorder === '' || $sortorder === 'null') ? null : $sortorder;

        $params = ['modulepart' => $modulepart];

        if ($id !== null) {
            $params['id'] = $id;
        }
        if ($ref !== null) {
            $params['ref'] = $ref;
        }
        if ($sortfield !== null) {
            $params['sortfield'] = $sortfield;
        }
        if ($sortorder !== null) {
            $params['sortorder'] = $sortorder;
        }

        try {
            $result = $this->client->get('documents', $params);
            return json_encode([
                'success' => true,
                'modulepart' => $modulepart,
                'documents' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return json_encode([
                    'success' => true,
                    'modulepart' => $modulepart,
                    'documents' => [],
                    'message' => 'No documents found for this element',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            throw $e;
        }
    }

    #[McpTool(
        name: 'dolibarr_documents_upload',
        description: 'Upload a document to a Dolibarr element. Supports text or base64 encoded files. Use English module names: invoice, order, supplier_order, proposal, product, member, project, expensereport, contact, thirdparty. For text files use fileencoding="", for binary files use fileencoding="base64".'
    )]
    public function uploadDocument(
        #[Schema(description: 'Module type: invoice, order, supplier_order, proposal, product, member, project, expensereport, contact, thirdparty')]
        string $modulepart,
        #[Schema(description: 'Element reference (e.g., FA2501942 for invoice, PR001 for product)')]
        string $ref,
        #[Schema(description: 'Filename with extension (e.g., document.pdf, image.png)')]
        string $filename,
        #[Schema(description: 'File content (text or base64 encoded)')]
        string $filecontent,
        #[Schema(description: 'Encoding: empty for text, "base64" for binary files')]
        string $fileencoding = '',
        #[Schema(description: 'Subdirectory within the element folder (optional)')]
        string $subdir = '',
        #[Schema(description: 'Overwrite if file exists: 0=no, 1=yes')]
        int $overwriteifexists = 0
    ): string {
        $data = [
            'filename' => $filename,
            'modulepart' => $modulepart,
            'ref' => $ref,
            'subdir' => $subdir,
            'filecontent' => $filecontent,
            'fileencoding' => $fileencoding,
            'overwriteifexists' => $overwriteifexists,
        ];

        $result = $this->client->post('documents/upload', $data);

        return json_encode([
            'success' => true,
            'result' => $result,
            'message' => "Document '{$filename}' uploaded to {$modulepart}/{$ref}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_documents_download',
        description: 'Download a document from Dolibarr. IMPORTANT: Use French module names for download: facture (not invoice), commande (not order), propal (not proposal). File path format: {reference}/{filename} e.g., "FA2501942/FA2501942.pdf". Use save_to_path to save the file to disk instead of returning base64 content (recommended for PDFs and large files to avoid context overload).'
    )]
    public function downloadDocument(
        #[Schema(description: 'Module type: facture, order, propal, product, etc.')]
        string $modulepart,
        #[Schema(description: 'Relative path to file within modulepart (e.g., FA2501942/FA2501942.pdf)')]
        string $original_file,
        #[Schema(description: 'Optional local path to save the file to disk (e.g., /tmp/FA2501942.pdf). When set, the file is saved and only metadata is returned, avoiding context overload. Recommended for PDFs and large files.')]
        ?string $save_to_path = null
    ): string {
        $save_to_path = ($save_to_path === '' || $save_to_path === 'null') ? null : $save_to_path;

        $params = [
            'modulepart' => $modulepart,
            'original_file' => $original_file,
        ];

        $result = $this->client->get('documents/download', $params);

        if ($save_to_path !== null) {
            $dir = dirname($save_to_path);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("Cannot create directory: {$dir}");
                }
            }

            $content = is_array($result) && isset($result['content'])
                ? $result['content']
                : (is_string($result) ? $result : json_encode($result));

            $decoded = base64_decode($content, true);
            if ($decoded === false) {
                throw new \RuntimeException("Failed to decode base64 content for: {$original_file}");
            }

            $bytes = file_put_contents($save_to_path, $decoded);
            if ($bytes === false) {
                throw new \RuntimeException("Failed to write file to: {$save_to_path}");
            }

            return json_encode([
                'success' => true,
                'modulepart' => $modulepart,
                'file' => $original_file,
                'saved_to' => $save_to_path,
                'size' => $bytes,
                'message' => "File saved to {$save_to_path} ({$bytes} bytes)",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode([
            'success' => true,
            'modulepart' => $modulepart,
            'file' => $original_file,
            'content' => $result,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_documents_builddoc',
        description: 'Generate/build a PDF document for an element (invoice, order, proposal, etc.). Use English module names: invoice, order, proposal, contract, shipment. File path format: {reference}/{reference}.pdf e.g., "FA2501942/FA2501942.pdf". Available templates depend on Dolibarr config (crabe, sponge, azur, etc.).'
    )]
    public function buildDocument(
        #[Schema(description: 'Module type: invoice, order, proposal, contract, shipment')]
        string $modulepart,
        #[Schema(description: 'File path (e.g., FA2501942/FA2501942.pdf)')]
        string $original_file,
        #[Schema(description: 'PDF template name (e.g., crabe, sponge, azur). Leave empty for default.')]
        string $doctemplate = '',
        #[Schema(description: 'Language code (e.g., fr_FR, en_US)')]
        string $langcode = 'fr_FR'
    ): string {
        $data = [
            'modulepart' => $modulepart,
            'original_file' => $original_file,
            'doctemplate' => $doctemplate,
            'langcode' => $langcode,
        ];

        $result = $this->client->put('documents/builddoc', $data);

        return json_encode([
            'success' => true,
            'result' => $result,
            'message' => "Document built: {$original_file}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(
        name: 'dolibarr_documents_delete',
        description: 'Delete a document from Dolibarr. Use French module names for delete: facture (not invoice), commande (not order), propal (not proposal). File path format: {reference}/{filename} e.g., "FA2501942/note.txt". Use with caution - deletion is irreversible.'
    )]
    public function deleteDocument(
        #[Schema(description: 'Module type: invoice, product, thirdparty, etc.')]
        string $modulepart,
        #[Schema(description: 'Relative path to file within modulepart (e.g., PROD-001/image.jpg)')]
        string $original_file
    ): string {
        $params = [
            'modulepart' => $modulepart,
            'original_file' => $original_file,
        ];

        $this->client->delete('documents', $params);

        return json_encode([
            'success' => true,
            'message' => "Document deleted: {$modulepart}/{$original_file}",
        ], JSON_PRETTY_PRINT);
    }
}
