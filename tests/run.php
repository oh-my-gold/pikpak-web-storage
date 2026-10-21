<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function folder(string $id, string $name): array
{
    return ['id' => $id, 'name' => $name, 'kind' => 'drive#folder'];
}

function fakeDrive(array $directories): Factory
{
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fake(function ($request) use ($directories) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        return Http::response(['files' => $directories[$query['parent_id'] ?? ''] ?? []]);
    });
    return $http;
}

$tests = [];
$tests['configured root path resolves from account root'] = function (): void {
    $http = fakeDrive([
        '' => [folder('backup-id', '备份')],
        'backup-id' => [folder('project-id', '项目')],
        'project-id' => [folder('docs-id', 'documents')],
    ]);
    $adapter = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/备份/项目');
    same(true, $adapter->directoryExists('documents'));
    same(3, $http->recorded()->count());
};


function throws(string $message, callable $action): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        same($message, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected exception: ' . $message);
}

function resolver(?\Illuminate\Contracts\Cache\Repository $cache = null, string $token = 'account-a', int $ttl = 3600): \OhMyGold\PikPakWebStorage\Flysystem\FolderIdResolver
{
    return new \OhMyGold\PikPakWebStorage\Flysystem\FolderIdResolver(new PikPakApiClient($token), $cache, $ttl);
}

$tests['folder lookup uses directory filters and page size 500'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    same('a-id', resolver()->resolve(['a']));
    $request = $http->recorded()->first()[0];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
    same('500', $query['limit']);
    same(['eq' => 'drive#folder'], json_decode($query['filters'], true)['kind']);
    same(['eq' => false], json_decode($query['filters'], true)['trashed']);
};

$tests['instance memory avoids repeated API calls without Laravel cache'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')], 'a-id' => [folder('b-id', 'b')]]);
    $resolver = resolver();
    same('b-id', $resolver->resolve(['a', 'b']));
    same('b-id', $resolver->resolve(['a', 'b']));
    same(2, $http->recorded()->count());
};

$tests['shared cache reuses IDs across adapter instances'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')], 'a-id' => [folder('b-id', 'b')]]);
    $cache = new Repository(new ArrayStore());
    foreach ([1, 2] as $_) {
        $adapter = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/a', $cache);
        same(true, $adapter->directoryExists('b'));
    }
    same(2, $http->recorded()->count());
};

$tests['cached parent skips API work when resolving a new child'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')], 'a-id' => [folder('b-id', 'b')]]);
    $cache = new Repository(new ArrayStore());
    same('a-id', resolver($cache)->resolve(['a']));
    same('b-id', resolver($cache)->resolve(['a', 'b']));
    same(2, $http->recorded()->count());
};

$tests['cache isolates different credentials'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $cache = new Repository(new ArrayStore());
    same('a-id', resolver($cache, 'account-a')->resolve(['a']));
    same('a-id', resolver($cache, 'account-b')->resolve(['a']));
    same(2, $http->recorded()->count());
    same(false, str_contains(serialize($cache->getStore()), 'account-a'));
};

$tests['cache expires and resolves the path again'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $cache = new Repository(new ArrayStore());
    $resolver = resolver($cache, ttl: 10);
    same('a-id', $resolver->resolve(['a']));
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(11));
    try {
        same('a-id', $resolver->resolve(['a']));
        same(2, $http->recorded()->count());
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
};

$tests['unavailable cache falls back to instance memory'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $cache = new class(new ArrayStore()) extends Repository {
        public function get($key, $default = null): mixed { throw new RuntimeException('cache offline'); }
    };
    $resolver = resolver($cache);
    same('a-id', $resolver->resolve(['a']));
    same('a-id', $resolver->resolve(['a']));
    same(1, $http->recorded()->count());
};

$tests['failed cache writes retain in-memory results'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $cache = new class(new ArrayStore()) extends Repository {
        public function put($key, $value, $ttl = null): bool { throw new RuntimeException('cache offline'); }
    };
    $resolver = resolver($cache);
    same('a-id', $resolver->resolve(['a']));
    same('a-id', $resolver->resolve(['a']));
    same(1, $http->recorded()->count());
};

$tests['folder lookup follows pagination'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['files' => [], 'next_page_token' => 'page-2'])
        ->push(['files' => [folder('a-id', 'a')]]);
    same('a-id', resolver()->resolve(['a']));
    same(2, $http->recorded()->count());
    parse_str((string) parse_url($http->recorded()->last()[0]->url(), PHP_URL_QUERY), $query);
    same('page-2', $query['page_token']);
};

$tests['same-name folders on different pages are rejected and not cached'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['files' => [folder('a-id', 'a')], 'next_page_token' => 'page-2'])
        ->push(['files' => [folder('other-a-id', 'a')]])
        ->push(['files' => [folder('fixed-a-id', 'a')]]);
    $resolver = resolver();
    throws('PikPak path is ambiguous.', fn () => $resolver->resolve(['a']));
    same('fixed-a-id', $resolver->resolve(['a']));
};

$tests['repeated pagination token fails'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['files' => [], 'next_page_token' => 'again'])
        ->push(['files' => [], 'next_page_token' => 'again']);
    throws('PikPak pagination token repeated.', fn () => resolver()->resolve(['missing']));
};

$tests['files and trashed folders cannot satisfy a directory path'] = function (): void {
    fakeDrive(['' => [
        ['id' => 'file-id', 'name' => 'a', 'kind' => 'drive#file'],
        folder('trashed-id', 'a') + ['trashed' => true],
    ]]);
    throws('PikPak folder path was not found.', fn () => resolver()->resolve(['a']));
};

