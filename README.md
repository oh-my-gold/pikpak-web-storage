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
```

All ordinary paths below are relative to `root_folder_path`. For example, with `/备份/项目` configured as the root, `photos/example.txt` refers to `/备份/项目/photos/example.txt` in PikPak. An empty directory path `''` means the configured disk root. Path segments `.`/`..`, empty interior segments, and control characters are rejected by the adapter; `/` and `.` are accepted as root paths.

### Files and directories

| Laravel call | Return on success | Usage |
| --- | --- | --- |
| `$disk->fileExists('photos/a.txt')` | `bool` | Check for a completed, non-trashed file. |
| `$disk->fileMissing('photos/a.txt')` | `bool` | Inverse of `fileExists()`. |
| `$disk->directoryExists('photos')` | `bool` | Check for a folder. Resolving a missing parent/configured root can throw. |
| `$disk->directoryMissing('photos')` | `bool` | Inverse of `directoryExists()`. |
| `$disk->exists('photos/a.txt')` | `bool` | Laravel/Flysystem check for a file or directory. |
| `$disk->missing('photos/a.txt')` | `bool` | Inverse of `exists()`. |
| `$disk->makeDirectory('photos/2026')` | `true` | Create every missing folder in the path. |
| `$disk->delete('photos/a.txt')` | `true` | Move one file to trash. Also accepts an array of file paths. |
| `$disk->deleteDirectory('photos/2026')` | `true` | Move the folder and its contents to trash and invalidate folder IDs. |
| `$disk->copy('photos/a.txt', 'archive/a.txt')` | `true` | Copy a file; create missing destination folders. |
| `$disk->move('photos/a.txt', 'archive/b.txt')` | `true` | Move/rename a file; create missing destination folders. |

`copy()` and `move()` operate on files. This adapter does not implement directory move/copy. Same-name entries of the requested kind are ambiguous and cause an exception, including duplicates on different listing pages. Listing requests follow every page and reject repeated page tokens.

### Writing and reading

| Laravel call | Return on success | Usage |
| --- | --- | --- |
| `$disk->put('photos/a.txt', 'Hello')` | `true` | Upload a string; also accepts a readable, seekable stream. |
| `$disk->writeStream('photos/a.txt', $stream)` | `true` | Upload from a readable, seekable stream; caller closes it. |
| `$disk->putFile('photos', $uploadedFile)` | `string` path | Laravel helper that uploads a local/uploaded file under a generated name. |
| `$disk->putFileAs('photos', $uploadedFile, 'a.jpg')` | `string` path | Upload a local/uploaded file under a specified name. |
| `$disk->get('photos/a.txt')` | `string` | Read the entire file into memory. |
| `$disk->json('data/settings.json')` | Decoded JSON | Laravel helper that reads the file and decodes JSON as associative arrays. |
| `$disk->readStream('photos/a.txt')` | `resource` | Open a readable content stream; caller closes it. |
| `$disk->download('photos/a.txt', 'download.txt')` | `StreamedResponse` | Return a download response from a Laravel controller. |
| `$disk->response('photos/a.txt', 'preview.txt')` | `StreamedResponse` | Return an inline response from a Laravel controller. |

Writes create a new remote file and upload session. They do not replace an existing same-name file atomically, so uploading to an occupied path can create duplicates and make subsequent path lookups ambiguous. Use unique paths, or explicitly resolve/delete the previous file before uploading again. Laravel's `append()` and `prepend()` perform a read followed by `put()` and have the same duplicate-name limitation; this package provides no in-place append or overwrite operation.

```php
$disk->put('photos/example.txt', 'Stored in PikPak');
$contents = $disk->get('photos/example.txt');

// Upload a local file using a seekable stream.
$input = fopen(storage_path('app/example.jpg'), 'rb');
if ($input === false) {
    throw new RuntimeException('Unable to open local file.');
}
try {
    $disk->writeStream('photos/example.jpg', $input);
} finally {
    fclose($input);
}

// Read without loading the whole file into memory.
$output = $disk->readStream('photos/example.jpg');
if (!is_resource($output)) {
    throw new RuntimeException('Unable to open remote stream.');
}
try {
    // Consume or copy the stream here.
} finally {
    fclose($output);
}

