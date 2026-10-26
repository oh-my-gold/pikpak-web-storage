# PikPak Web Storage

[English](README.md) · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md)

基于 Flysystem 3 和 PikPak Drive API 的 Laravel Storage 驱动。

Laravel 会通过包自动发现注册 `pikpak` 驱动。使用方需提供有效的 PikPak Bearer Token；本包不会保存、解密、刷新或替换凭据。

## 环境要求

- PHP 8.1 或更高版本
- Laravel 10、11 或 12
- Flysystem 3
- 对应用所需操作有相应权限的 PikPak Bearer Token

## 安装

使用 Composer 安装：

```sh
composer require oh-my-gold/pikpak-web-storage:dev-master
```

Laravel 会自动发现 `PikPakWebStorageServiceProvider`。如果关闭了包自动发现，请将 `OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider::class` 加入应用的 providers 列表。

## 配置磁盘

在 `config/filesystems.php` 中添加磁盘：

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

请将 `PIKPAK_TOKEN` 存放在密钥管理器或环境变量中，不要提交到代码仓库。设置 `PIKPAK_ROOT_FOLDER_PATH=/备份/项目` 可将此目录作为磁盘根目录。空路径或 `/` 表示账号根目录。驱动会按需逐级解析 PikPak 文件夹 ID，每次仅查找一层并使用文件夹过滤器；每页最多请求 500 条。读取时，配置的根路径不存在会报错；写入和创建目录时会创建缺失的目录。路径均相对于配置的磁盘根目录。

有 Laravel 缓存时，驱动默认使用应用的默认缓存 store；缓存不可用时回退到当前 adapter 实例的内存缓存。可选配置 `folder_cache_store` 指定 Laravel 缓存 store，`folder_cache_ttl` 指定缓存秒数，默认 `3600`。缓存只保存成功的文件夹查询结果，并按 Bearer Token 的哈希隔离。删除目录或调用 `deleteById()` 会清除对应文件夹映射，包括后代映射。外部文件夹变更会在缓存过期后生效；也可以清理所选缓存 store 以立即刷新。驱动会检查所有分页；若同名文件夹有多个，则会报错。

为保持兼容，`root_folder_id` 仍可配置起始文件夹 ID（`''` 或 `root` 表示账号根目录）。同时设置两个根目录选项时，`root_folder_path` 会在 `root_folder_id` 下解析。

`use_trash` 默认为 `true`。设为 `false` 时，文件和目录会直接通过 PikPak `files:batchDelete` 接口永久删除，不会先移入回收站；每次删除只发送一次 API 请求。此设置适用于 Laravel `delete()`、`deleteDirectory()` 和 adapter 的 `deleteById()` 方法。

`path_prefix` 会保留从根目录列出时显示路径的前缀。输入路径以该前缀开头时，查找前会移除它。不带此前缀的路径仍相对于配置的磁盘根目录，因此 `path_prefix` 不是访问控制边界。

## 使用 Laravel Storage

```php
use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('pikpak');
$disk->put('photos/example.txt', '保存到 PikPak');
$contents = $disk->get('photos/example.txt');
$stream = $disk->readStream('photos/example.txt');
fclose($stream);
```

常用方法：

| Laravel 调用 | 成功时返回 | 说明 |
| --- | --- | --- |
| `$disk->fileExists('photos/a.txt')` / `fileMissing(...)` | `bool` | 检查文件是否存在，忽略已移入回收站的项目。 |
| `$disk->directoryExists('photos')` / `directoryMissing(...)` | `bool` | 检查目录是否存在。 |
| `$disk->exists('photos/a.txt')` / `missing(...)` | `bool` | 检查文件或目录是否存在。 |
| `$disk->makeDirectory('photos/2026')` | `true` | 创建路径中的所有缺失目录。 |
| `$disk->delete('photos/a.txt')` | `true` | 按 `use_trash` 设置删除文件；也接受文件路径数组。 |
| `$disk->deleteDirectory('photos/2026')` | `true` | 按 `use_trash` 设置删除目录及其内容，并清除目录 ID 缓存。 |
| `$disk->copy($from, $to)` / `$disk->move($from, $to)` | `true` | 复制或移动文件，会创建目标路径缺失的目录。 |
| `$disk->put($path, $contents)` / `writeStream(...)` | `true` | 写入字符串或可读取、可 seek 的流。 |
| `$disk->putFile(...)` / `$disk->putFileAs(...)` | `string` 路径 | Laravel 上传文件辅助方法。 |
| `$disk->get($path)` / `readStream(...)` | `string` / `resource` | 读取完整内容或读取流；调用方需关闭流。 |
| `$disk->files(...)` / `allFiles(...)` | `string[]` | 列出当前目录或递归列出文件。 |
| `$disk->directories(...)` / `allDirectories(...)` | `string[]` | 列出当前目录或递归列出目录。 |
| `$disk->size(...)` / `mimeType(...)` / `lastModified(...)` | `int` / `string` / `int` | 获取文件大小、MIME 类型和修改时间。 |
| `$disk->url($path)` | `string` | 返回 PikPak 当前内容链接，过期时间由 PikPak 决定。 |

