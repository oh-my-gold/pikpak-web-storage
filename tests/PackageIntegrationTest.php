<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\UnableToCheckExistence;
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;
use OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PackageIntegrationTest extends TestCase
{
    private Container $app;
    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        $this->app->instance('config', new Repository([
            'filesystems' => [
                'default' => 'pikpak',
                'disks' => [
                    'pikpak' => [
                        'driver' => 'pikpak',
                        'token' => 'test-bearer-token',
                        'root_folder_id' => '',
                        'path_prefix' => '',
                        'throw' => true,
                    ],
                ],
            ],
        ]));

        $this->http = new Factory();
        $this->app->instance(Factory::class, $this->http);
        $this->app->instance('filesystem', new FilesystemManager($this->app));
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();

        (new PikPakWebStorageServiceProvider($this->app))->boot();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_composer_declares_laravel_package_auto_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains(
            PikPakWebStorageServiceProvider::class,
            $composer['extra']['laravel']['providers'] ?? [],
        );
    }

    public function test_laravel_storage_facade_registers_driver_and_uploads_a_file(): void
    {
        $this->http->fake(function (Request $request) {
            if ($request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/files')) {
                return $this->http->response([
                    'file' => ['id' => 'uploaded-file-id'],
                    'resumable' => [
                        'params' => [
                            'endpoint' => 'oss.example.com',
                            'bucket' => 'pikpak-bucket',
                            'key' => 'uploads/hello.txt',
                            'access_key_id' => 'temporary-access-key',
                            'access_key_secret' => 'temporary-secret',
                            'security_token' => 'temporary-session-token',
                        ],
                    ],
                ]);
            }

            if ($request->method() === 'PUT' && str_starts_with($request->url(), 'https://pikpak-bucket.oss.example.com/')) {
                return $this->http->response('', 200);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $disk = Storage::disk('pikpak');

        self::assertTrue($disk->put('hello.txt', 'hello PikPak'));
        self::assertSame('uploaded-file-id', $disk->getAdapter()->lastUploadedFileId());
        self::assertArrayNotHasKey('token', $disk->getConfig());

        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/files')
            && $request->hasHeader('Authorization', 'Bearer test-bearer-token'));
        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_starts_with($request->url(), 'https://pikpak-bucket.oss.example.com/'));
    }

    public function test_storage_reads_remote_content_and_returns_a_stream(): void
    {
        $this->http->fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/files')) {
                return $this->http->response([
                    'files' => [[
                        'id' => 'file-id',
                        'name' => 'hello.txt',
                        'kind' => 'drive#file',
                        'size' => 12,
                        'web_content_link' => 'https://download.example.com/hello.txt',
                    ]],
                ]);
            }

            if ($request->method() === 'GET' && $request->url() === 'https://download.example.com/hello.txt') {
                return $this->http->response('hello PikPak');
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $disk = Storage::disk('pikpak');
        self::assertSame('hello PikPak', $disk->get('hello.txt'));

        $stream = $disk->readStream('hello.txt');
        self::assertIsResource($stream);
        self::assertSame('hello PikPak', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_listing_follows_pages_and_returns_nested_paths(): void
    {
        $seenPageTokens = [];
        $this->http->fake(function (Request $request) use (&$seenPageTokens) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $seenPageTokens[] = $query['page_token'] ?? '';

            if (($query['parent_id'] ?? '') === 'folder-id') {
                return $this->http->response([
                    'files' => [[
                        'id' => 'nested-file',
                        'name' => 'guide.txt',
                        'kind' => 'drive#file',
                        'size' => 4,
                    ]],
                ]);
            }

            if (($query['page_token'] ?? '') === 'next-page') {
                return $this->http->response([
                    'files' => [[
                        'id' => 'second-file',
                        'name' => 'notes.txt',
                        'kind' => 'drive#file',
                        'size' => 5,
                    ]],
                ]);
            }

            return $this->http->response([
                'files' => [
                    ['id' => 'folder-id', 'name' => 'docs', 'kind' => 'drive#folder'],
                    ['id' => 'first-file', 'name' => 'readme.txt', 'kind' => 'drive#file', 'size' => 6],
                ],
                'next_page_token' => 'next-page',
            ]);
        });

        self::assertSame(['docs/guide.txt', 'notes.txt', 'readme.txt'], Storage::disk('pikpak')->allFiles());
        self::assertSame(['', 'next-page', '', 'next-page', ''], $seenPageTokens);
    }

    public function test_root_folder_and_visible_path_prefix_scope_remote_lookups(): void
    {
        $config = $this->app->make('config');
        $config->set('filesystems.disks.pikpak.root_folder_id', 'root-folder-id');
        $config->set('filesystems.disks.pikpak.path_prefix', 'workspace');

        $parentIds = [];
        $this->http->fake(function (Request $request) use (&$parentIds) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $parentIds[] = $query['parent_id'] ?? null;

            return $this->http->response([
                'files' => [[
                    'id' => 'file-id',
                    'name' => 'hello.txt',
                    'kind' => 'drive#file',
                ]],
            ]);
        });

        $disk = Storage::disk('pikpak');
        self::assertTrue($disk->exists('workspace/hello.txt'));
        self::assertTrue($disk->directoryExists('workspace'));
        self::assertSame(['root-folder-id'], $parentIds);
    }

    public function test_move_copy_and_delete_use_the_expected_drive_operations(): void
    {
        $files = [['id' => 'file-id', 'name' => 'old.txt', 'kind' => 'drive#file']];
        $operations = [];
        $this->http->fake(function (Request $request) use (&$files, &$operations) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response(['files' => $files]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/files:batchMove')) {
                $operations[] = 'move';
                return $this->http->response([]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/files:batchCopy')) {
                $operations[] = 'copy';
                $files[] = ['id' => 'copy-id', 'name' => 'old.txt', 'kind' => 'drive#file'];
                return $this->http->response(['file' => ['id' => 'copy-id']]);
            }

            if ($request->method() === 'PATCH' && str_ends_with($path, '/files/file-id')) {
                $files[0]['name'] = $request->data()['name'];
                $operations[] = 'rename:' . $request->data()['name'];
                return $this->http->response([]);
            }

            if ($request->method() === 'PATCH' && str_ends_with($path, '/files/copy-id')) {
                $files[1]['name'] = $request->data()['name'];
                $operations[] = 'rename:' . $request->data()['name'];
                return $this->http->response([]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/files:batchTrash')) {
                $operations[] = 'trash:' . $request->data()['ids'][0];
                return $this->http->response([]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $disk = Storage::disk('pikpak');
        self::assertTrue($disk->move('old.txt', 'renamed.txt'));
        self::assertTrue($disk->copy('renamed.txt', 'copy.txt'));
        self::assertTrue($disk->delete('copy.txt'));

        self::assertSame(['move', 'rename:renamed.txt', 'copy', 'rename:copy.txt', 'trash:copy-id'], $operations);
    }

    public function test_delete_permanently_when_trash_is_disabled(): void
    {
        $this->app->make('config')->set('filesystems.disks.pikpak.use_trash', false);
        $operations = [];
        $this->http->fake(function (Request $request) use (&$operations) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response([
                    'files' => [['id' => 'file-id', 'name' => 'manual.pdf', 'kind' => 'drive#file']],
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/files:batchDelete')) {
                $operations[] = ['endpoint' => 'batchDelete', 'ids' => $request->data()['ids']];
                return $this->http->response([]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        Storage::disk('pikpak')->delete('manual.pdf');

        self::assertSame([['endpoint' => 'batchDelete', 'ids' => ['file-id']]], $operations);
    }

    public function test_delete_directory_permanently_when_trash_is_disabled(): void
    {
        $this->app->make('config')->set('filesystems.disks.pikpak.use_trash', false);
        $operations = [];
        $this->http->fake(function (Request $request) use (&$operations) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response([
                    'files' => [['id' => 'folder-id', 'name' => 'archive', 'kind' => 'drive#folder']],
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/files:batchDelete')) {
                $operations[] = ['endpoint' => 'batchDelete', 'ids' => $request->data()['ids']];
                return $this->http->response([]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        Storage::disk('pikpak')->deleteDirectory('archive');

        self::assertSame([['endpoint' => 'batchDelete', 'ids' => ['folder-id']]], $operations);
    }

    public function test_share_returns_a_seven_day_link_without_a_password_by_default(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response([
                    'files' => [['id' => 'file-id', 'name' => 'manual.pdf', 'kind' => 'drive#file']],
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/share')) {
                return $this->http->response([
                    'share_id' => 'share-id',
                    'share_url' => 'https://mypikpak.com/s/share-id',
                    'pass_code' => '',
                    'share_text' => 'https://mypikpak.com/s/share-id',
                ]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $adapter = Storage::disk('pikpak')->getAdapter();
        $share = $adapter->share('manual.pdf');

        self::assertSame('https://mypikpak.com/s/share-id', $share['share_url']);
        self::assertSame('', $share['pass_code']);
        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/share')
            && $request->data() === [
                'file_ids' => ['file-id'],
                'share_to' => 'publiclink',
                'expiration_days' => 7,
                'pass_code_option' => 'NOT_REQUIRED',
            ]);
    }

    public function test_revoke_share_posts_the_share_id_to_the_batch_delete_endpoint(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'POST' && str_ends_with($path, '/share:batchDelete')) {
                return $this->http->response([]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        Storage::disk('pikpak')->getAdapter()->revokeShare('share-id');

        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/share:batchDelete')
            && $request->data() === ['ids' => ['share-id']]);
    }

    public function test_revoke_share_rejects_an_empty_share_id(): void
    {
        $this->http->fake();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A PikPak share ID is required.');

        Storage::disk('pikpak')->getAdapter()->revokeShare('   ');
    }

    public function test_list_shares_follows_all_pages_and_returns_raw_share_records(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($request->method() === 'GET' && str_ends_with($path, '/share/list')) {
                if (($query['page_token'] ?? '') === '') {
                    return $this->http->response([
                        'data' => [['share_id' => 'share-one', 'share_url' => 'https://mypikpak.com/s/share-one']],
                        'next_page_token' => 'page-two',
                    ]);
                }

                return $this->http->response([
                    'data' => [['share_id' => 'share-two', 'share_status' => 'ACTIVE']],
                ]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $shares = Storage::disk('pikpak')->getAdapter()->listShares();

        self::assertSame([
            ['share_id' => 'share-one', 'share_url' => 'https://mypikpak.com/s/share-one'],
            ['share_id' => 'share-two', 'share_status' => 'ACTIVE'],
        ], $shares);
        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/share/list')
            && str_contains($request->url(), 'limit=100')
            && str_contains($request->url(), 'thumbnail_size=SIZE_SMALL')
            && str_contains($request->url(), 'page_token=page-two'));
    }

    public function test_list_shares_rejects_a_repeated_pagination_token(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/share/list')) {
                return $this->http->response([
                    'data' => [],
                    'next_page_token' => 'repeated-token',
                ]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PikPak share pagination token repeated.');

        Storage::disk('pikpak')->getAdapter()->listShares();
    }

    public function test_list_shares_rejects_a_non_array_response_data_field(): void
    {
        $this->http->fake(fn (Request $request) => $this->http->response(['data' => 'invalid']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PikPak share list response is invalid.');

        Storage::disk('pikpak')->getAdapter()->listShares();
    }

    public function test_share_can_create_a_password_link_for_a_folder_with_a_custom_expiration(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response([
                    'files' => [['id' => 'folder-id', 'name' => 'reports', 'kind' => 'drive#folder']],
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/share')) {
                return $this->http->response([
                    'share_id' => 'protected-share-id',
                    'share_url' => 'https://mypikpak.com/s/protected-share-id',
                    'pass_code' => 'generated-code',
                    'share_text' => 'https://mypikpak.com/s/protected-share-id code: generated-code',
                ]);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $adapter = Storage::disk('pikpak')->getAdapter();
        $share = $adapter->share('reports', true, 30);

        self::assertSame('https://mypikpak.com/s/protected-share-id', $share['share_url']);
        self::assertSame('generated-code', $share['pass_code']);
        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === [
                'file_ids' => ['folder-id'],
                'share_to' => 'encryptedlink',
                'expiration_days' => 30,
                'pass_code_option' => 'REQUIRED',
            ]);
    }

    public function test_share_rejects_a_response_without_a_share_url(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response([
                    'files' => [['id' => 'file-id', 'name' => 'manual.pdf', 'kind' => 'drive#file']],
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/share')) {
                return $this->http->response(['share_id' => 'share-without-url']);
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PikPak did not return a share link.');

        Storage::disk('pikpak')->getAdapter()->share('manual.pdf');
    }

    public function test_large_upload_completes_multipart_transfer_with_valid_etag_xml(): void
    {
        $completionXml = '';
        $this->http->fake(function (Request $request) use (&$completionXml) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($request->method() === 'POST' && str_ends_with($path, '/files')) {
                return $this->http->response([
                    'file' => ['id' => 'large-file-id'],
                    'resumable' => [
                        'params' => [
                            'endpoint' => 'oss.example.com',
                            'bucket' => 'pikpak-bucket',
                            'key' => 'uploads/large.bin',
                            'access_key_id' => 'temporary-access-key',
                            'access_key_secret' => 'temporary-secret',
                            'security_token' => 'temporary-session-token',
                        ],
                    ],
                ]);
            }

            if ($request->method() === 'PUT' && array_key_exists('uploads', $query)) {
                return $this->http->response('<InitiateMultipartUploadResult><UploadId>upload-id</UploadId></InitiateMultipartUploadResult>');
            }

            if ($request->method() === 'PUT' && isset($query['partNumber'])) {
                return $this->http->response('', 200, ['ETag' => 'etag-1']);
            }

            if ($request->method() === 'POST' && isset($query['uploadId'])) {
                $completionXml = $request->body();
                return $this->http->response('');
            }

            return $this->http->response(['error' => 'unexpected request'], 500);
        });

        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat('x', 10 * 1024 * 1024 + 1));
        rewind($stream);
        $disk = Storage::disk('pikpak');
        $disk->writeStream('large.bin', $stream);
        fclose($stream);

        self::assertStringContainsString('<ETag>"etag-1"</ETag>', $completionXml);
        self::assertCount(8, $disk->getAdapter()->lastUploadRequests());
        self::assertStringNotContainsString('uploadId', json_encode($disk->getAdapter()->lastUploadRequests(), JSON_THROW_ON_ERROR));
    }

    public function test_ambiguous_file_name_fails_instead_of_selecting_an_arbitrary_file(): void
    {
        $this->http->fake(fn () => $this->http->response([
            'files' => [
                ['id' => 'file-one', 'name' => 'same.txt', 'kind' => 'drive#file'],
                ['id' => 'file-two', 'name' => 'same.txt', 'kind' => 'drive#file'],
            ],
        ]));

        $adapter = Storage::disk('pikpak')->getAdapter();
        self::assertInstanceOf(PikPakFilesystemAdapter::class, $adapter);
        try {
            $adapter->fileExists('same.txt');
            self::fail('Expected an ambiguous path to fail.');
        } catch (UnableToCheckExistence $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame('PikPak path is ambiguous.', $exception->getPrevious()->getMessage());
        }
    }

    public function test_disk_requires_a_non_empty_bearer_token(): void
    {
        $config = $this->app->make('config');
        $config->set('filesystems.disks.pikpak.token', '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A PikPak Bearer token is required for this disk.');

        Storage::disk('pikpak');
    }

    public function test_api_client_rejects_non_drive_api_urls(): void
    {
        $client = new PikPakApiClient('test-token');

        $this->expectException(InvalidArgumentException::class);
        $client->get('https://example.com/drive/v1/files');
    }

    public function test_api_error_exposes_only_status_and_safe_error_code(): void
    {
        $this->http->fake(fn () => $this->http->response([
            'error_code' => 'token-expired',
            'message' => 'private response text that must not be exposed',
        ], 401));

        try {
            (new PikPakApiClient('test-token'))->get('https://api-drive.mypikpak.com/drive/v1/files');
            self::fail('Expected PikPakApiException was not thrown.');
        } catch (PikPakApiException $exception) {
            self::assertSame(401, $exception->httpStatus);
            self::assertSame('token-expired', $exception->apiErrorCode);
            self::assertStringNotContainsString('private response text', $exception->getMessage());
        }
    }

    public function test_api_client_can_return_status_and_send_extra_headers(): void
    {
        $this->http->fake(function (Request $request) {
            self::assertSame('POST', $request->method());
            self::assertTrue($request->hasHeader('x-integration-context', 'ushow-upload'));
            self::assertSame(['name' => 'example.jpg'], $request->data());

            return $this->http->response(['file' => ['id' => 'created-id']], 201);
        });

        $response = (new PikPakApiClient('test-token'))->postWithStatus(
            'https://api-drive.mypikpak.com/drive/v1/files',
            ['name' => 'example.jpg'],
            ['x-integration-context' => 'ushow-upload'],
        );

        self::assertSame(201, $response['status']);
        self::assertSame(['file' => ['id' => 'created-id']], $response['data']);
    }

    public function test_get_presigned_upload_url_returns_a_signed_put_url(): void
    {
        $this->http->fake();
        $params = [
            'endpoint' => 'oss.example.com',
            'bucket' => 'pikpak-bucket',
            'key' => 'uploads/my photo?.txt',
            'access_key_id' => 'temporary-access-key',
            'access_key_secret' => 'temporary-secret',
            'security_token' => 'temporary+session/token=',
        ];

        $adapter = Storage::disk('pikpak')->getAdapter();
        self::assertInstanceOf(PikPakFilesystemAdapter::class, $adapter);
        $url = $adapter->getPresignedUploadUrl($params);

        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('pikpak-bucket.oss.example.com', parse_url($url, PHP_URL_HOST));
        self::assertSame('/uploads/my%20photo%3F.txt', parse_url($url, PHP_URL_PATH));

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('temporary-access-key', $query['OSSAccessKeyId'] ?? null);
        self::assertSame($params['security_token'], $query['security-token'] ?? null);
        self::assertGreaterThanOrEqual(time(), (int) ($query['Expires'] ?? 0));
        self::assertLessThanOrEqual(time() + 900, (int) ($query['Expires'] ?? 0));
        self::assertNotEmpty($query['Signature'] ?? null);

        $canonicalResource = '/pikpak-bucket/' . $params['key'] . '?security-token=' . $params['security_token'];
        $stringToSign = "PUT\n\napplication/octet-stream\n" . $query['Expires'] . "\n" . $canonicalResource;
        $expectedSignature = base64_encode(hash_hmac('sha1', $stringToSign, $params['access_key_secret'], true));
        self::assertSame($expectedSignature, $query['Signature']);
        $this->http->assertNothingSent();
    }

    public function test_get_presigned_upload_url_rejects_invalid_expiration(): void
    {
        $adapter = Storage::disk('pikpak')->getAdapter();
        self::assertInstanceOf(PikPakFilesystemAdapter::class, $adapter);
        $params = [
            'endpoint' => 'oss.example.com',
            'bucket' => 'pikpak-bucket',
            'key' => 'uploads/hello.txt',
            'access_key_id' => 'temporary-access-key',
            'access_key_secret' => 'temporary-secret',
            'security_token' => 'temporary-session-token',
        ];

        foreach ([0, -1, 3601] as $expiration) {
            try {
                $adapter->getPresignedUploadUrl($params, $expiration);
                self::fail('Expected an invalid expiration to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('PikPak upload URL expiration must be between 1 and 3600 seconds.', $exception->getMessage());
            }
        }
    }

    public function test_get_presigned_upload_url_requires_session_credentials(): void
    {
        $adapter = Storage::disk('pikpak')->getAdapter();
        self::assertInstanceOf(PikPakFilesystemAdapter::class, $adapter);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PikPak upload credentials are incomplete.');

        $adapter->getPresignedUploadUrl([
            'endpoint' => 'oss.example.com',
            'bucket' => 'pikpak-bucket',
            'key' => 'uploads/hello.txt',
            'access_key_id' => 'temporary-access-key',
            'access_key_secret' => 'temporary-secret',
        ]);
    }

    public function test_browser_upload_authorization_returns_put_and_post_requests(): void
    {
        $this->http->fake();
        $adapter = Storage::disk('pikpak')->getAdapter();
        $params = [
            'endpoint' => 'oss.example.com',
            'bucket' => 'pikpak-bucket',
            'key' => 'uploads/my photo.txt',
            'access_key_id' => 'temporary-access-key',
            'access_key_secret' => 'temporary-secret',
            'security_token' => 'temporary-session-token',
        ];
        $md5 = base64_encode(md5('upload', true));

        $authorization = $adapter->getBrowserUploadAuthorization($params, $md5, 6);

        self::assertSame('https://pikpak-bucket.oss.example.com/uploads/my%20photo.txt', $authorization['put']['url']);
        self::assertSame($md5, $authorization['put']['headers']['Content-MD5']);
        self::assertStringStartsWith('OSS temporary-access-key:', $authorization['put']['headers']['Authorization']);
        self::assertSame('https://pikpak-bucket.oss.example.com/', $authorization['post']['url']);
        self::assertSame('uploads/my photo.txt', $authorization['post']['fields']['key']);
        self::assertSame('temporary-session-token', $authorization['post']['fields']['x-oss-security-token']);
        self::assertSame([
            'bucket' => 'pikpak-bucket',
            'key' => 'uploads/my photo.txt',
            'success_action_status' => '201',
            'size_range' => [6, 6],
        ], $this->policyConditions($authorization['post']['fields']['policy']));
        $this->http->assertNothingSent();
    }

    public function test_incomplete_file_helpers_validate_identity_and_list_all_phases(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                self::assertStringNotContainsString('phase', (string) ($query['filters'] ?? ''));
                return $this->http->response(['files' => [
                    ['id' => 'incomplete-id', 'name' => 'video.mp4', 'kind' => 'drive#file', 'phase' => 'PHASE_TYPE_PROCESSING'],
                ]]);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/files/incomplete-id')) {
                return $this->http->response(['id' => 'incomplete-id', 'name' => 'video.mp4', 'kind' => 'drive#file', 'parent_id' => '', 'phase' => 'PHASE_TYPE_PROCESSING']);
            }
            return $this->http->response(['error' => 'unexpected request'], 500);
        });
        $adapter = Storage::disk('pikpak')->getAdapter();

        self::assertSame('incomplete-id', $adapter->fileIdIncludingIncomplete('video.mp4'));
        self::assertSame('PHASE_TYPE_PROCESSING', $adapter->filePhaseAtPath('incomplete-id', 'video.mp4'));
        self::assertFalse($adapter->fileIsCompleteAtPath('incomplete-id', 'video.mp4'));
    }

    public function test_folder_can_be_created_below_a_known_id(): void
    {
        $this->http->fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if ($request->method() === 'GET' && str_ends_with($path, '/files')) {
                return $this->http->response(['files' => []]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/files')) {
                self::assertSame('root-folder-id', $request->data()['parent_id']);
                self::assertSame('2025', $request->data()['name']);
                return $this->http->response(['file' => ['id' => 'year-folder-id', 'kind' => 'drive#folder']]);
            }
            return $this->http->response(['error' => 'unexpected request'], 500);
        });
        $adapter = Storage::disk('pikpak')->getAdapter();

        self::assertSame('year-folder-id', $adapter->ensureFolderBelowId('root-folder-id', '2025'));
    }

    public function test_permanent_cleanup_checks_identity_then_trashes_and_deletes(): void
    {
        $calls = [];
        $this->http->fake(function (Request $request) use (&$calls) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if ($request->method() === 'GET') {
                $calls[] = 'get';
                return $this->http->response([
                    'id' => 'cleanup-id', 'kind' => 'drive#file', 'trashed' => false,
                    'parent_id' => 'parent-id', 'name' => 'upload.bin',
                ]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, ':batchTrash')) {
                $calls[] = 'trash';
                return $this->http->response([]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, ':batchDelete')) {
                $calls[] = 'delete';
                return $this->http->response([]);
            }
            return $this->http->response(['error' => 'unexpected request'], 500);
        });
        $adapter = Storage::disk('pikpak')->getAdapter();

        $adapter->permanentlyDeleteById('cleanup-id', ['parent_id' => 'parent-id', 'name' => 'upload.bin']);

        self::assertSame(['get', 'trash', 'delete'], $calls);
    }

    public function test_permanent_cleanup_rejects_a_replaced_upload_target(): void
    {
        $calls = [];
        $this->http->fake(function (Request $request) use (&$calls) {
            if ($request->method() === 'GET') {
                $calls[] = 'get';
                return $this->http->response([
                    'id' => 'cleanup-id', 'kind' => 'drive#file', 'trashed' => false,
                    'parent_id' => 'parent-id', 'name' => 'replacement.bin',
                ]);
            }
            $calls[] = 'delete';
            return $this->http->response([]);
        });
        $adapter = Storage::disk('pikpak')->getAdapter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Remote cleanup target no longer belongs to this upload.');
        try {
            $adapter->permanentlyDeleteById('cleanup-id', ['parent_id' => 'parent-id', 'name' => 'upload.bin']);
        } finally {
            self::assertSame(['get'], $calls);
        }
    }

    private function policyConditions(string $encodedPolicy): array
    {
        $policy = json_decode((string) base64_decode($encodedPolicy, true), true, 512, JSON_THROW_ON_ERROR);
        $conditions = $policy['conditions'] ?? [];
        $sizeRange = [];
        foreach ($conditions as $condition) {
            if (is_array($condition) && ($condition[0] ?? null) === 'content-length-range') {
                $sizeRange = [(int) $condition[1], (int) $condition[2]];
            }
        }

        return [
            'bucket' => $conditions[0]['bucket'] ?? null,
            'key' => $conditions[1][2] ?? null,
            'success_action_status' => $conditions[2][2] ?? null,
            'size_range' => $sizeRange,
        ];
    }
}