// In a controller receiving an uploaded file:
// $path = $disk->putFile('uploads', $request->file('file'));
// $path = $disk->putFileAs('uploads', $request->file('file'), 'unique-name.jpg');
// return $disk->download('photos/example.jpg', 'example.jpg');
```

### Listing and metadata

| Laravel call | Return on success | Usage |
| --- | --- | --- |
| `$disk->files('photos')` | `string[]` | Immediate file paths. Use `''` for the disk root. |
| `$disk->allFiles('photos')` | `string[]` | File paths recursively. |
| `$disk->directories('photos')` | `string[]` | Immediate directory paths. |
| `$disk->allDirectories('photos')` | `string[]` | Directory paths recursively. |
| `$disk->listContents('photos', false)` | `DirectoryListing` | Flysystem listing forwarded by Laravel; use `true` for recursion. |
| `$disk->size('photos/a.txt')` | `int` bytes | File size. |
| `$disk->mimeType('photos/a.txt')` | `string` | MIME type, defaulting to `application/octet-stream` when absent. |
| `$disk->lastModified('photos/a.txt')` | `int` Unix timestamp | Modification time; missing/unusable remote timestamps can cause an error. |
| `$disk->getVisibility('photos/a.txt')` | `'private'` | Current adapter visibility result. |
| `$disk->setVisibility('photos/a.txt', 'private')` | `true` | Accepts `private` or `public`; does not change PikPak permissions. |

```php
foreach ($disk->listContents('photos', true) as $entry) {
    $path = $entry->path();
    $isFile = $entry->isFile();
    $isDirectory = $entry->isDir();
}

$bytes = $disk->size('photos/example.jpg');
$mime = $disk->mimeType('photos/example.jpg');
$modifiedAt = $disk->lastModified('photos/example.jpg');
```

The adapter has no built-in `url()`, `temporaryUrl()`, or `temporaryUploadUrl()` implementation. `path()` only formats a path string; it does not provide a local file path or a PikPak download URL. Use `get()`, `readStream()`, `download()`, or `response()` to retrieve content. Laravel conveniences depend on the installed Laravel version and the host application's HTTP/container services.

### Direct file IDs

Use `@id/{file-id}` for `fileExists()`, reads, file metadata, and `delete()` when a stable file ID is already known:

```php
$exists = $disk->fileExists('@id/REMOTE_FILE_ID');
$contents = $disk->get('@id/REMOTE_FILE_ID');
$bytes = $disk->size('@id/REMOTE_FILE_ID');
// $disk->delete('@id/REMOTE_FILE_ID'); // Moves the file to trash.
```

Direct file ID lookups address the account object directly and bypass the configured folder path. The syntax is not supported for directory operations, write destinations, or the path-based helper methods below. Use `deleteById()` to trash a known folder ID.

### Error handling

The tables describe successful results. Laravel wrappers may return `false`/`null` for Flysystem exceptions when `throw` is disabled. This adapter also raises `RuntimeException` and `PikPakApiException` directly; those can propagate regardless of the `throw` setting. `sourceFileId()` is the exception: it catches lookup errors and returns `null`.

```php
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;

