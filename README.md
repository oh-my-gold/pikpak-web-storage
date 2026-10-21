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
        'root_folder_id' => env('PIKPAK_ROOT_FOLDER_ID', ''),
        'path_prefix' => env('PIKPAK_PATH_PREFIX', ''),
        'use_trash' => env('PIKPAK_USE_TRASH', true),
        'throw' => true,
    ],
],
```

Store `PIKPAK_TOKEN` in the application's secret manager or environment, and keep it out of source control. An empty root folder ID selects the PikPak account root. The literal value `root` is normalized to the same account root. A non-empty root folder ID scopes ordinary paths to that folder.

`path_prefix` preserves a visible leading prefix in paths returned from a root listing. When an input begins with that exact prefix, it is removed before remote lookup. Inputs without it are still relative to `root_folder_id`, so `path_prefix` is not an access-control boundary.

`use_trash` defaults to `true`. With the default, `delete()`, `deleteDirectory()`, and `deleteById()` move items to PikPak trash. Set it to `false` to permanently delete items directly through PikPak's `files:batchDelete` endpoint without first moving them to trash.

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
$share = $adapter->share('photos/example.txt');
$shares = $adapter->listShares();
```

Additional methods are `pathIsUniqueTo($path, $id)`, `deleteById($id)`, `lastUploadedFileId()`, `getPresignedUploadUrl($params, $expiresInSeconds = 900)`, `share($path, $withPassword = false, $expirationDays = 7)`, `listShares()`, and `revokeShare($shareId)`. `lastUploadRequests()` contains safe request summaries without signed query strings or temporary credential values.

### Get a browser upload URL

`getPresignedUploadUrl()` takes the `resumable.params` from an upload session that your application has already created and returns one signed PUT URL as a string. It does not create a PikPak file or upload session. The URL expires after 15 minutes by default; pass a value from 1 to 3600 seconds to choose another lifetime.

UShow can keep its current flow for creating the session and tracking `fileId` and `sessionId`, then obtain the browser URL on the backend. Install this package in UShow. If UShow already registers its own `pikpak` Laravel driver, instantiate this package's adapter directly so the two drivers do not compete for the same driver name:

```php
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

$adapter = new PikPakFilesystemAdapter(new PikPakApiClient($pikpakToken, $deviceId));
$uploadParams = $metadata['resumable']['params']; // From UShow's existing UPLOAD_TYPE_RESUMABLE request.
$uploadUrl = $adapter->getPresignedUploadUrl($uploadParams);

return response()->json([
    'fileId' => $metadata['file']['id'],
    'sessionId' => $sessionId,
    'uploadUrl' => $uploadUrl,
]);
```

The frontend sends the file bytes directly to the URL with a PUT request and the same content type used for signing:

```javascript
const response = await fetch(uploadUrl, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/octet-stream' },
    body: file,
});

if (!response.ok) {
    throw new Error(`PikPak upload failed (HTTP ${response.status})`);
}
```

Only continue UShow's upload-completion flow after the PUT response succeeds. The PikPak OSS endpoint must allow browser CORS requests from the UShow origin for `PUT` and the `Content-Type` header. This helper signs one PUT; it does not provide multipart or chunked upload URLs. Treat the signed URL as temporary authorization: do not log it or store it beyond the upload flow.

### Create a share link

`share()` accepts a file or folder path and returns the PikPak share response fields `share_id`, `share_url`, `pass_code`, and `share_text`. The default link expires after 7 days. Pass `true` as the second argument to ask PikPak to generate a password-protected link; the generated code is returned as `pass_code`. A link created without a password does not require a code, and anyone who obtains that link can access the shared content.

```php
$adapter = Storage::disk('pikpak')->getAdapter();

// No password; expires after 7 days.
$share = $adapter->share('photos/example.txt');
$url = $share['share_url'];

// PikPak generates the pass code; expires after 30 days.
$protectedShare = $adapter->share('photos/example.txt', true, 30);
$protectedUrl = $protectedShare['share_url'];
$passCode = $protectedShare['pass_code'];
```

The password is generated by PikPak and cannot be chosen through this method. The account token must have PikPak's Share permission. The account root cannot be shared; a configured `root_folder_id` can be shared by passing an empty path.

### List and revoke share links

`listShares()` returns the PikPak share records as an array, following all pages automatically. Each record uses PikPak's response fields, including `share_id`, `title`, `share_url`, `share_status`, and expiration metadata when provided. `revokeShare($shareId)` revokes one share by its `share_id`; an empty ID is rejected.

```php
$adapter = Storage::disk('pikpak')->getAdapter();

$shares = $adapter->listShares();
foreach ($shares as $share) {
    echo $share['share_id'] . ': ' . $share['share_url'] . PHP_EOL;
}

// Use the exact share ID you intend to revoke.
$adapter->revokeShare('share-id-to-revoke');
```

Both methods require PikPak's Share permission. Revoking a share link does not delete its underlying file or folder.

## PikPak behavior and limits

- Writes create file metadata and an upload session through the Drive API, then transfer the bytes directly to the temporary OSS endpoint. Files up to 10 MiB use one signed PUT; larger files use 10 MiB multipart parts.
- Reads use the current PikPak content link and return a stream for `readStream()`.
- `delete()` and `deleteDirectory()` follow the disk's `use_trash` option: by default they move targets to PikPak trash through `files:batchTrash`; with `use_trash` set to `false`, they permanently delete targets through `files:batchDelete`. Deleting the configured root folder applies to that folder. Deleting the account root fails because it has no remote object ID.
- `copy()` uses the PikPak batch-copy operation. If the destination name differs, the package renames the copied object after resolving its new ID; if the API does not return an ID and the new copy cannot be uniquely identified, the operation raises an exception.
- PikPak's current Drive API does not expose file visibility changes. `visibility()` reports private and `setVisibility()` accepts the Flysystem values without changing remote ACLs.
- The Drive API and temporary OSS upload protocol used here are based on the project's current integration behavior and are not a stable public PikPak developer contract. API changes may require a package update.
- The package can list and revoke share links, but does not set a custom share password, implement refresh-token authentication, handle captcha, or manage accounts.

The consuming application must protect its token and choose permissions appropriate to its read, upload, move, copy, delete, and share operations. Authentication failures stop with an exception; the package does not retry with another account or credential source.

## Run the test suite

Install the package's development dependencies and run its mocked integration tests:

```sh
composer install
composer test
```

The CI workflow runs the suite against Laravel 10, 11, and 12 dependency sets, including the minimum supported PHP 8.1 runtime.
