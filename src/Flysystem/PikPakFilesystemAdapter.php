<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Flysystem;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;
use OhMyGold\PikPakWebStorage\Upload\PikPakOssUploader;
use RuntimeException;
use Throwable;

final class PikPakFilesystemAdapter implements FilesystemAdapter
{
    private const FILES = 'https://api-drive.mypikpak.com/drive/v1/files';

    private array $cache = [];
    private array $lastUploadRequests = [];
    private string $lastUploadedFileId = '';
    private readonly string $rootFolderId;
    private readonly array $keyPrefix;
    private readonly array $rootFolderPath;
    private readonly FolderIdResolver $folders;
    private readonly PikPakOssUploader $uploader;

    public function __construct(
        private readonly PikPakApiClient $client,
        string $rootFolderId = '',
        string $keyPrefix = '',
        ?PikPakOssUploader $uploader = null,
        string $rootFolderPath = '',
        ?Repository $folderCache = null,
        int $folderCacheTtl = 3600,
    ) {
        $this->rootFolderId = $rootFolderId === 'root' ? '' : $rootFolderId;
        $this->keyPrefix = $this->segments($keyPrefix);
        $this->rootFolderPath = $this->segments($rootFolderPath);
        $this->folders = new FolderIdResolver($client, $folderCache, $folderCacheTtl);
        $this->uploader = $uploader ?? new PikPakOssUploader();
    }

    public function lastUploadedFileId(): string
    {
        return $this->lastUploadedFileId;
    }

    public function lastUploadRequests(): array
    {
        return $this->lastUploadRequests;
    }

    public function getUrl(string $path): string
    {
        return $this->contentUrlForFile($this->resolveFile($path));
    }

    public function fileExists(string $path): bool
    {
        return $this->runFlysystemOperation(function () use ($path): bool {
            if ($this->isIdPath($path)) {
                try {
                    $file = $this->details($this->idFromPath($path));
                } catch (PikPakApiException $exception) {
                    if ($exception->httpStatus === 404) {
                        return false;
                    }
                    throw $exception;
                }

                return ($file['kind'] ?? '') === 'drive#file' && !($file['trashed'] ?? false);
            }

            $parentId = $this->lookupParentId($path);
            return $parentId !== null && $this->findChild($parentId, $this->basename($path), 'drive#file') !== null;
        }, fn (Throwable $exception): UnableToCheckExistence => UnableToCheckExistence::forLocation($path, $exception));
    }

    /** Includes incomplete remote uploads so retries cannot silently create duplicate keys. */
    public function pathOccupied(string $path): bool
    {
        $parentId = $this->parentId($path);
        $name = $this->basename($path);
        foreach ($this->children($parentId, true) as $child) {
            if ((string) ($child['name'] ?? '') === $name
                && in_array(($child['kind'] ?? ''), ['drive#file', 'drive#folder'], true)) {
                return true;
            }
        }

        return false;
    }

    public function pathIsUniqueTo(string $path, string $id): bool
    {
        $parentId = $this->parentId($path);
        $name = $this->basename($path);
        $matches = [];
        foreach ($this->children($parentId, true) as $child) {
            if ((string) ($child['name'] ?? '') === $name
                && in_array(($child['kind'] ?? ''), ['drive#file', 'drive#folder'], true)) {
                $matches[] = $child;
            }
        }

        return count($matches) === 1
            && ($matches[0]['kind'] ?? '') === 'drive#file'
            && (string) ($matches[0]['id'] ?? '') === $id;
    }

