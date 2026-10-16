<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider;
use Orchestra\Testbench\TestCase;

final class PikPakExistenceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PikPakWebStorageServiceProvider::class];
    }

    public function test_missing_parent_folders_mean_file_and_directory_do_not_exist(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-drive.mypikpak.com/*' => Http::response(['files' => []])]);

        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => false,
        ]);

        $this->assertFalse($disk->fileExists('missing/file.txt'));
        $this->assertFalse($disk->directoryExists('missing/child'));
    }
}