当 `root_folder_path` 为 `/备份/项目` 时，`photos/example.txt` 指向 PikPak 中的 `/备份/项目/photos/example.txt`。空目录路径 `''` 表示磁盘根目录。路径段中的 `.`、`..`、中间空段和控制字符会被拒绝；`/` 和 `.` 表示根目录。

写入会创建新的远端文件和上传会话，不会原子覆盖同名文件；占用路径上写入可能产生重名文件，之后的路径查找可能变得不明确。建议使用唯一路径，或先定位并删除原文件。Laravel 的 `append()` 和 `prepend()` 会先读取再执行 `put()`，也有同样的重名限制；本包不提供原位追加或覆盖操作。

`copy()` 和 `move()` 仅作用于文件，不支持目录复制或移动。请求列表时会跟随所有分页；遇到重复分页 token 会报错。目标类型相同的同名项目不唯一时（包括不同分页中的重复名称），驱动会报错。

## 文件列表与元数据

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

`listContents($path, false)` 只列出当前层；第二个参数设为 `true` 时递归列出。`getVisibility()` 返回 `private`。PikPak 当前 Drive API 不支持修改 ACL；`setVisibility(..., 'private')` 可用，设置为 `public` 会抛出 Flysystem 异常。Laravel 的 `download()` 和 `response()` 可用于控制器响应。Flysystem 校验和调用使用基于流的回退实现。

adapter 没有内建 `temporaryUrl()` 或 `temporaryUploadUrl()`。`path()` 只格式化路径字符串，不会提供本地文件路径。Laravel 辅助方法的可用性取决于 Laravel 版本以及宿主应用的 HTTP 和容器服务。

## 直接使用文件 ID

已知稳定文件 ID 时，可使用 `@id/{file-id}` 进行 `fileExists()`、读取、文件元数据查询和 `delete()`：

```php
$exists = $disk->fileExists('@id/REMOTE_FILE_ID');
$contents = $disk->get('@id/REMOTE_FILE_ID');
$bytes = $disk->size('@id/REMOTE_FILE_ID');
// $disk->delete('@id/REMOTE_FILE_ID'); // 行为由 use_trash 决定。
```

直接文件 ID 会访问账号对象，并绕过配置的文件夹路径。该语法不支持目录操作、写入目标或后文的路径辅助方法。已知目录 ID 时可用 `deleteById()` 删除。

## 获取浏览器上传 URL

`getPresignedUploadUrl()` 使用应用已创建的上传会话中的 `resumable.params` 生成签名 PUT URL，并以字符串返回。它不会创建 PikPak 文件或上传会话。默认有效期为 15 分钟，可设置为 1 到 3600 秒。

`getBrowserUploadAuthorization()` 适用于 UShow 当前的浏览器上传回退流程：返回带签名请求头的 PUT 请求，以及带签名表单字段的 POST 请求。参数中的 MD5 必须使用 Base64 编码，大小必须是准确字节数。此方法不会创建上传会话；会话 ID、文件 ID 和完成流程仍由应用管理。返回的授权信息应视为短时凭据。

```php
$uploadParams = $metadata['resumable']['params'];
$md5 = base64_encode(md5($contents, true));
$authorization = $adapter->getBrowserUploadAuthorization($uploadParams, $md5, strlen($contents));

return response()->json(['upload' => $authorization]);
```

UShow 可保留现有的后端会话创建流程和 `fileId`、`sessionId` 管理，再由后端生成并返回浏览器上传 URL。如果 UShow 已注册自己的 `pikpak` 驱动，可直接实例化本包的 adapter：

```php
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

$adapter = new PikPakFilesystemAdapter(new PikPakApiClient($pikpakToken));
$uploadParams = $metadata['resumable']['params']; // 来自 UShow 现有的上传会话请求。
$uploadUrl = $adapter->getPresignedUploadUrl($uploadParams);

return response()->json([
    'fileId' => $metadata['file']['id'],
    'sessionId' => $sessionId,
    'uploadUrl' => $uploadUrl,
]);
```

浏览器使用单次 PUT 直接上传文件字节：

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