try {
    $contents = $disk->get('photos/example.txt');
} catch (PikPakApiException $error) {
    $httpStatus = $error->httpStatus;     // ?int
    $apiErrorCode = $error->apiErrorCode; // ?string, sanitized API error code
    // Handle authentication, permissions, or API errors in your application.
} catch (RuntimeException $error) {
    // Handle invalid/missing/ambiguous paths and other adapter errors.
}
```

## Complete adapter method reference

Access the package adapter and the Flysystem operator through Laravel:

```php
$adapter = $disk->getAdapter(); // PikPakFilesystemAdapter
$driver = $disk->getDriver();  // Flysystem FilesystemOperator
$config = new \League\Flysystem\Config();
```

The following lists every public operation declared by `PikPakFilesystemAdapter`. Direct adapter writes/mutations return `void` and throw on failure; Laravel Storage wrappers typically return a boolean. Direct metadata calls return `League\Flysystem\FileAttributes`, not a scalar.

| Adapter method | Return | Example / behavior |
| --- | --- | --- |
| `fileExists(string $path)` | `bool` | `$adapter->fileExists('photos/a.txt')`. Supports `@id/…`. |
| `directoryExists(string $path)` | `bool` | `$adapter->directoryExists('photos')`. |
| `write(string $path, string $contents, Config $config)` | `void` | `$adapter->write('photos/a.txt', 'Hello', $config)`. |
| `writeStream(string $path, resource $contents, Config $config)` | `void` | `$adapter->writeStream('photos/a.jpg', $input, $config)`. Caller closes the stream. |
| `read(string $path)` | `string` | `$adapter->read('photos/a.txt')`. Supports `@id/…`. |
| `readStream(string $path)` | `resource` | `$adapter->readStream('photos/a.jpg')`. Caller closes the stream. |
| `delete(string $path)` | `void` | `$adapter->delete('photos/a.txt')`. Trash a file; supports `@id/…`. |
| `deleteDirectory(string $path)` | `void` | `$adapter->deleteDirectory('photos/2026')`. Trash a folder and invalidate directory IDs. |
| `createDirectory(string $path, Config $config)` | `void` | `$adapter->createDirectory('photos/2026', $config)`. Create missing parents. |
| `move(string $source, string $destination, Config $config)` | `void` | `$adapter->move('photos/a.txt', 'archive/b.txt', $config)`. Move/rename a file. |
| `copy(string $source, string $destination, Config $config)` | `void` | `$adapter->copy('photos/a.txt', 'archive/a.txt', $config)`. Copy a file. |
| `listContents(string $path, bool $deep)` | `iterable<StorageAttributes>` | `$adapter->listContents('', true)`. Yields `FileAttributes` and `DirectoryAttributes`. |
| `fileSize(string $path)` | `FileAttributes` | `$adapter->fileSize('photos/a.txt')->fileSize()` yields `?int` bytes. |
| `mimeType(string $path)` | `FileAttributes` | `$adapter->mimeType('photos/a.txt')->mimeType()` yields a MIME string. |
| `lastModified(string $path)` | `FileAttributes` | `$adapter->lastModified('photos/a.txt')->lastModified()` yields `?int`. |
| `visibility(string $path)` | `FileAttributes` | `$adapter->visibility('photos/a.txt')->visibility()` yields `'private'`. |
| `setVisibility(string $path, string $visibility)` | `void` | `$adapter->setVisibility('photos/a.txt', 'private')`. Also accepts `public`; no remote ACL change. |
| `sourceFileId(string $path)` | `?string` | `$adapter->sourceFileId('photos/a.txt')`. Returns/caches a path's file ID, or `null` on any lookup error. |
| `pathOccupied(string $path)` | `bool` | `$adapter->pathOccupied('photos/a.txt')`. Checks any same-name file/folder, including incomplete uploads. |
| `pathIsUniqueTo(string $path, string $id)` | `bool` | `$adapter->pathIsUniqueTo('photos/a.txt', $id)`. True only for exactly one matching entry that is a file with that ID; includes incomplete entries. |
| `ensureFolderPathAndGetId(string $path)` | `string` | `$adapter->ensureFolderPathAndGetId('photos/2026')`. Create missing folders and return the final ID. Account root has no object ID and raises an error. |
| `deleteById(string $id)` | `void` | `$adapter->deleteById($id)`. Trash a file/folder by its raw ID and invalidate folder cache. |
| `lastUploadedFileId()` | `string` | `$adapter->lastUploadedFileId()`. Last upload's metadata ID, or `''` before an upload. Does not prove that bytes uploaded successfully. |
| `lastUploadRequests()` | `array` | `$adapter->lastUploadRequests()`. Last upload's safe OSS request summaries, without credential values or signed query strings. |

```php
$fileId = $adapter->sourceFileId('photos/example.txt');
$occupied = $adapter->pathOccupied('photos/new-name.txt');
$folderId = $adapter->ensureFolderPathAndGetId('photos/2026');

if ($fileId !== null) {
    $unique = $adapter->pathIsUniqueTo('photos/example.txt', $fileId);
}

// Inspect immediately after an upload, including when the transfer throws.
$lastId = $adapter->lastUploadedFileId();
$requests = $adapter->lastUploadRequests();
```

`lastUploadedFileId()` and `lastUploadRequests()` are reset when an upload starts. `sourceFileId()` uses an instance-local file ID cache; the Laravel folder-cache TTL does not govern that file cache. `deleteById()` accepts raw IDs, so it can address objects outside the configured root.

### Constructing the adapter directly

`PikPakFilesystemAdapter::__construct()` accepts the following parameters in order:

| Parameter | Type | Default | Purpose |
| --- | --- | --- | --- |
| `$client` | `PikPakApiClient` | Required | Authenticated Drive API client. |
| `$rootFolderId` | `string` | `''` | Legacy starting folder ID. |
| `$keyPrefix` | `string` | `''` | Visible path prefix. |
| `$uploader` | `?PikPakOssUploader` | `null` | Optional OSS uploader instance. |
| `$rootFolderPath` | `string` | `''` | Root directory path relative to the starting ID. |
| `$folderCache` | `?Illuminate\Contracts\Cache\Repository` | `null` | Optional Laravel cache repository. |
| `$folderCacheTtl` | `int` | `3600` | Positive cache lifetime in seconds. |

For example, inside a Laravel application with its HTTP services available:

```php
use League\Flysystem\Filesystem;
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

