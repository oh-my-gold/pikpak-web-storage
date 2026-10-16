<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Throwable;

final class PikPakFlysystemErrorsTest extends TestCase
{
    public function test_core_operations_report_flysystem_operation_exceptions(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['error' => 'temporarily_unavailable'], 503));
        $adapter = new PikPakFilesystemAdapter(new PikPakApiClient('test-token'));

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'contents');
        rewind($stream);

        $this->assertOperationFailsWith(UnableToCheckExistence::class, fn () => $adapter->fileExists('file.txt'));
        $this->assertOperationFailsWith(UnableToCheckExistence::class, fn () => $adapter->directoryExists('folder'));
        $this->assertOperationFailsWith(UnableToWriteFile::class, fn () => $adapter->write('file.txt', 'contents', new Config()));
        $this->assertOperationFailsWith(UnableToWriteFile::class, fn () => $adapter->writeStream('file.txt', $stream, new Config()));
        $this->assertOperationFailsWith(UnableToReadFile::class, fn () => $adapter->read('file.txt'));
        $this->assertOperationFailsWith(UnableToReadFile::class, fn () => $adapter->readStream('file.txt'));
        $this->assertOperationFailsWith(UnableToDeleteFile::class, fn () => $adapter->delete('file.txt'));
        $this->assertOperationFailsWith(UnableToDeleteDirectory::class, fn () => $adapter->deleteDirectory('folder'));
        $this->assertOperationFailsWith(UnableToCreateDirectory::class, fn () => $adapter->createDirectory('folder', new Config()));
        $this->assertOperationFailsWith(UnableToRetrieveMetadata::class, fn () => $adapter->visibility('file.txt'));
        $this->assertOperationFailsWith(UnableToRetrieveMetadata::class, fn () => $adapter->mimeType('file.txt'));
        $this->assertOperationFailsWith(UnableToRetrieveMetadata::class, fn () => $adapter->lastModified('file.txt'));
        $this->assertOperationFailsWith(UnableToRetrieveMetadata::class, fn () => $adapter->fileSize('file.txt'));
        $this->assertOperationFailsWith(UnableToListContents::class, fn () => iterator_to_array($adapter->listContents('folder', true)));
        $this->assertOperationFailsWith(UnableToMoveFile::class, fn () => $adapter->move('source.txt', 'target.txt', new Config()));
        $this->assertOperationFailsWith(UnableToCopyFile::class, fn () => $adapter->copy('source.txt', 'target.txt', new Config()));

        fclose($stream);
    }

    private function assertOperationFailsWith(string $expected, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expected, $exception);
            return;
        }

        throw new RuntimeException('Expected operation to fail with ' . $expected . '.');
    }
}
