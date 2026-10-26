# PikPak Web Storage

[English](README.md) · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md)

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
        'root_folder_path' => env('PIKPAK_ROOT_FOLDER_PATH', ''),
        'path_prefix' => env('PIKPAK_PATH_PREFIX', ''),
        'use_trash' => true,
        'throw' => true,
    ],
],
```

Store `PIKPAK_TOKEN` in the application’s secret manager or environment, and keep it out of source control. Set `PIKPAK_ROOT_FOLDER_PATH=/备份/项目` to use that folder as the disk root. An empty path or `/` selects the account root. The driver resolves the path to PikPak folder IDs lazily, one level at a time, with folder-only filters and pages of 500. Users do not need to look up IDs. Reads fail when the configured path is missing; writes and directory creation create missing folders.

The driver uses Laravel’s default cache store when available and falls back to adapter-instance memory if the store cannot be initialized or accessed. Optional disk settings are `folder_cache_store` (a Laravel cache store name) and `folder_cache_ttl` (a positive number of seconds, default `3600`). Cache entries contain successful folder lookups, are isolated by a hash of the bearer token, and expire after the configured TTL. Deleting a directory or calling `deleteById()` invalidates cached folder mappings, including descendants. External folder changes become visible after expiry; clear the selected cache store to refresh sooner. Same-name folders are checked across every result page and raise an error when ambiguous.

For compatibility, `root_folder_id` remains an optional starting folder ID (`''` or `root` means account root). If both root options are configured, `root_folder_path` is resolved under that starting ID.

`use_trash` defaults to `true`. Set it to `false` to permanently delete files and folders through PikPak's `files:batchDelete` API without moving them to trash. Each deletion sends one API request. This setting applies to Laravel `delete()`, `deleteDirectory()`, and the adapter's `deleteById()` helper.

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
| `$disk->delete('photos/a.txt')` | `true` | Delete one file according to `use_trash`. Also accepts an array of file paths. |
| `$disk->deleteDirectory('photos/2026')` | `true` | Delete the folder and its contents according to `use_trash`, then invalidate folder IDs. |
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
| `$disk->url('photos/a.txt')` | `string` | PikPak's current content URL; its expiry is chosen by PikPak. |

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
| `$disk->setVisibility('photos/a.txt', 'private')` | `true` | `private` is accepted; public visibility raises an exception because PikPak cannot change ACLs. |

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

Laravel's `$disk->url('photos/a.txt')` calls the adapter's `getUrl()` and returns PikPak's current content link; PikPak chooses its expiry. Flysystem checksum calls use its stream-based fallback. The adapter has no built-in `temporaryUrl()` or `temporaryUploadUrl()` implementation. `path()` only formats a path string; it does not provide a local file path. Use `get()`, `readStream()`, `download()`, or `response()` to retrieve content. Laravel conveniences depend on the installed Laravel version and the host application's HTTP/container services.

### Direct file IDs

Use `@id/{file-id}` for `fileExists()`, reads, file metadata, and `delete()` when a stable file ID is already known:

```php
$exists = $disk->fileExists('@id/REMOTE_FILE_ID');
$contents = $disk->get('@id/REMOTE_FILE_ID');
$bytes = $disk->size('@id/REMOTE_FILE_ID');
// $disk->delete('@id/REMOTE_FILE_ID'); // Uses the configured use_trash behavior.
```

Direct file ID lookups address the account object directly and bypass the configured folder path. The syntax is not supported for directory operations, write destinations, or the path-based helper methods below. Use `deleteById()` to delete a known file or folder ID according to `use_trash`.

### Error handling

The tables describe successful results. Laravel wrappers may return `false`/`null` for Flysystem exceptions when `throw` is disabled. The adapter wraps operation failures in the corresponding Flysystem exception. Laravel returns `false`/`null` for wrapped failures when `throw` is disabled; with `throw` enabled, the Flysystem exception propagates. The underlying `RuntimeException` or `PikPakApiException` is available as the previous exception. `sourceFileId()` is the exception: it catches lookup errors and returns `null`.

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
| `getUrl(string $path)` | `string` | `$adapter->getUrl('photos/a.txt')`. Current PikPak content link; the expiry is chosen by PikPak. |
| `getPresignedUploadUrl(array $params, int $expiresInSeconds = 900)` | `string` | Sign an existing upload session's `resumable.params` into a browser PUT URL. Valid for 1–3600 seconds. |
| `getBrowserUploadAuthorization(array $params, string $contentMd5, int $size)` | `array` | Return signed PUT headers and a signed POST form for browser upload clients that need UShow's POST-then-PUT fallback. |
| `share(string $path, bool $withPassword = false, int $expirationDays = 7)` | `array` | Create a share link for a file or folder. Returns `share_id`, `share_url`, `pass_code`, and `share_text`. PikPak generates protected-link codes. |
| `listShares()` | `array` | Return all raw share records, automatically following pages. |
| `revokeShare(string $shareId)` | `void` | Revoke one share using its ID. |
| `fileExists(string $path)` | `bool` | `$adapter->fileExists('photos/a.txt')`. Supports `@id/…`. |
| `directoryExists(string $path)` | `bool` | `$adapter->directoryExists('photos')`. |
| `write(string $path, string $contents, Config $config)` | `void` | `$adapter->write('photos/a.txt', 'Hello', $config)`. |
| `writeStream(string $path, resource $contents, Config $config)` | `void` | `$adapter->writeStream('photos/a.jpg', $input, $config)`. Caller closes the stream. |
| `read(string $path)` | `string` | `$adapter->read('photos/a.txt')`. Supports `@id/…`. |
| `readStream(string $path)` | `resource` | `$adapter->readStream('photos/a.jpg')`. Caller closes the stream. |
| `delete(string $path)` | `void` | `$adapter->delete('photos/a.txt')`. Delete according to `use_trash`; supports `@id/…`. |
| `deleteDirectory(string $path)` | `void` | `$adapter->deleteDirectory('photos/2026')`. Delete according to `use_trash` and invalidate directory IDs. |
| `createDirectory(string $path, Config $config)` | `void` | `$adapter->createDirectory('photos/2026', $config)`. Create missing parents. |
| `move(string $source, string $destination, Config $config)` | `void` | `$adapter->move('photos/a.txt', 'archive/b.txt', $config)`. Move/rename a file. |
| `copy(string $source, string $destination, Config $config)` | `void` | `$adapter->copy('photos/a.txt', 'archive/a.txt', $config)`. Copy a file. |
| `listContents(string $path, bool $deep)` | `iterable<StorageAttributes>` | `$adapter->listContents('', true)`. Yields `FileAttributes` and `DirectoryAttributes`. |
| `fileSize(string $path)` | `FileAttributes` | `$adapter->fileSize('photos/a.txt')->fileSize()` yields `?int` bytes. |
| `mimeType(string $path)` | `FileAttributes` | `$adapter->mimeType('photos/a.txt')->mimeType()` yields a MIME string. |
| `lastModified(string $path)` | `FileAttributes` | `$adapter->lastModified('photos/a.txt')->lastModified()` yields `?int`. |
| `visibility(string $path)` | `FileAttributes` | `$adapter->visibility('photos/a.txt')->visibility()` yields `'private'`. |
| `setVisibility(string $path, string $visibility)` | `void` | `$adapter->setVisibility('photos/a.txt', 'private')`. `public` raises a Flysystem exception. |
| `sourceFileId(string $path)` | `?string` | `$adapter->sourceFileId('photos/a.txt')`. Returns/caches a path's file ID, or `null` on any lookup error. |
| `getFileById(string $id, array $query = [], array $extraHeaders = [])` | `array` | Fetch Drive metadata by stable file or folder ID. |
| `getFileByIdWithStatus(string $id, array $query = [], array $extraHeaders = [])` | `array` | Fetch Drive metadata and the HTTP response status by stable ID. |
| `createUploadSession(string $parentId, string $name, int $size, string $sha1)` | `array` | Create a resumable file session; returns `data` and HTTP `status`. |
| `createFolderBelowId(string $parentId, string $name)` | `array` | Create one folder below a server-owned folder ID and return its provider record. |
| `listChildren(string $parentId, bool $includeIncomplete = false)` | `array` | Fetch all paginated child records; include incomplete uploads when requested. |
| `occupiedChildInFolder(string $parentId, string $name)` | `?array` | Find a matching file or folder from one complete listing page; throws if a next page makes absence uncertain. |
| `fileIdIncludingIncomplete(string $path)` | `?string` | Find a file by path including uploads still processing; throws for ambiguous paths. |
| `filePhaseAtPath(string $id, string $path)` | `?string` | Return the upload phase if the ID still identifies the file at that path. |
| `fileIsCompleteAtPath(string $id, string $path)` | `bool` | Check that the file at a path has reached `PHASE_TYPE_COMPLETE`. |
| `pathOccupied(string $path)` | `bool` | `$adapter->pathOccupied('photos/a.txt')`. Checks any same-name file/folder, including incomplete uploads. |
| `pathIsUniqueTo(string $path, string $id)` | `bool` | `$adapter->pathIsUniqueTo('photos/a.txt', $id)`. True only for exactly one matching entry that is a file with that ID; includes incomplete entries. |
| `ensureFolderPathAndGetId(string $path)` | `string` | `$adapter->ensureFolderPathAndGetId('photos/2026')`. Create missing folders and return the final ID. Account root has no object ID and raises an error. |
| `ensureFolderBelowId(string $parentId, string $relativeDirectory)` | `string` | Create nested folders below a server-owned ID and return the final folder ID. |
| `deleteById(string $id)` | `void` | `$adapter->deleteById($id)`. Delete a file/folder by its raw ID according to `use_trash`; invalidate folder cache. |
| `permanentlyDeleteById(string $id, array $expected = [])` | `void` | Verify a file ID and optional `parent_id`/`name` identity, move it to trash when needed, then permanently delete it. Missing IDs are treated as already cleaned up. |
| `lastUploadedFileId()` | `string` | `$adapter->lastUploadedFileId()`. Last upload's metadata ID, or `''` before an upload. Does not prove that bytes uploaded successfully. |
| `lastUploadRequests()` | `array` | `$adapter->lastUploadRequests()`. Last upload's safe OSS request summaries, without credential values or signed query strings. |

### Get a browser upload URL

`getPresignedUploadUrl()` signs the `resumable.params` from an upload session that your application has already created. It returns a single PUT URL as a string; it does not create the PikPak file or upload session. The default expiry is 15 minutes. You can pass an expiry from 1 to 3600 seconds.

`getBrowserUploadAuthorization()` preserves clients that use UShow's browser transfer fallback: it returns both a PUT URL with signed headers and a POST URL with signed form fields. Pass the MD5 in base64 and the exact byte size. It does not create the upload session. Keep the existing server-side session and completion tracking; treat the returned authorization as short-lived.

```php
$uploadParams = $metadata['resumable']['params'];
$md5 = base64_encode(md5($contents, true));
$authorization = $adapter->getBrowserUploadAuthorization($uploadParams, $md5, strlen($contents));

