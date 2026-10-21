<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Flysystem;

use Illuminate\Contracts\Cache\Repository;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;
use RuntimeException;
use Throwable;

/** Resolves directory names without requiring callers to know remote IDs. */
final class FolderIdResolver
{
    private const FILES = 'https://api-drive.mypikpak.com/drive/v1/files';

    private array $memory = [];
    private string $generation = '';
    private readonly string $namespace;

    public function __construct(
        private readonly PikPakApiClient $client,
        private ?Repository $cache = null,
        private readonly int $ttl = 3600,
    ) {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('PikPak folder cache TTL must be positive.');
        }
        $this->namespace = 'pikpak:folders:' . $client->cacheIdentity();
    }

    public function resolve(array $segments, string $parentId = '', bool $create = false): string
    {
        foreach ($segments as $name) {
            $id = $this->find($parentId, $name);
            if ($id === null && $create) {
                $response = $this->client->post(self::FILES, [
                    'kind' => 'drive#folder', 'parent_id' => $parentId, 'name' => $name,
                ]);
                $folder = $response['file'] ?? $response;
                $id = (string) ($folder['id'] ?? '');
                if ($id === '') {
                    $id = $this->find($parentId, $name);
                }
                if ($id === null || $id === '') {
                    throw new RuntimeException('PikPak folder creation failed.');
                }
                $this->remember($this->key($parentId, $name), $id);
            }
            if ($id === null) {
                throw new RuntimeException('PikPak folder path was not found.');
            }
            $parentId = $id;
        }
        return $parentId;
    }

    public function find(string $parentId, string $name): ?string
    {
        $key = $this->key($parentId, $name);
        if ($this->cache !== null) {
            try {
                $id = $this->cache->get($key);
                if (is_string($id) && $id !== '') {
                    $this->memory[$key] = $id;
                    return $id;
                }
            } catch (Throwable) {
                $this->cache = null;
            }
        }
        if ($this->cache === null && isset($this->memory[$key])) {
            return $this->memory[$key];
        }

        $token = '';
        $seen = [];
        $found = null;
        do {
            $page = $this->client->get(self::FILES, [
                'parent_id' => $parentId,
                'limit' => 500,
                'page_token' => $token,
                'filters' => json_encode([
                    'kind' => ['eq' => 'drive#folder'],
                    'trashed' => ['eq' => false],
                    'phase' => ['eq' => 'PHASE_TYPE_COMPLETE'],
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach (($page['files'] ?? []) as $folder) {
                if (!is_array($folder) || ($folder['kind'] ?? '') !== 'drive#folder'
                    || ($folder['trashed'] ?? false) || ($folder['name'] ?? '') !== $name) {
                    continue;
                }
                if ($found !== null) {
                    throw new RuntimeException('PikPak path is ambiguous.');
                }
                $found = (string) ($folder['id'] ?? '');
                if ($found === '') {
                    throw new RuntimeException('PikPak folder has no remote identity.');
                }
            }
            $token = (string) ($page['next_page_token'] ?? '');
            if ($token !== '' && isset($seen[$token])) {
                throw new RuntimeException('PikPak pagination token repeated.');
            }
            $seen[$token] = true;
        } while ($token !== '');

        if ($found !== null) {
            $this->remember($key, $found);
        }
        return $found;
    }

    /** Rotate the shared namespace after deletion so other instances miss stale IDs. */
    public function invalidate(): void
    {
        $this->memory = [];
        $this->generation = bin2hex(random_bytes(16));
        if ($this->cache !== null) {
            try {
                if (!$this->cache->forever($this->namespace . ':generation', $this->generation)) {
                    $this->cache = null;
                }
            } catch (Throwable) {
                $this->cache = null;
            }
        }
    }

    private function key(string $parentId, string $name): string
    {
        if ($this->cache !== null) {
            try {
                $generation = (string) $this->cache->get($this->namespace . ':generation', '');
                if ($generation !== $this->generation) {
                    $this->memory = [];
                    $this->generation = $generation;
                }
            } catch (Throwable) {
                $this->cache = null;
            }
        }
        return $this->namespace . ':' . $this->generation . ':' . hash('sha256', $parentId . "\0" . $name);
    }

    private function remember(string $key, string $id): void
    {
        $this->memory[$key] = $id;
        if ($this->cache !== null) {
            try {
                if (!$this->cache->put($key, $id, $this->ttl)) {
                    $this->cache = null;
                }
            } catch (Throwable) {
                $this->cache = null;
            }
        }
    }
}