只有 PUT 成功后才继续 UShow 的上传完成流程。PikPak OSS 端点需要允许来自 UShow 来源的浏览器 CORS 请求，并放行 `PUT` 和 `Content-Type`。此方法只签名单次 PUT，不提供分片或 multipart 上传 URL。签名 URL 是临时授权信息；不要记录日志，也不要在上传流程结束后继续保存。

## 创建、查询和撤销分享

`share()` 接收文件或目录路径，返回 `share_id`、`share_url`、`pass_code` 和 `share_text`。默认有效期为 7 天。不带密码时，任何获得链接的人都能访问分享内容。带密码时由 PikPak 自动生成提取码，并通过 `pass_code` 返回；本方法不支持自定义提取码。

```php
$adapter = Storage::disk('pikpak')->getAdapter();

$share = $adapter->share('photos/example.txt'); // 无密码，默认 7 天。
$url = $share['share_url'];

$protected = $adapter->share('photos', true, 30); // PikPak 生成提取码，有效 30 天。
$protectedUrl = $protected['share_url'];
$passCode = $protected['pass_code'];
```

账号根目录不能分享。配置了磁盘根目录时，可传入空路径分享该根目录。Token 需要具有 PikPak Share 权限。

`listShares()` 会自动读取所有分页并返回 PikPak 原始分享记录。`revokeShare()` 接受 `share_id`；撤销分享链接不会删除文件或目录。

```php
$shares = $adapter->listShares();
foreach ($shares as $share) {
    echo $share['share_id'] . ': ' . $share['share_url'] . PHP_EOL;
}

$adapter->revokeShare('share-id-to-revoke');
```

## adapter 方法参考

通过 Laravel 磁盘取得 adapter 和 Flysystem 操作对象：

```php
$adapter = $disk->getAdapter(); // PikPakFilesystemAdapter
$driver = $disk->getDriver();   // Flysystem FilesystemOperator
```

adapter 的写入和变更方法成功时返回 `void`，失败时抛出异常。直接元数据方法返回 `League\Flysystem\FileAttributes`，而不是标量值。

| 方法 | 返回值 | 用途 |
| --- | --- | --- |
| `getUrl($path)` | `string` | PikPak 当前内容链接。 |
| `getPresignedUploadUrl($params, $expiresInSeconds = 900)` | `string` | 为已创建会话的 `resumable.params` 签名单次 PUT URL。 |
| `getBrowserUploadAuthorization($params, $contentMd5, $size)` | `array` | 返回 UShow 浏览器上传流程所需的签名 PUT 请求和 POST 表单。 |
| `share($path, $withPassword = false, $expirationDays = 7)` | `array` | 创建分享并返回 PikPak 分享字段。 |
| `shareByIds($fileIds, $withPassword = false, $expirationDays = 7)` | `array` | 为多个已知 ID 创建一个分享；受密码保护时由 PikPak 生成提取码。 |
| `listShares()` | `array` | 自动分页并返回原始分享记录。 |
| `listSharesPage($limit = 100, $pageToken = '')` | `array` | 返回单页分享记录，供应用自己管理分页。 |
| `revokeShare($shareId)` | `void` | 撤销指定分享。 |
| `sourceFileId($path)` | `?string` | 返回路径对应文件 ID；查找失败时返回 `null`。 |
| `getFileById($id, $query = [], $extraHeaders = [])` | `array` | 按稳定 ID 查询 Drive 文件或目录元数据。 |
| `getFileByIdWithStatus($id, $query = [], $extraHeaders = [])` | `array` | 按稳定 ID 查询 Drive 元数据并返回 HTTP 状态码。 |
| `createUploadSession($parentId, $name, $size, $sha1)` | `array` | 创建 resumable 上传会话，返回 `data` 和 HTTP `status`。 |
| `createFolderBelowId($parentId, $name)` | `array` | 在指定父目录 ID 下创建一个子目录并返回云端记录；空 ID 表示账号根目录。 |
| `listChildren($parentId, $includeIncomplete = false)` | `array` | 自动分页读取子项；可选择包含尚未完成的上传。 |
| `listChildrenPage($parentId, $limit = 500, $pageToken = '', $includeIncomplete = false)` | `array` | 返回一页云端子项记录。 |
| `occupiedChildInFolder($parentId, $name)` | `?array` | 查找直接子项；空 ID 表示账号根目录。若分页未完成而无法确认不存在，会抛出异常。 |
| `fileIdIncludingIncomplete($path)` | `?string` | 按路径查询文件 ID，包括仍在处理中的上传；重名时抛出异常。 |
| `filePhaseAtPath($id, $path)` | `?string` | 只有 ID 仍对应此路径时才返回文件处理阶段。 |
| `fileIsCompleteAtPath($id, $path)` | `bool` | 检查文件是否已达到 `PHASE_TYPE_COMPLETE`。 |
| `pathOccupied($path)` | `bool` | 检查是否有同名文件或目录，包括未完成上传。 |
| `pathIsUniqueTo($path, $id)` | `bool` | 检查唯一同名项目是否为指定文件 ID。 |
| `ensureFolderPathAndGetId($path)` | `string` | 创建缺失目录并返回最终目录 ID；账号根目录没有对象 ID。 |
| `ensureFolderBelowId($parentId, $relativeDirectory)` | `string` | 在指定目录 ID 下创建多层目录并返回最终 ID。 |
| `deleteById($id)` | `void` | 按原始 ID 删除文件或目录，行为由 `use_trash` 决定。 |
| `permanentlyDeleteById($id, $expected = [])` | `void` | 校验文件 ID 和可选的 `parent_id`、`name`；必要时先移入回收站，再永久删除。不存在时视为已清理。 |
| `lastUploadedFileId()` | `string` | 最近上传会话返回的文件元数据 ID；不代表文件字节已上传成功。 |
| `lastUploadRequests()` | `array` | 最近上传的安全 OSS 请求摘要，不含凭据值和签名查询参数。 |