$tests['missing paths are not negatively cached'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()->push(['files' => []])->push(['files' => [folder('a-id', 'a')]]);
    $resolver = resolver();
    throws('PikPak folder path was not found.', fn () => $resolver->resolve(['a']));
    same('a-id', $resolver->resolve(['a']));
};

$tests['directory creation caches returned ID'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()->push(['files' => []])->push(['file' => folder('a-id', 'a')]);
    $resolver = resolver();
    same('a-id', $resolver->resolve(['a'], create: true));
    same('a-id', $resolver->resolve(['a']));
    same('POST', $http->recorded()->last()[0]->method());
    same(2, $http->recorded()->count());
};

$tests['writes can create configured root path'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['files' => []])->push(['file' => folder('a-id', 'a')])
        ->push(['files' => []])->push(['file' => folder('b-id', 'b')]);
    $adapter = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/a');
    same('b-id', $adapter->ensureFolderPathAndGetId('b'));
    same(4, $http->recorded()->count());
};

$tests['deleting configured root invalidates shared IDs in existing instances'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['files' => [folder('a-id', 'a')]])
        ->push([])
        ->push(['files' => [folder('replacement-id', 'a')]]);
    $cache = new Repository(new ArrayStore());
    $one = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/a', $cache);
    $two = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/a', $cache);
    same(true, $two->directoryExists(''));
    $one->deleteDirectory('');
    same('replacement-id', $two->ensureFolderPathAndGetId(''));
    same(3, $http->recorded()->count());
};

$tests['legacy folder ID and visible prefix remain compatible'] = function (): void {
    $http = fakeDrive(['legacy-id' => [folder('docs-id', 'docs')]]);
    $adapter = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), 'legacy-id', 'visible');
    same(true, $adapter->directoryExists('visible/docs'));
    same(true, $adapter->directoryExists('docs'));
    same(1, $http->recorded()->count());
};

$tests['root listing is scoped to resolved path'] = function (): void {
    fakeDrive([
        '' => [folder('a-id', 'a')],
        'a-id' => [['id' => 'f-id', 'name' => 'file.txt', 'kind' => 'drive#file']],
    ]);
    $adapter = new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/a');
    $items = iterator_to_array($adapter->listContents('', false));
    same(1, count($items));
    same('file.txt', $items[0]->path());
};

$tests['invalid configured path is rejected before any API request'] = function (): void {
    $http = fakeDrive([]);
    throws('Invalid PikPak path.', fn () => new PikPakFilesystemAdapter(new PikPakApiClient('account-a'), '', '', null, '/a/../b'));
    same(0, $http->recorded()->count());
};

$tests['Laravel provider selects configured cache store and shares resolved IDs'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $app = new \Illuminate\Container\Container();
    $app->instance('config', new \Illuminate\Config\Repository([
        'filesystems' => ['disks' => ['pikpak' => [
            'driver' => 'pikpak', 'token' => 'account-a', 'root_folder_path' => '/a',
            'folder_cache_store' => 'test',
        ]]],
        'cache' => ['default' => 'missing', 'stores' => ['test' => ['driver' => 'array']]],
    ]));
    $app->instance('cache', new \Illuminate\Cache\CacheManager($app));
    $manager = new \Illuminate\Filesystem\FilesystemManager($app);
    $app->instance('filesystem', $manager);
    (new \OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider($app))->boot();
    same(true, $manager->disk('pikpak')->directoryExists(''));
    $manager->forgetDisk('pikpak');
    same(true, $manager->disk('pikpak')->directoryExists(''));
    same(1, $http->recorded()->count());
};

$tests['Laravel provider works without cache service'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $app = new \Illuminate\Container\Container();
    $app->instance('config', new \Illuminate\Config\Repository([
        'filesystems' => ['disks' => ['pikpak' => [
            'driver' => 'pikpak', 'token' => 'account-a', 'root_folder_path' => '/a',
        ]]],
    ]));
    $manager = new \Illuminate\Filesystem\FilesystemManager($app);
    $app->instance('filesystem', $manager);
    (new \OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider($app))->boot();
    same(true, $manager->disk('pikpak')->directoryExists(''));
    same(true, $manager->disk('pikpak')->directoryExists(''));
    same(1, $http->recorded()->count());
};


$tests['folder creation can recover ID from a follow-up listing'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()->push(['files' => []])->push([])->push(['files' => [folder('a-id', 'a')]]);
    same('a-id', resolver()->resolve(['a'], create: true));
};


$tests['cache returning false on put falls back to memory'] = function (): void {
    $http = fakeDrive(['' => [folder('a-id', 'a')]]);
    $cache = new class(new ArrayStore()) extends Repository {
        public function put($key, $value, $ttl = null): bool { return false; }
    };
    $resolver = resolver($cache);
    same('a-id', $resolver->resolve(['a']));
    same('a-id', $resolver->resolve(['a']));
    same(1, $http->recorded()->count());
};

$tests['cache returning false on invalidation does not reuse deleted IDs'] = function (): void {
    $http = new Factory();
    Http::swap($http);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['files' => [folder('old-id', 'a')]])
        ->push(['files' => [folder('replacement-id', 'a')]]);
    $cache = new class(new ArrayStore()) extends Repository {
        public function forever($key, $value): bool { return false; }
    };
    $resolver = resolver($cache);
    same('old-id', $resolver->resolve(['a']));
    $resolver->invalidate();
    same('replacement-id', $resolver->resolve(['a']));
    same(2, $http->recorded()->count());
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failed;
        echo "FAIL $name: {$error->getMessage()}\n";
    }
}
echo count($tests) . " tests, $failed failures\n";
exit($failed === 0 ? 0 : 1);
