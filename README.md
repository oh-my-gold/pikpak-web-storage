# PikPak Web Storage

A Laravel Storage driver backed by Flysystem 3 and the PikPak Drive API.

The package registers the `pikpak` driver through Laravel package auto-discovery. The consuming application supplies a valid PikPak Bearer token; this package does not store, decrypt, refresh, or replace credentials.

## Requirements

- PHP 8.1 or later
- Laravel 10, 11, or 12
- Flysystem 3
- A PikPak Bearer token with permissions for the operations the application uses

## Install from this repository

Until a tagged release is published, add the repository and package to the consuming application's composer.json:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/oh-my-gold/pikpak-web-storage" }
  ],
  "require": {
    "oh-my-gold/pikpak-web-storage": "dev-master"
  }
}
```

Then run `composer update oh-my-gold/pikpak-web-storage`. Laravel discovers `PikPakWebStorageServiceProvider` automatically. If package discovery is disabled, add `OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider::class` to the application's providers list.

## Configure a disk

Add a disk to `config/filesystems.php`:

```php
'disks' => [
    'pikpak' => [
        'driver' => 'pikpak',
        'token' => env('PIKPAK_TOKEN'),
        'device_id' => env('PIKPAK_DEVICE_ID'),
        'root_folder_path' => env('PIKPAK_ROOT_FOLDER_PATH', ''),
        'path_prefix' => env('PIKPAK_PATH_PREFIX', ''),
        'throw' => true,
    ],
],
```

Store `PIKPAK_TOKEN` in the application's secret manager or environment, and keep it out of source control. Set `PIKPAK_ROOT_FOLDER_PATH=/备份/项目` to use that directory as the disk root. An empty path or `/` selects the account root. Users do not need to look up folder IDs. Paths are resolved lazily through PikPak's folder listing API, using folder-only filters, pages of 500, and exact name matching. Reads fail when the configured path is missing; writes and directory creation create missing directories.

The driver automatically uses Laravel's default cache store when available, otherwise it uses memory for the current adapter instance. Cache store initialization, read, or write failures also fall back to memory. Optional disk settings are `folder_cache_store` (a Laravel cache store name) and `folder_cache_ttl` (a positive number of seconds, default `3600`). Only successful folder lookups are cached. Cache keys are isolated by a hash of the supplied credential and the parent ID/name; token rotation starts a new cache namespace. Resolving an already cached path requires no PikPak API requests, and cached parents are reused for new child paths.

Deleting a directory or using `deleteById()` invalidates the shared folder cache namespace, including cached descendants. External renames, moves, or deletions become visible after cache expiry; clear the selected cache store to refresh them earlier. Without a cache service, entries last for the adapter instance. Same-name folders are checked across every page before caching, so an ambiguous directory raises an error.

For compatibility, `root_folder_id` is still accepted as an optional starting folder ID (`''` and `root` mean the account root). If both options are present, `root_folder_path` is resolved relative to that starting ID.

`path_prefix` preserves a visible leading prefix in paths returned from a root listing. When an input begins with that exact prefix, it is removed before remote lookup. Inputs without it are still relative to the configured disk root, so `path_prefix` is not an access-control boundary.

## Use Laravel Storage

```php
use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('pikpak');
$disk->put('photos/example.txt', 'Stored in PikPak');
$contents = $disk->get('photos/example.txt');
$stream = $disk->readStream('photos/example.txt');
fclose($stream);
```

The driver implements Flysystem 3 file and directory existence, reads and streams, writes and streams, listings, directory creation, move/copy, file metadata, and deletion. Listing requests follow every page and reject repeated page tokens. Same-name files of the requested kind are treated as ambiguous; the adapter fails instead of picking one.

The adapter accepts `@id/{file-id}` for direct detail/read/delete operations when a path name is ambiguous or a stable file ID is already known.

### Adapter-specific helpers

Laravel's disk adapter exposes the Flysystem adapter through `getAdapter()`:

```php
$adapter = Storage::disk('pikpak')->getAdapter();
$fileId = $adapter->sourceFileId('photos/example.txt');
$occupied = $adapter->pathOccupied('photos/example.txt');
$folderId = $adapter->ensureFolderPathAndGetId('photos/2026');
$uploadRequests = $adapter->lastUploadRequests();
```

Additional methods are `pathIsUniqueTo($path, $id)`, `deleteById($id)`, and `lastUploadedFileId()`. `lastUploadRequests()` contains safe request summaries without signed query strings or temporary credential values.

## PikPak behavior and limits

- Writes create file metadata and an upload session through the Drive API, then transfer the bytes directly to the temporary OSS endpoint. Files up to 10 MiB use one signed PUT; larger files use 10 MiB multipart parts.
- Reads use the current PikPak content link and return a stream for `readStream()`.
- `delete()` and `deleteDirectory()` move targets to PikPak trash through `files:batchTrash`; they do not permanently erase data. Deleting the configured root folder moves that folder to trash. Deleting the account root fails because it has no remote object ID.
- `copy()` uses the PikPak batch-copy operation. If the destination name differs, the package renames the copied object after resolving its new ID; if the API does not return an ID and the new copy cannot be uniquely identified, the operation raises an exception.
- PikPak's current Drive API does not expose file visibility changes. `visibility()` reports private and `setVisibility()` accepts the Flysystem values without changing remote ACLs.
- The Drive API and temporary OSS upload protocol used here are based on the project's current integration behavior and are not a stable public PikPak developer contract. API changes may require a package update.
- The package does not implement refresh-token authentication, captcha handling, account management, sharing, or permanent deletion.

The consuming application must protect its token and choose permissions appropriate to its read, upload, move, copy, and trash operations. Authentication failures stop with an exception; the package does not retry with another account or credential source.

## Tests

Run `composer install` and `composer test` for the automated regression suite. HTTP requests are faked and no PikPak credentials are needed. The suite covers path scoping, pagination, ambiguity, cache reuse/expiry/isolation/failure, creation, deletion invalidation, legacy configuration, and Laravel service provider integration.

For an optional read-only integration check, supply `PIKPAK_API_TOKEN` through your environment and run `composer test:live`. It lists root folders, resolves one folder ID, and verifies cache reuse across resolver instances. It does not create, change, or delete remote files. An account with no root folders reports a skip.
