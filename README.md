# PikPak Web Storage

A Laravel Storage driver backed by Flysystem 3 and the PikPak Drive API.

The package registers the `pikpak` driver through Laravel package auto-discovery. The consuming application supplies a valid PikPak Bearer token; this package does not store, decrypt, refresh, or replace credentials.

## Requirements

- PHP 8.1 or later
- Laravel 10, 11, or 12
- Flysystem 3
- A PikPak Bearer token with permissions for the operations the application uses

## Install

Install the Packagist package with Composer:

```sh
composer require oh-my-gold/pikpak-web-storage:dev-master
```

Laravel discovers `PikPakWebStorageServiceProvider` automatically. If package discovery is disabled, add `OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider::class` to the application's providers list.

## Configure a disk

Add a disk to `config/filesystems.php`:

```php
'disks' => [
    'pikpak' => [
        'driver' => 'pikpak',
        'token' => env('PIKPAK_TOKEN'),
        'root_folder_id' => env('PIKPAK_ROOT_FOLDER_ID', ''),
        'path_prefix' => env('PIKPAK_PATH_PREFIX', ''),
        'throw' => true,
    ],
],
```

Store `PIKPAK_TOKEN` in the application's secret manager or environment, and keep it out of source control. An empty root folder ID selects the PikPak account root. The literal value `root` is normalized to the same account root. A non-empty root folder ID scopes ordinary paths to that folder.

`path_prefix` preserves a visible leading prefix in paths returned from a root listing. When an input begins with that exact prefix, it is removed before remote lookup. Inputs without it are still relative to `root_folder_id`, so `path_prefix` is not an access-control boundary.

## Use Laravel Storage

```php
use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('pikpak');
$disk->put('photos/example.txt', 'Stored in PikPak');
$contents = $disk->get('photos/example.txt');
$stream = $disk->readStream('photos/example.txt');
fclose($stream);
```

The driver implements the Flysystem 3 `FilesystemAdapter` operations: file and directory existence, reads and streams, writes and streams, listings, directory creation, move/copy, file metadata, visibility, and deletion. Laravel's `Storage::url()` uses the current PikPak content link. Flysystem checksum calls use its standard stream-based fallback. Listing requests follow every page and reject repeated page tokens. Same-name files of the requested kind are treated as ambiguous; the adapter fails instead of picking one.

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
- PikPak's current Drive API does not expose file visibility changes. `visibility()` reports private; requesting public visibility fails with a Flysystem exception instead of silently claiming success.
- PikPak does not expose a way to request a content URL with a caller-selected expiration time. The package therefore does not provide `temporaryUrl()` itself; Laravel applications can install a `buildTemporaryUrlsUsing()` callback if they have a separate URL service.
- The Drive API and temporary OSS upload protocol used here are based on the project's current integration behavior and are not a stable public PikPak developer contract. API changes may require a package update.
- The package does not implement refresh-token authentication, captcha handling, account management, sharing, or permanent deletion.

The package uses a bearer token and an optional root folder ID; it has no device ID setting. Its independent test suite runs with Laravel Testbench. The consuming application must protect its token and choose permissions appropriate to its read, upload, move, copy, and trash operations. Authentication failures stop with an exception; the package does not retry with another account or credential source.