    public function directoryExists(string $path): bool
    {
        return $this->runFlysystemOperation(function () use ($path): bool {
            if ($this->isRootPath($path)) {
                $this->rootId();
                return true;
            }

            $parentId = $this->lookupParentId($path);
            return $parentId !== null && $this->findChild($parentId, $this->basename($path), 'drive#folder') !== null;
        }, fn (Throwable $exception): UnableToCheckExistence => UnableToCheckExistence::forLocation($path, $exception));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->runFlysystemOperation(function () use ($path, $contents, $config): void {
            $this->assertConfiguredVisibility($path, $config, Config::OPTION_VISIBILITY);

            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to open temporary upload stream.');
            }

            try {
                $written = fwrite($stream, $contents);
                if ($written !== strlen($contents)) {
                    throw new RuntimeException('PikPak upload contents could not be buffered.');
                }
                rewind($stream);
                $this->writeStream($path, $stream, $config);
            } finally {
                fclose($stream);
            }
        }, fn (Throwable $exception): UnableToWriteFile => UnableToWriteFile::atLocation($path, 'PikPak upload failed.', $exception));
    }

    /** @param resource $contents */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->runFlysystemOperation(function () use ($path, $contents, $config): void {
            $this->assertConfiguredVisibility($path, $config, Config::OPTION_VISIBILITY);
            $this->lastUploadRequests = [];
            $this->lastUploadedFileId = '';
            if (!is_resource($contents)) {
                throw new RuntimeException('PikPak upload requires a stream.');
            }

            $parentId = $this->parentId($path, true);
            $size = $this->streamSize($contents);
            $sha1 = $this->streamHash($contents, 'sha1');
            $metadata = $this->client->post(self::FILES, [
                'kind' => 'drive#file',
                'name' => $this->basename($path),
                'size' => $size,
                'hash' => strtoupper($sha1),
                'upload_type' => 'UPLOAD_TYPE_RESUMABLE',
                'objProvider' => ['provider' => 'UPLOAD_TYPE_UNKNOWN'],
                'parent_id' => $parentId,
                'folder_type' => 'NORMAL',
            ]);

            $file = $metadata['file'] ?? $metadata;
            $id = (string) ($file['id'] ?? '');
            $uploadParams = $metadata['resumable']['params'] ?? null;
            if ($id === '') {
                throw new RuntimeException('PikPak did not return a file identity.');
            }
            if (!is_array($uploadParams)) {
                throw new RuntimeException('PikPak did not return upload session credentials.');
            }

            $this->lastUploadedFileId = $id;
            $this->cache[$this->cacheKey($parentId, $this->basename($path))] = $id;
            try {
                $this->uploader->upload($uploadParams, $contents, $size);
            } finally {
                $this->lastUploadRequests = $this->uploader->lastRequests();
            }
        }, fn (Throwable $exception): UnableToWriteFile => UnableToWriteFile::atLocation($path, 'PikPak upload failed.', $exception));
    }

    public function read(string $path): string
    {
        return $this->runFlysystemOperation(function () use ($path): string {
            $stream = $this->readStream($path);
            try {
                $contents = stream_get_contents($stream);
                if ($contents === false) {
                    throw new RuntimeException('PikPak file could not be read.');
                }

                return $contents;
            } finally {
                fclose($stream);
            }
        }, fn (Throwable $exception): UnableToReadFile => UnableToReadFile::fromLocation($path, 'PikPak read failed.', $exception));
    }

    /** @return resource */
    public function readStream(string $path)
    {
        return $this->runFlysystemOperation(function () use ($path) {
            $file = $this->resolveFile($path);
            if (($file['kind'] ?? '') !== 'drive#file') {
                throw new RuntimeException('PikPak file was not found.');
            }

            $url = $this->contentUrlForFile($file);

            try {
                $response = Http::withOptions(['stream' => true])->connectTimeout(10)->timeout(120)->get($url);
            } catch (Throwable) {
                throw new RuntimeException('PikPak content download failed.');
            }
            if (!$response->successful()) {
                throw new RuntimeException('PikPak content download failed.');
            }

            $stream = $response->toPsrResponse()->getBody()->detach();
            if (!is_resource($stream)) {
                throw new RuntimeException('PikPak content stream is unavailable.');
            }

            return $stream;
        }, fn (Throwable $exception): UnableToReadFile => UnableToReadFile::fromLocation($path, 'PikPak read failed.', $exception));
    }

    public function delete(string $path): void
    {
        $this->runFlysystemOperation(function () use ($path): void {
            $file = $this->resolveFile($path);
            $id = (string) ($file['id'] ?? '');
            $this->trash($id);
            unset($this->cache[$this->cacheKey((string) ($file['parent_id'] ?? ''), (string) ($file['name'] ?? ''))]);
        }, fn (Throwable $exception): UnableToDeleteFile => UnableToDeleteFile::atLocation($path, 'PikPak delete failed.', $exception));
    }

    public function deleteById(string $id): void
    {
        $this->trash($id);
        $this->folders->invalidate();
        foreach ($this->cache as $key => $cachedId) {
            if ($cachedId === $id) {
                unset($this->cache[$key]);
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        $this->runFlysystemOperation(function () use ($path): void {
            $folder = $this->resolveFolder($path);
            $this->trash((string) ($folder['id'] ?? ''));
            $this->folders->invalidate();
            $this->cache = [];
        }, fn (Throwable $exception): UnableToDeleteDirectory => UnableToDeleteDirectory::atLocation($path, 'PikPak directory deletion failed.', $exception));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->runFlysystemOperation(function () use ($path, $config): void {
            $this->assertConfiguredVisibility($path, $config, Config::OPTION_DIRECTORY_VISIBILITY);

            if ($this->isRootPath($path)) {
                $this->rootId(true);
                return;
            }

            $this->ensureFolderPathAndGetId($path);
        }, fn (Throwable $exception): UnableToCreateDirectory => UnableToCreateDirectory::atLocation($path, 'PikPak directory creation failed.', $exception));
    }

    /** Creates every folder in a relative path and returns its final remote ID. */
    public function ensureFolderPathAndGetId(string $path): string
    {
        $parentId = $this->folders->resolve($this->relativeSegments($path), $this->rootId(true), true);
        if ($parentId === '') {
            throw new RuntimeException('PikPak folder path has no remote identity.');
        }

        return $parentId;
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->runFlysystemOperation(function () use ($path, $visibility): void {
            if (!in_array($visibility, [Visibility::PRIVATE, Visibility::PUBLIC], true)) {
                InvalidVisibilityProvided::withVisibility($visibility, 'private or public');
            }

            if ($visibility === Visibility::PUBLIC) {
                throw UnableToSetVisibility::atLocation($path, 'PikPak does not support public file visibility.');
            }

            $this->resolveFile($path);
        }, fn (Throwable $exception): UnableToSetVisibility => UnableToSetVisibility::atLocation($path, 'PikPak visibility update failed.', $exception));
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->runFlysystemOperation(function () use ($path): FileAttributes {
            $file = $this->resolveFile($path);
            return new FileAttributes(
                $path,
                isset($file['size']) ? (int) $file['size'] : null,
                Visibility::PRIVATE,
                $this->timestamp($file['modified_time'] ?? null),
                (string) ($file['mime_type'] ?? 'application/octet-stream'),
            );
        }, fn (Throwable $exception): UnableToRetrieveMetadata => UnableToRetrieveMetadata::visibility($path, 'PikPak visibility lookup failed.', $exception));
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->runFlysystemOperation(function () use ($path): FileAttributes {
            $file = $this->resolveFile($path);
            return new FileAttributes($path, null, null, null, (string) ($file['mime_type'] ?? 'application/octet-stream'));
        }, fn (Throwable $exception): UnableToRetrieveMetadata => UnableToRetrieveMetadata::mimeType($path, 'PikPak MIME type lookup failed.', $exception));
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->runFlysystemOperation(function () use ($path): FileAttributes {
            $file = $this->resolveFile($path);
            return new FileAttributes($path, null, null, $this->timestamp($file['modified_time'] ?? null));
        }, fn (Throwable $exception): UnableToRetrieveMetadata => UnableToRetrieveMetadata::lastModified($path, 'PikPak modification time lookup failed.', $exception));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->runFlysystemOperation(function () use ($path): FileAttributes {
            $file = $this->resolveFile($path);
            return new FileAttributes($path, isset($file['size']) ? (int) $file['size'] : null);
        }, fn (Throwable $exception): UnableToRetrieveMetadata => UnableToRetrieveMetadata::fileSize($path, 'PikPak file size lookup failed.', $exception));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            yield from $this->listDirectoryContents($path, $deep, []);
        } catch (FilesystemException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->runFlysystemOperation(function () use ($source, $destination, $config): void {
            $this->assertConfiguredVisibility($destination, $config, Config::OPTION_VISIBILITY);

            $file = $this->resolveFile($source);
            $parentId = $this->parentId($destination, true);
            $id = (string) $file['id'];
            $this->client->post('https://api-drive.mypikpak.com/drive/v1/files:batchMove', [
                'ids' => [$id],
                'to' => ['parent_id' => $parentId],
            ]);
            $name = $this->basename($destination);
            if ((string) ($file['name'] ?? '') !== $name) {
                $this->rename($id, $name);
            }

            unset($this->cache[$this->cacheKey((string) ($file['parent_id'] ?? ''), (string) ($file['name'] ?? ''))]);
            $this->cache[$this->cacheKey($parentId, $name)] = $id;
        }, fn (Throwable $exception): UnableToMoveFile => UnableToMoveFile::fromLocationTo($source, $destination, $exception));
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->runFlysystemOperation(function () use ($source, $destination, $config): void {
            $this->assertConfiguredVisibility($destination, $config, Config::OPTION_VISIBILITY);

            $file = $this->resolveFile($source);
            $parentId = $this->parentId($destination, true);
            $destinationName = $this->basename($destination);
            $beforeIds = [];
            if ($destinationName !== (string) ($file['name'] ?? '')) {
                foreach ($this->children($parentId, true) as $child) {
                    $childId = (string) ($child['id'] ?? '');
                    if ($childId !== '') {
                        $beforeIds[$childId] = true;
                    }
                }
            }

            $response = $this->client->post('https://api-drive.mypikpak.com/drive/v1/files:batchCopy', [
                'ids' => [(string) $file['id']],
                'to' => ['parent_id' => $parentId],
            ]);

            if ($destinationName === (string) ($file['name'] ?? '')) {
                return;
            }

            $sourceId = (string) $file['id'];
            $copiedId = $this->copiedFileId($response, $sourceId);
            if ($copiedId !== null && isset($beforeIds[$copiedId])) {
                $copiedId = null;
            }
            if ($copiedId === null) {
                $newCopies = [];
                foreach ($this->children($parentId, true) as $child) {
                    if (($child['kind'] ?? '') === 'drive#file'
                        && (string) ($child['name'] ?? '') === (string) ($file['name'] ?? '')
                        && !isset($beforeIds[(string) ($child['id'] ?? '')])
                        && (string) ($child['id'] ?? '') !== $sourceId
                        && (string) ($child['id'] ?? '') !== '') {
                        $newCopies[] = $child;
                    }
                }
                if (count($newCopies) !== 1) {
                    throw new RuntimeException('PikPak copied file identity could not be resolved for rename.');
                }
                $copiedId = (string) $newCopies[0]['id'];
            }

            $this->rename($copiedId, $destinationName);
        }, fn (Throwable $exception): UnableToCopyFile => UnableToCopyFile::fromLocationTo($source, $destination, $exception));
    }

    public function sourceFileId(string $path): ?string
    {
        try {
            $parentId = $this->parentId($path);
            $cacheKey = $this->cacheKey($parentId, $this->basename($path));
            if (isset($this->cache[$cacheKey])) {
                return (string) $this->cache[$cacheKey];
            }

            $id = (string) ($this->resolveFile($path)['id'] ?? '');
            if ($id === '') {
                return null;
            }
            $this->cache[$cacheKey] = $id;
            return $id;
        } catch (Throwable) {
            return null;
        }
    }

    private function listDirectoryContents(string $path, bool $deep, array $visited): iterable
    {
        $atRoot = $this->isRootPath($path);
        $folder = $atRoot ? ['id' => $this->rootId()] : $this->resolveFolder($path);
        $parentId = (string) ($folder['id'] ?? '');
        $visitKey = $parentId === '' ? '@account-root' : $parentId;
        if (isset($visited[$visitKey])) {
            throw new RuntimeException('PikPak folder hierarchy contains a cycle.');
        }
        $visited[$visitKey] = true;

        $base = $atRoot ? implode('/', $this->keyPrefix) : trim($path, '/');
        foreach ($this->children($parentId) as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $childPath = ($base !== '' ? $base . '/' : '') . $name;
            if (($item['kind'] ?? '') === 'drive#folder') {
                yield new DirectoryAttributes($childPath, null, $this->timestamp($item['modified_time'] ?? null));
                if ($deep) {
                    yield from $this->listDirectoryContents($childPath, true, $visited);
                }
            } elseif (($item['kind'] ?? '') === 'drive#file') {
                yield new FileAttributes(
                    $childPath,
                    isset($item['size']) ? (int) $item['size'] : null,
                    null,
                    $this->timestamp($item['modified_time'] ?? null),
                    (string) ($item['mime_type'] ?? 'application/octet-stream'),
                );
            }
        }
    }

    private function resolveFile(string $path): array
    {
        if ($this->isIdPath($path)) {
            $file = $this->details($this->idFromPath($path));
            if (($file['kind'] ?? '') !== 'drive#file' || ($file['trashed'] ?? false)) {
                throw new RuntimeException('PikPak file was not found.');
            }
            return $file;
        }

        $file = $this->findChild($this->parentId($path), $this->basename($path), 'drive#file');
        if ($file === null) {
            throw new RuntimeException('PikPak file was not found.');
        }
        return $file;
    }

    private function resolveFolder(string $path): array
    {
        if ($this->isRootPath($path)) {
            return ['id' => $this->rootId(), 'kind' => 'drive#folder'];
        }

        $folder = $this->findChild($this->parentId($path), $this->basename($path), 'drive#folder');
        if ($folder === null) {
            throw new RuntimeException('PikPak folder was not found.');
        }
        return $folder;
    }

    private function parentId(string $path, bool $create = false): string
    {
        $segments = $this->relativeSegments($path);
        array_pop($segments);
        return $this->folders->resolve($segments, $this->rootId($create), $create);
    }

    private function rootId(bool $create = false): string
    {
        return $this->folders->resolve($this->rootFolderPath, $this->rootFolderId, $create);
    }

    private function lookupParentId(string $path): ?string
    {
        $segments = $this->relativeSegments($path);
        array_pop($segments);
        $parentId = $this->rootId();
        foreach ($segments as $segment) {
            $folderId = $this->folders->find($parentId, $segment);
            if ($folderId === null) {
                return null;
            }
            $parentId = $folderId;
        }

        return $parentId;
    }

    private function findChild(string $parentId, string $name, string $kind): ?array
    {
        if ($kind === 'drive#folder') {
            $id = $this->folders->find($parentId, $name);
            return $id === null ? null : ['id' => $id, 'name' => $name, 'kind' => $kind, 'parent_id' => $parentId];
        }

        $found = null;
        foreach ($this->children($parentId) as $child) {
            if (($child['kind'] ?? '') === $kind && (string) ($child['name'] ?? '') === $name) {
                if ($found !== null) {
                    throw new RuntimeException('PikPak path is ambiguous.');
                }
                $found = $child;
            }
        }

        return $found;
    }

    private function children(string $parentId, bool $includeIncomplete = false): array
    {
        $items = [];
        $token = '';
        $seen = [];
        do {
            $filters = ['trashed' => ['eq' => false]];
            if (!$includeIncomplete) {
                $filters['phase'] = ['eq' => 'PHASE_TYPE_COMPLETE'];
            }

            $query = [
                'parent_id' => $parentId,
                'limit' => 500,
                'thumbnail_size' => 'SIZE_SMALL',
                'with_audit' => 'true',
                'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'unzip_password' => '',
            ];
            if ($token !== '') {
                $query['page_token'] = $token;
            }

            $page = $this->client->get(self::FILES, $query);
            foreach (($page['files'] ?? []) as $file) {
                if (is_array($file) && !($file['trashed'] ?? false)) {
                    $items[] = $file;
                }
            }

            $token = (string) ($page['next_page_token'] ?? '');
            if ($token !== '' && isset($seen[$token])) {
                throw new RuntimeException('PikPak pagination token repeated.');
            }
            if ($token !== '') {
                $seen[$token] = true;
            }
        } while ($token !== '');

        return $items;
    }

    private function details(string $id): array
    {
        if ($id === '') {
            throw new RuntimeException('PikPak source identity is empty.');
        }

        return $this->client->get(self::FILES . '/' . rawurlencode($id));
    }

    private function rename(string $id, string $name): void
    {
        $this->client->patch(self::FILES . '/' . rawurlencode($id), ['name' => $name]);
    }

    private function trash(string $id): void
    {
        if ($id === '') {
            throw new RuntimeException('PikPak directory has no remote identity and cannot be trashed.');
        }
        $this->client->post('https://api-drive.mypikpak.com/drive/v1/files:batchTrash', ['ids' => [$id]]);
    }

    private function copiedFileId(array $response, string $sourceId): ?string
    {
        $candidates = [
            $response['file']['id'] ?? null,
            $response['files'][0]['id'] ?? null,
            $response['id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '' && (string) $candidate !== $sourceId) {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function downloadUrl(array $file): string
    {
        $link = $file['links']['application/octet-stream']['url'] ?? '';
        $url = (string) ($file['web_content_link'] ?? $link);
        return str_starts_with(strtolower($url), 'https://') ? $url : '';
    }

    private function contentUrlForFile(array $file): string
    {
        $url = $this->downloadUrl($file);
        if ($url === '') {
            $url = $this->downloadUrl($this->details((string) ($file['id'] ?? '')));
        }
        if ($url === '') {
            throw new RuntimeException('PikPak did not provide a download URL.');
        }

        return $url;
    }

    private function segments(string $path): array
    {
        $path = trim($path, '/');
        if ($path === '' || $path === '.') {
            return [];
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1f\x7f]/', $segment)) {
                throw new RuntimeException('Invalid PikPak path.');
            }
        }

        return $segments;
    }

    private function relativeSegments(string $path): array
    {
        $segments = $this->segments($path);
        if ($this->keyPrefix !== [] && array_slice($segments, 0, count($this->keyPrefix)) === $this->keyPrefix) {
            return array_slice($segments, count($this->keyPrefix));
        }

        return $segments;
    }

    private function isRootPath(string $path): bool
    {
        return $this->segments($path) === [] || $this->isPrefixPath($path);
    }

    private function isPrefixPath(string $path): bool
    {
        return $this->keyPrefix !== [] && $this->segments($path) === $this->keyPrefix;
    }

    private function basename(string $path): string
    {
        $segments = $this->segments($path);
        $name = (string) end($segments);
        if ($name === '' || $this->isPrefixPath($path)) {
            throw new RuntimeException('PikPak path has no filename.');
        }

        return $name;
    }

    private function isIdPath(string $path): bool
    {
        return str_starts_with($path, '@id/');
    }

    private function idFromPath(string $path): string
    {
        $id = substr($path, 4);
        if ($id === '' || str_contains($id, '/') || preg_match('/[\x00-\x1f\x7f]/', $id)) {
            throw new RuntimeException('PikPak source identity is invalid.');
        }

        return $id;
    }

    private function streamSize($stream): int
    {
        $stats = fstat($stream);
        if (!is_array($stats) || !isset($stats['size']) || (int) $stats['size'] < 0) {
            throw new RuntimeException('PikPak upload size is unavailable.');
        }

        return (int) $stats['size'];
    }

    private function streamHash($stream, string $algorithm): string
    {
        $position = ftell($stream);
        if ($position === false || rewind($stream) !== true) {
            throw new RuntimeException('PikPak upload stream must be seekable.');
        }

        $hash = hash_init($algorithm);
        hash_update_stream($hash, $stream);
        if (fseek($stream, $position) !== 0) {
            throw new RuntimeException('PikPak upload stream could not be restored.');
        }

        return hash_final($hash);
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function cacheKey(string $parentId, string $name): string
    {
        return $parentId . "\0" . $name;
    }

    private function assertConfiguredVisibility(string $path, Config $config, string $option): void
    {
        $visibility = $config->get($option);
        if ($visibility === null) {
            return;
        }

        if (!is_string($visibility)) {
            InvalidVisibilityProvided::withVisibility(get_debug_type($visibility), 'private or public');
        }

        $this->assertVisibilitySupported($path, $visibility);
    }

    private function assertVisibilitySupported(string $path, string $visibility): void
    {
        if (!in_array($visibility, [Visibility::PRIVATE, Visibility::PUBLIC], true)) {
            InvalidVisibilityProvided::withVisibility($visibility, 'private or public');
        }

        if ($visibility === Visibility::PUBLIC) {
            throw UnableToSetVisibility::atLocation($path, 'PikPak does not support public file visibility.');
        }
    }

    private function runFlysystemOperation(callable $operation, callable $failure): mixed
    {
        try {
            return $operation();
        } catch (FilesystemException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $failure($exception);
        }
    }
}