`sourceFileId()` 使用 adapter 实例内的文件 ID 缓存；Laravel 文件夹缓存 TTL 不影响此缓存。`deleteById()` 接受原始 ID，因此可访问配置根目录以外的对象。`lastUploadedFileId()` 与 `lastUploadRequests()` 会在上传开始时重置。`lastUploadRequests()` 适合用于排查，不包含签名 URL 或临时凭据。

直接构造 adapter 时，第一个参数接受 `PikPakApiClientContract`。可直接使用内建 `PikPakApiClient`；应用也可以实现此 contract，在使用包内文件系统和 Drive 方法时保留自身的凭据获取与 API 错误处理。其余构造参数依次为：`string $rootFolderId = ''`、`string $keyPrefix = ''`、`?PikPakOssUploader $uploader = null`、`string $rootFolderPath = ''`、`?Illuminate\Contracts\Cache\Repository $folderCache = null`、`int $folderCacheTtl = 3600`、`bool $useTrash = true`。

## 错误处理

本文表格列出成功时的返回值。关闭 `throw` 后，Laravel 包装器可能对 Flysystem 异常返回 `false` 或 `null`；开启 `throw` 时，Flysystem 异常会继续抛出。其 `previous` 异常可用于检查底层 `RuntimeException` 或 `PikPakApiException`。`sourceFileId()` 是例外：查找出错时会捕获异常并返回 `null`。

```php
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;

try {
    $contents = $disk->get('photos/example.txt');
} catch (PikPakApiException $error) {
    $httpStatus = $error->httpStatus;     // ?int
    $apiErrorCode = $error->apiErrorCode; // ?string，已清理的 API 错误码
} catch (RuntimeException $error) {
    // 处理无效、不存在或重名路径等错误。
}
```

## PikPak 行为与限制

- 写入时先通过 Drive API 创建文件元数据和上传会话，再将字节直接传给临时 OSS 端点。小于等于 10 MiB 的文件使用一次签名 PUT；更大的文件使用 10 MiB 分片。
- 读取使用 PikPak 当前内容链接；`readStream()` 返回内容流。
- 默认 `delete()` 和 `deleteDirectory()` 通过 `files:batchTrash` 移入回收站。将 `use_trash` 设为 `false` 后，会用一次 `files:batchDelete` 请求永久删除。删除配置的磁盘根目录会删除该目录；账号根目录没有对象 ID，因此不能删除。
- `copy()` 使用 PikPak 批量复制接口。目标名称不同时，包会解析复制对象 ID 并重命名；若 API 未返回 ID 且无法唯一识别新副本，则会报错。
- PikPak 当前 Drive API 不提供文件可见性修改。`visibility()` 返回 private；设置 public 会抛出 Flysystem 异常，而不会假装成功。
- PikPak 不支持由调用方选择内容 URL 过期时间，因此本包没有自行实现 `temporaryUrl()`；Laravel 应用可在具备其他 URL 服务时安装 `buildTemporaryUrlsUsing()` 回调。
- 本包使用的 Drive API 和临时 OSS 上传协议基于当前集成行为，不是稳定的公开开发者合约；API 变化可能需要更新本包。
- 分享链接可创建、查询和撤销。受密码保护的分享提取码由 PikPak 生成；不支持自定义提取码。

本包不实现刷新令牌认证、验证码处理或账号管理。应用必须保护 Bearer Token，并按读取、上传、移动、复制、删除和分享操作授予适当权限。认证失败会抛出异常；包不会尝试切换账号或其他凭据。

## 运行测试

安装开发依赖并运行模拟集成测试：

```sh
composer install
composer test
```
