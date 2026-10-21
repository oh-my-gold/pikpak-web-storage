<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider;
use Orchestra\Testbench\TestCase;

final class PikPakLaravelErrorsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PikPakWebStorageServiceProvider::class];
    }

    public function test_read_failure_obeys_laravels_throw_setting(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/drive/v1/files') {
                return Http::response(['files' => []]);
            }

            return Http::response([], 404);
        });

        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => false,
        ]);

        $this->assertNull($disk->get('missing.txt'));
    }

    public function test_read_failure_throws_a_flysystem_exception_when_configured(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/drive/v1/files') {
                return Http::response(['files' => []]);
            }

            return Http::response([], 404);
        });
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToReadFile::class);

        $disk->get('missing.txt');
    }

    public function test_write_failure_obeys_laravels_throw_setting(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/drive/v1/files') {
                return Http::response(['error' => 'temporarily_unavailable'], 503);
            }

            return Http::response([], 404);
        });
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => false,
        ]);

        $this->assertFalse($disk->put('file.txt', 'contents'));
    }

    public function test_write_failure_throws_a_flysystem_exception_when_configured(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/drive/v1/files') {
                return Http::response(['error' => 'temporarily_unavailable'], 503);
            }

            return Http::response([], 404);
        });
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToWriteFile::class);

        $disk->put('file.txt', 'contents');
    }
}
