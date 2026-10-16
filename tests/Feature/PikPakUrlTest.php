<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider;
use Orchestra\Testbench\TestCase;

final class PikPakUrlTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PikPakWebStorageServiceProvider::class];
    }

    public function test_laravel_storage_url_returns_the_remote_content_url(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/drive/v1/files') {
                return Http::response(['files' => [[
                    'id' => 'file-id',
                    'kind' => 'drive#file',
                    'name' => 'photo.jpg',
                    'parent_id' => 'root-id',
                    'web_content_link' => 'https://download.example.test/photo.jpg?signature=temporary',
                ]]]);
            }

            return Http::response([], 404);
        });

        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'root_folder_id' => 'root-id',
            'throw' => true,
        ]);

        $this->assertSame(
            'https://download.example.test/photo.jpg?signature=temporary',
            $disk->url('photo.jpg'),
        );
    }
}