return response()->json(['upload' => $authorization]);
```

For UShow, keep the existing backend flow that creates the PikPak upload session and tracks its file and session IDs, then return the signed URL to the browser. The package can be used directly if UShow already registers its own `pikpak` driver:

```php
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

$adapter = new PikPakFilesystemAdapter(new PikPakApiClient($pikpakToken));
$uploadParams = $metadata['resumable']['params']; // From UShow's existing upload-session request.
$uploadUrl = $adapter->getPresignedUploadUrl($uploadParams);

return response()->json([
    'fileId' => $metadata['file']['id'],
    'sessionId' => $sessionId,
    'uploadUrl' => $uploadUrl,
]);
```

The browser sends the file bytes in one PUT request:

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

Continue UShow's completion flow only after the PUT succeeds. The PikPak OSS endpoint must allow CORS from the UShow origin for `PUT` and the `Content-Type` header. This helper signs one PUT; it does not create multipart or chunked upload URLs. Treat the signed URL as temporary authorization and avoid logging or storing it after the upload flow.

### Create, list, and revoke shares

`share()` accepts a file or folder path and returns `share_id`, `share_url`, `pass_code`, and `share_text`. It defaults to seven days. Without a password, anyone who has the link can access the content. With a password, PikPak generates a pass code and returns it as `pass_code`; this method does not accept a custom code.

```php
$adapter = Storage::disk('pikpak')->getAdapter();