$client = new PikPakApiClient(config('filesystems.disks.pikpak.token'));
$adapter = new PikPakFilesystemAdapter(
    $client,
    rootFolderPath: '/备份/项目',
    folderCache: app('cache')->store(),
    folderCacheTtl: 3600,
);
$filesystem = new Filesystem($adapter);
$filesystem->write('example.txt', 'Hello');
$contents = $filesystem->read('example.txt');
```

`keyPrefix` has the same meaning as the disk's `path_prefix`. `rootFolderId` is the legacy optional starting ID, `rootFolderPath` selects the path relative to it, and a null `uploader` selects the built-in OSS uploader. A null `folderCache` selects instance memory.

## Supporting classes

These lower-level classes are normally called by the driver. This reference includes every public method declared by the package, including its integration helpers.

| Class | Public constructor / method | Return and purpose |
| --- | --- | --- |
| `Http\PikPakApiClient` | `__construct(string $token, ?string $deviceId = null)` | Create a Drive API client. Non-empty Bearer token required; optional device header. |
| `Http\PikPakApiClient` | `get(string $url, array $query = [])` | `array`; issue a Drive API GET with query parameters. |
| `Http\PikPakApiClient` | `post(string $url, array $body = [])` | `array`; issue a Drive API POST with a JSON body. |
| `Http\PikPakApiClient` | `patch(string $url, array $body = [])` | `array`; issue a Drive API PATCH with a JSON body. |
| `Http\PikPakApiClient` | `cacheIdentity()` | `string`; credential hash used to isolate caches. |
| `Flysystem\FolderIdResolver` | `__construct(PikPakApiClient $client, ?Repository $cache = null, int $ttl = 3600)` | Create a resolver. `Repository` is the Illuminate cache contract; TTL must be positive. |
| `Flysystem\FolderIdResolver` | `resolve(array $segments, string $parentId = '', bool $create = false)` | `string`; resolve folder-name segments from a parent ID; optionally create missing folders. `resolve([])` returns the supplied parent ID, including `''` for the account root. |
| `Flysystem\FolderIdResolver` | `find(string $parentId, string $name)` | `?string`; look up one child folder; `null` if missing, exception if ambiguous. |
| `Flysystem\FolderIdResolver` | `invalidate()` | `void`; invalidate folder mappings for the resolver's credential namespace. |
| `Upload\PikPakOssUploader` | `upload(array $params, resource $stream, int $size)` | `void`; upload a seekable stream using the Drive API's temporary `resumable.params`; size is in bytes. |
| `Upload\PikPakOssUploader` | `lastRequests()` | `array`; safe request summaries for the latest OSS upload. |
| `PikPakWebStorageServiceProvider` | `boot()` | `void`; register the Laravel `pikpak` filesystem extension. Invoked automatically by Laravel. |
| `Exceptions\PikPakApiException` | `__construct(string $message, ?int $httpStatus = null, ?string $apiErrorCode = null)` | Runtime exception with public readonly `httpStatus` and `apiErrorCode` properties. |

Class names above are relative to `OhMyGold\PikPakWebStorage`. The HTTP client only permits HTTPS requests to `api-drive.mypikpak.com`. Low-level methods use Laravel's HTTP facade and require it to be initialized. `FolderIdResolver` expects already validated directory-name segments; ordinary applications should use the adapter's path methods.

```php
use OhMyGold\PikPakWebStorage\Flysystem\FolderIdResolver;

$resolver = new FolderIdResolver($client, app('cache')->store());
$folderId = $resolver->resolve(['备份', '项目']);
$childId = $resolver->find($folderId, 'photos'); // string or null
// $resolver->invalidate(); // Force future directory lookups to refresh.
```

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
