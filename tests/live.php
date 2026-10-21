<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;
use OhMyGold\PikPakWebStorage\Flysystem\FolderIdResolver;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

$token = getenv('PIKPAK_API_TOKEN');
if (!is_string($token) || trim($token) === '') {
    fwrite(STDERR, "PIKPAK_API_TOKEN is required for the read-only integration check.\n");
    exit(1);
}

try {
    $http = new Factory();
    Http::swap($http);
    $http->record();
    $client = new PikPakApiClient($token);
    $page = $client->get('https://api-drive.mypikpak.com/drive/v1/files', [
        'parent_id' => '',
        'limit' => 500,
        'filters' => json_encode([
            'kind' => ['eq' => 'drive#folder'],
            'trashed' => ['eq' => false],
            'phase' => ['eq' => 'PHASE_TYPE_COMPLETE'],
        ], JSON_THROW_ON_ERROR),
    ]);
    $folder = null;
    foreach (($page['files'] ?? []) as $item) {
        if (($item['kind'] ?? '') === 'drive#folder' && !($item['trashed'] ?? false)) {
            $folder = $item;
            break;
        }
    }
    if ($folder === null) {
        echo "SKIP no root folders available for path resolution.\n";
        exit(0);
    }
    $cache = new Repository(new ArrayStore());
    $id = (new FolderIdResolver($client, $cache))->resolve([$folder['name']]);
    if ($id !== $folder['id']) {
        throw new RuntimeException('Resolved folder ID does not match the root listing.');
    }
    $before = $http->recorded()->count();
    $cachedId = (new FolderIdResolver($client, $cache))->resolve([$folder['name']]);
    if ($cachedId !== $id || $http->recorded()->count() !== $before) {
        throw new RuntimeException('Cache reuse did not avoid a PikPak API request.');
    }
    echo "PASS live folder resolution and cross-instance cache reuse; no remote mutations.\n";
} catch (PikPakApiException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    // Avoid logging tokens, directory names, content links, or transport internals.
    fwrite(STDERR, 'Live check failed (' . get_class($error) . ").\n");
    exit(1);
}
