<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider;
use Orchestra\Testbench\TestCase;

final class PikPakVisibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PikPakWebStorageServiceProvider::class];
    }

    public function test_public_visibility_is_rejected_instead_of_silently_ignored(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToSetVisibility::class);

        $disk->setVisibility('file.txt', Visibility::PUBLIC);
    }

    public function test_writes_reject_public_visibility_before_creating_a_remote_file(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToSetVisibility::class);

        $disk->put('file.txt', 'contents', ['visibility' => Visibility::PUBLIC]);
    }

    public function test_directory_creation_rejects_public_visibility_before_creating_a_folder(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToSetVisibility::class);

        $disk->getDriver()->createDirectory('folder', ['directory_visibility' => Visibility::PUBLIC]);
    }

    public function test_move_rejects_public_visibility_before_moving_a_file(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToSetVisibility::class);

        $disk->getDriver()->move('source.txt', 'target.txt', ['visibility' => Visibility::PUBLIC]);
    }

    public function test_copy_rejects_public_visibility_before_copying_a_file(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::build([
            'driver' => 'pikpak',
            'token' => 'test-token',
            'throw' => true,
        ]);

        $this->expectException(UnableToSetVisibility::class);

        $disk->getDriver()->copy('source.txt', 'target.txt', ['visibility' => Visibility::PUBLIC]);
    }
}
