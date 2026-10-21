<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage;

use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

final class PikPakWebStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('filesystem')->extend('pikpak', function ($app, array $config): LaravelFilesystemAdapter {
            $token = trim((string) ($config['token'] ?? ''));
            if ($token === '') {
                throw new InvalidArgumentException('A PikPak Bearer token is required for this disk.');
            }

            $rawUseTrash = $config['use_trash'] ?? true;
            if (!is_scalar($rawUseTrash)) {
                throw new InvalidArgumentException('The PikPak use_trash disk option must be a boolean value.');
            }
            $useTrash = filter_var($rawUseTrash, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($useTrash === null) {
                throw new InvalidArgumentException('The PikPak use_trash disk option must be a boolean value.');
            }

            $client = new PikPakApiClient(
                $token,
                isset($config['device_id']) ? (string) $config['device_id'] : null,
            );
            $adapter = new PikPakFilesystemAdapter(
                $client,
                (string) ($config['root_folder_id'] ?? ''),
                (string) ($config['path_prefix'] ?? ''),
                null,
                $useTrash,
            );
            $filesystemConfig = $config;
            unset($filesystemConfig['token']);

            return new LaravelFilesystemAdapter(
                new Filesystem($adapter, $filesystemConfig),
                $adapter,
                $filesystemConfig,
            );
        });
    }
}
