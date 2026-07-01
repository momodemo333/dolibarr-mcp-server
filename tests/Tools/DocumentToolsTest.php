<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Tools;

use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Tools\DocumentTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentToolsTest extends TestCase
{
    private DolibarrClient&MockObject $client;
    private DocumentTools $tools;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DolibarrClient::class);
        $this->tools = new DocumentTools($this->client);
    }

    public function testListDocumentsCallsCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('documents', ['modulepart' => 'invoice', 'id' => 42])
            ->willReturn([['name' => 'FA001.pdf']]);

        $result = json_decode($this->tools->listDocuments('invoice', 42), true);
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['documents']);
    }

    public function testListDocumentsHandles404(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Dolibarr API error (404): Not found'));

        $result = json_decode($this->tools->listDocuments('invoice', 999), true);
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['documents']);
    }

    public function testUploadDocumentPostsData(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('documents/upload', $this->callback(fn($data) =>
                $data['filename'] === 'test.txt' &&
                $data['modulepart'] === 'invoice' &&
                $data['ref'] === 'FA001'
            ))
            ->willReturn('OK');

        $result = json_decode($this->tools->uploadDocument('invoice', 'FA001', 'test.txt', 'hello'), true);
        $this->assertTrue($result['success']);
    }

    public function testDownloadDocumentCallsCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('documents/download', [
                'modulepart' => 'facture',
                'original_file' => 'FA001/FA001.pdf',
            ])
            ->willReturn(['content' => 'base64data']);

        $result = json_decode($this->tools->downloadDocument('facture', 'FA001/FA001.pdf'), true);
        $this->assertTrue($result['success']);
    }

    public function testDownloadDocumentSavesToDisk(): void
    {
        $tmpFile = sys_get_temp_dir() . '/dolibarr_test_' . uniqid() . '.pdf';

        $this->client->expects($this->once())
            ->method('get')
            ->with('documents/download', [
                'modulepart' => 'facture',
                'original_file' => 'FA001/FA001.pdf',
            ])
            ->willReturn(['content' => base64_encode('fake pdf content')]);

        $result = json_decode($this->tools->downloadDocument('facture', 'FA001/FA001.pdf', $tmpFile), true);

        $this->assertTrue($result['success']);
        $this->assertSame($tmpFile, $result['saved_to']);
        $this->assertSame(16, $result['size']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertFileExists($tmpFile);
        $this->assertSame('fake pdf content', file_get_contents($tmpFile));

        unlink($tmpFile);
    }

    public function testDownloadDocumentSavesToDiskCreatesDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dolibarr_test_' . uniqid();
        $tmpFile = $tmpDir . '/subdir/FA001.pdf';

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn(['content' => base64_encode('test')]);

        $result = json_decode($this->tools->downloadDocument('facture', 'FA001/FA001.pdf', $tmpFile), true);

        $this->assertTrue($result['success']);
        $this->assertFileExists($tmpFile);

        unlink($tmpFile);
        rmdir($tmpDir . '/subdir');
        rmdir($tmpDir);
    }

    public function testDownloadDocumentWithoutSaveToPathReturnsBase64(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn(['content' => 'base64data']);

        $result = json_decode($this->tools->downloadDocument('facture', 'FA001/FA001.pdf'), true);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayNotHasKey('saved_to', $result);
    }

    public function testDownloadDocumentSaveToPathStringContent(): void
    {
        $tmpFile = sys_get_temp_dir() . '/dolibarr_test_' . uniqid() . '.txt';

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn(base64_encode('hello world'));

        $result = json_decode($this->tools->downloadDocument('facture', 'FA001/note.txt', $tmpFile), true);

        $this->assertTrue($result['success']);
        $this->assertSame('hello world', file_get_contents($tmpFile));

        unlink($tmpFile);
    }

    public function testBuildDocumentPutsData(): void
    {
        $this->client->expects($this->once())
            ->method('put')
            ->with('documents/builddoc', $this->callback(fn($data) =>
                $data['modulepart'] === 'invoice' &&
                $data['doctemplate'] === 'crabe'
            ))
            ->willReturn('OK');

        $result = json_decode($this->tools->buildDocument('invoice', 'FA001/FA001.pdf', 'crabe'), true);
        $this->assertTrue($result['success']);
    }

    public function testDeleteDocumentCallsCorrectEndpoint(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('documents', [
                'modulepart' => 'facture',
                'original_file' => 'FA001/note.txt',
            ]);

        $result = json_decode($this->tools->deleteDocument('facture', 'FA001/note.txt'), true);
        $this->assertTrue($result['success']);
    }
}