$share = $adapter->share('photos/example.txt'); // No password; expires in 7 days.
$url = $share['share_url'];

$protected = $adapter->share('photos', true, 30); // Generated code; expires in 30 days.
$protectedUrl = $protected['share_url'];
$passCode = $protected['pass_code'];
```

The account root cannot be shared. A configured disk root can be shared by passing an empty path. The token must have PikPak Share permission.

`listShares()` returns raw share records and automatically follows every page. `revokeShare()` takes a `share_id`; revoking a link does not delete its file or folder.

```php
$shares = $adapter->listShares();
foreach ($shares as $share) {
    echo $share['share_id'] . ': ' . $share['share_url'] . PHP_EOL;
}

$adapter->revokeShare('share-id-to-revoke');
```

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

`PikPakFilesystemAdapter::__construct()` accepts these parameters in order:

| Parameter | Type | Default | Purpose |
| --- | --- | --- | --- |
| `$client` | `PikPakApiClient` | Required | Authenticated Drive API client. |
| `$rootFolderId` | `string` | `''` | Legacy starting folder ID. |
| `$keyPrefix` | `string` | `''` | Visible path prefix. |
| `$uploader` | `?PikPakOssUploader` | `null` | Optional OSS uploader instance. |
| `$rootFolderPath` | `string` | `''` | Root path relative to the starting ID. |
| `$folderCache` | `?Illuminate\Contracts\Cache\Repository` | `null` | Optional Laravel cache repository. |
| `$folderCacheTtl` | `int` | `3600` | Positive cache lifetime in seconds. |
| `$useTrash` | `bool` | `true` | Use the trash endpoint for deletes; `false` permanently deletes through `files:batchDelete`. |

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

The adapter accepts `PikPakApiClientContract`. Implement this interface when the application needs to keep its own per-account token lookup or API error translation while using the package's filesystem and Drive helpers. The interface provides `cacheIdentity()`, `get()`, `getWithStatus()`, `post()`, `postWithStatus()`, and `patch()`.

## Supporting classes

These lower-level classes are normally called by the driver. This reference includes every public method declared by the package, including its integration helpers.

| Class | Public constructor / method | Return and purpose |
| --- | --- | --- |
| `Http\PikPakApiClient` | `__construct(string $token)` | Create a Drive API client. A non-empty Bearer token is required. |
| `Http\PikPakApiClientContract` | `cacheIdentity()` | `string`; credential hash used to isolate caches. |
| `Http\PikPakApiClientContract` | `get(string $url, array $query = [], array $extraHeaders = [])` | `array`; issue a Drive API GET with query parameters. |
| `Http\PikPakApiClientContract` | `getWithStatus(string $url, array $query = [], array $extraHeaders = [])` | `array{data: array, status: int}`; GET response data and HTTP status. |
| `Http\PikPakApiClientContract` | `post(string $url, array $body = [], array $extraHeaders = [])` | `array`; issue a Drive API POST with a JSON body. |
| `Http\PikPakApiClientContract` | `postWithStatus(string $url, array $body = [], array $extraHeaders = [])` | `array{data: array, status: int}`; POST response data and HTTP status. |
| `Http\PikPakApiClientContract` | `patch(string $url, array $body = [], array $extraHeaders = [])` | `array`; issue a Drive API PATCH with a JSON body. |
| `Http\PikPakApiClient` | `cacheIdentity()` | `string`; credential hash used to isolate caches. |
| `Flysystem\FolderIdResolver` | `__construct(PikPakApiClientContract $client, ?Repository $cache = null, int $ttl = 3600)` | Create a resolver. `Repository` is the Illuminate cache contract; TTL must be positive. |
| `Flysystem\FolderIdResolver` | `resolve(array $segments, string $parentId = '', bool $create = false)` | `string`; resolve folder-name segments from a parent ID; optionally create missing folders. `resolve([])` returns the supplied parent ID, including `''` for the account root. |
| `Flysystem\FolderIdResolver` | `find(string $parentId, string $name)` | `?string`; look up one child folder; `null` if missing, exception if ambiguous. |
| `Flysystem\FolderIdResolver` | `invalidate()` | `void`; invalidate folder mappings for the resolver's credential namespace. |
| `Upload\PikPakOssUploader` | `upload(array $params, resource $stream, int $size)` | `void`; upload a seekable stream using the Drive API's temporary `resumable.params`; size is in bytes. |
| `Upload\PikPakOssUploader` | `getPresignedUploadUrl(array $params, int $expiresInSeconds = 900)` | `string`; create a signed browser PUT URL for an existing upload session. |
| `Upload\PikPakOssUploader` | `getBrowserUploadAuthorization(array $params, string $contentMd5, int $size)` | `array`; create signed PUT headers and POST form fields for browser uploads. |
| `Upload\PikPakOssUploader` | `lastRequests()` | `array`; safe request summaries for the latest OSS upload. |
| `PikPakWebStorageServiceProvider` | `boot()` | `void`; register the Laravel `pikpak` filesystem extension. Invoked automatically by Laravel. |
| `Exceptions\PikPakApiException` | `__construct(string $message, ?int $httpStatus = null, ?string $apiErrorCode = null)` | Runtime exception with public readonly `httpStatus` and `apiErrorCode` properties. |

Class names above are relative to `OhMyGold\PikPakWebStorage`. The built-in HTTP client only permits HTTPS requests to `api-drive.mypikpak.com`. Low-level methods use Laravel's HTTP facade and require it to be initialized. `FolderIdResolver` expects already validated directory-name segments; ordinary applications should use the adapter's path methods.

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
- `delete()` and `deleteDirectory()` move targets to PikPak trash through `files:batchTrash` by default. Set disk option `use_trash` to `false` to permanently delete with one `files:batchDelete` request. Deleting the configured root folder acts on that folder; deleting the account root fails because it has no remote object ID.
- `copy()` uses the PikPak batch-copy operation. If the destination name differs, the package renames the copied object after resolving its new ID; if the API does not return an ID and the new copy cannot be uniquely identified, the operation raises an exception.
- PikPak's current Drive API does not expose file visibility changes. `visibility()` reports private; requesting public visibility fails with a Flysystem exception instead of silently claiming success.
- PikPak does not expose a way to request a content URL with a caller-selected expiration time. The package therefore does not provide `temporaryUrl()` itself; Laravel applications can install a `buildTemporaryUrlsUsing()` callback if they have a separate URL service.
- The Drive API and temporary OSS upload protocol used here are based on the project's current integration behavior and are not a stable public PikPak developer contract. API changes may require a package update.
- Share links can be created, listed, and revoked. Protected-link pass codes are generated by PikPak; custom pass codes are not supported.

The adapter uses a bearer token and an optional legacy root folder ID. The consuming application must protect its token and choose permissions appropriate to its read, upload, move, copy, delete, and share operations. Authentication failures stop with an exception; the package does not retry with another account or credential source.

## Run the test suite

Install the development dependencies and run the mocked integration tests:

```sh
composer install
composer test
```
