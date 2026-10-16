<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;
use Orchestra\Testbench\TestCase;

final class PikPakApiClientTest extends TestCase
{
    public function test_drive_api_requests_do_not_use_a_device_id(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-drive.mypikpak.com/*' => Http::response(['files' => []])]);

        (new PikPakApiClient('test-token'))->get('https://api-drive.mypikpak.com/drive/v1/files');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://api-drive.mypikpak.com/drive/v1/files'
            && !$request->hasHeader('x-device-id')
        );
    }

}
