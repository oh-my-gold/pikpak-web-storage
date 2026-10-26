# PikPak Web Storage

[English](README.md) · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md)

使用 Flysystem 3 與 PikPak Drive API 的 Laravel Storage 驅動程式。

Laravel 會透過套件自動探索註冊 `pikpak` 驅動程式。使用端需提供有效的 PikPak Bearer Token；本套件不會儲存、解密、更新或取代憑證。

## 環境需求

- PHP 8.1 或更新版本
- Laravel 10、11 或 12
- Flysystem 3
- 具備應用程式所需操作權限的 PikPak Bearer Token

## 安裝

使用 Composer 安裝：

```sh
composer require oh-my-gold/pikpak-web-storage:dev-master
```

Laravel 會自動探索 `PikPakWebStorageServiceProvider`。若已停用套件自動探索，請將 `OhMyGold\PikPakWebStorage\PikPakWebStorageServiceProvider::class` 加入應用程式的 providers 清單。

## 設定磁碟

在 `config/filesystems.php` 加入磁碟：

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

請將 `PIKPAK_TOKEN` 存放於密鑰管理器或環境變數中，不要提交至原始碼儲存庫。設定 `PIKPAK_ROOT_FOLDER_PATH=/備份/專案` 可將此資料夾作為磁碟根目錄。空路徑或 `/` 代表帳號根目錄。驅動程式會依需要逐層解析 PikPak 資料夾 ID，每次只查詢一層並使用資料夾篩選器；每頁最多請求 500 筆。讀取時，若設定的根路徑不存在會發生錯誤；寫入與建立目錄時則會建立缺少的目錄。路徑皆相對於設定的磁碟根目錄。

若 Laravel 快取可用，驅動程式預設使用應用程式的預設快取 store；若快取無法初始化或存取，會改用目前 adapter 執行個體的記憶體快取。可選設定 `folder_cache_store` 指定 Laravel 快取 store，`folder_cache_ttl` 指定快取秒數，預設為 `3600`。快取只儲存成功的資料夾查詢結果，並以 Bearer Token 雜湊值隔離。刪除目錄或呼叫 `deleteById()` 會清除相關資料夾對應，包括子目錄。外部資料夾變更會在快取到期後生效；也可清除所選快取 store 立即更新。驅動程式會檢查所有分頁；若同名資料夾不只一個，會發生錯誤。

為了相容性，`root_folder_id` 仍可設定起始資料夾 ID（`''` 或 `root` 代表帳號根目錄）。若同時設定兩個根目錄選項，`root_folder_path` 會從 `root_folder_id` 下開始解析。

`use_trash` 預設為 `true`。設為 `false` 時，檔案與目錄會直接透過 PikPak `files:batchDelete` API 永久刪除，不會先移至回收桶；每次刪除只送出一個 API 請求。此設定適用於 Laravel `delete()`、`deleteDirectory()` 及 adapter 的 `deleteById()` 方法。

`path_prefix` 會保留從根目錄列出時顯示路徑的前綴。輸入路徑若以該前綴開頭，查詢前會先移除前綴。不含此前綴的輸入仍相對於設定的磁碟根目錄，因此 `path_prefix` 不是存取控制邊界。

## 使用 Laravel Storage

```php
use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('pikpak');
$disk->put('photos/example.txt', '儲存至 PikPak');
$contents = $disk->get('photos/example.txt');
$stream = $disk->readStream('photos/example.txt');
fclose($stream);
```

常用方法：

| Laravel 呼叫 | 成功時回傳 | 說明 |
| --- | --- | --- |
| `$disk->fileExists('photos/a.txt')` / `fileMissing(...)` | `bool` | 檢查檔案是否存在，不包含已移入回收桶的項目。 |
| `$disk->directoryExists('photos')` / `directoryMissing(...)` | `bool` | 檢查目錄是否存在。 |
| `$disk->exists('photos/a.txt')` / `missing(...)` | `bool` | 檢查檔案或目錄是否存在。 |
| `$disk->makeDirectory('photos/2026')` | `true` | 建立路徑中所有缺少的目錄。 |
| `$disk->delete('photos/a.txt')` | `true` | 依 `use_trash` 設定刪除檔案；也接受檔案路徑陣列。 |
| `$disk->deleteDirectory('photos/2026')` | `true` | 依 `use_trash` 設定刪除目錄及其內容，並清除目錄 ID 快取。 |
| `$disk->copy($from, $to)` / `$disk->move($from, $to)` | `true` | 複製或移動檔案，並建立目的路徑中缺少的目錄。 |
| `$disk->put($path, $contents)` / `writeStream(...)` | `true` | 寫入字串或可讀取、可 seek 的串流。 |
| `$disk->putFile(...)` / `$disk->putFileAs(...)` | `string` 路徑 | Laravel 檔案上傳輔助方法。 |
| `$disk->get($path)` / `readStream(...)` | `string` / `resource` | 讀取完整內容或串流；呼叫端需關閉串流。 |
| `$disk->files(...)` / `allFiles(...)` | `string[]` | 列出目前目錄或遞迴列出檔案。 |
| `$disk->directories(...)` / `allDirectories(...)` | `string[]` | 列出目前目錄或遞迴列出目錄。 |
| `$disk->size(...)` / `mimeType(...)` / `lastModified(...)` | `int` / `string` / `int` | 取得檔案大小、MIME 類型與修改時間。 |
| `$disk->url($path)` | `string` | 回傳 PikPak 目前的內容連結；到期時間由 PikPak 決定。 |

若 `root_folder_path` 設為 `/備份/專案`，`photos/example.txt` 會指向 PikPak 中的 `/備份/專案/photos/example.txt`。空目錄路徑 `''` 代表磁碟根目錄。路徑區段中的 `.`、`..`、中間空區段及控制字元會被拒絕；`/` 與 `.` 可用來表示根目錄。

寫入會建立新的遠端檔案與上傳工作階段，不會以原子方式取代同名檔案；寫入已佔用的路徑可能產生重複檔案，之後的路徑查詢可能無法判定唯一項目。建議使用唯一路徑，或先找到並刪除舊檔案。Laravel 的 `append()` 和 `prepend()` 會先讀取再呼叫 `put()`，也有相同的重名限制；本套件不支援原位附加或覆寫。

`copy()` 和 `move()` 只支援檔案，不支援目錄複製或移動。清單請求會讀取所有分頁；若分頁 token 重複，會發生錯誤。同類型的同名項目不唯一時（包括出現在不同分頁中的重複名稱），驅動程式會發生錯誤。

## 列表與中繼資料

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

`listContents($path, false)` 只列出目前層；第二個參數設為 `true` 時會遞迴列出。`getVisibility()` 回傳 `private`。PikPak 目前的 Drive API 不支援變更 ACL；`setVisibility(..., 'private')` 可用，設定為 `public` 則會拋出 Flysystem 例外。Laravel 的 `download()` 與 `response()` 可用於控制器回應。Flysystem checksum 呼叫會使用串流回退實作。

adapter 沒有內建 `temporaryUrl()` 或 `temporaryUploadUrl()`。`path()` 只會格式化路徑字串，不會提供本機檔案路徑。Laravel 輔助方法是否可用，取決於 Laravel 版本以及主應用程式的 HTTP 與容器服務。

## 直接使用檔案 ID

已知穩定檔案 ID 時，可使用 `@id/{file-id}` 執行 `fileExists()`、讀取、檔案中繼資料查詢和 `delete()`：

```php
$exists = $disk->fileExists('@id/REMOTE_FILE_ID');
$contents = $disk->get('@id/REMOTE_FILE_ID');
$bytes = $disk->size('@id/REMOTE_FILE_ID');
// $disk->delete('@id/REMOTE_FILE_ID'); // 行為由 use_trash 設定決定。
```

直接檔案 ID 會存取帳號中的物件，並略過設定的資料夾路徑。此語法不支援目錄操作、寫入目的地或下文的路徑輔助方法。已知目錄 ID 時可使用 `deleteById()` 刪除。

## 取得瀏覽器上傳 URL

`getPresignedUploadUrl()` 使用應用程式已建立的上傳工作階段中的 `resumable.params` 簽署 PUT URL，並以字串回傳。此方法不會建立 PikPak 檔案或上傳工作階段。預設有效期為 15 分鐘，可設定為 1 到 3600 秒。

`getBrowserUploadAuthorization()` 適用於 UShow 目前的瀏覽器上傳回退流程：回傳帶簽章標頭的 PUT 請求，以及帶簽章表單欄位的 POST 請求。參數中的 MD5 必須使用 Base64 編碼，大小必須是精確位元組數。此方法不會建立上傳工作階段；工作階段 ID、檔案 ID 和完成流程仍由應用程式管理。回傳的授權資訊應視為短效憑證。

```php
$uploadParams = $metadata['resumable']['params'];
$md5 = base64_encode(md5($contents, true));
$authorization = $adapter->getBrowserUploadAuthorization($uploadParams, $md5, strlen($contents));

return response()->json(['upload' => $authorization]);
```

UShow 可保留現有的後端工作階段建立流程及 `fileId`、`sessionId` 管理，再由後端產生並回傳瀏覽器上傳 URL。若 UShow 已註冊自己的 `pikpak` 驅動程式，可直接建立本套件的 adapter：

```php
use OhMyGold\PikPakWebStorage\Flysystem\PikPakFilesystemAdapter;
use OhMyGold\PikPakWebStorage\Http\PikPakApiClient;

$adapter = new PikPakFilesystemAdapter(new PikPakApiClient($pikpakToken));
$uploadParams = $metadata['resumable']['params']; // 來自 UShow 現有的上傳工作階段請求。
$uploadUrl = $adapter->getPresignedUploadUrl($uploadParams);

return response()->json([
    'fileId' => $metadata['file']['id'],
    'sessionId' => $sessionId,
    'uploadUrl' => $uploadUrl,
]);
```

瀏覽器以單次 PUT 直接上傳檔案內容：

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

只有 PUT 成功後才繼續 UShow 的上傳完成流程。PikPak OSS 端點必須允許來自 UShow 網域的瀏覽器 CORS 請求，並允許 `PUT` 與 `Content-Type` 標頭。此方法只簽署單次 PUT，不提供分段或 multipart 上傳 URL。簽署 URL 是臨時授權資訊；請勿寫入記錄，也不要在上傳流程結束後繼續保存。

## 建立、查詢與撤銷分享

`share()` 接受檔案或目錄路徑，並回傳 `share_id`、`share_url`、`pass_code` 與 `share_text`。預設有效期為 7 天。不設定密碼時，任何取得連結的人都能存取分享內容。設定密碼時由 PikPak 自動產生密碼，並透過 `pass_code` 回傳；此方法不接受自訂密碼。

```php
$adapter = Storage::disk('pikpak')->getAdapter();

$share = $adapter->share('photos/example.txt'); // 不設定密碼，預設 7 天。
$url = $share['share_url'];

$protected = $adapter->share('photos', true, 30); // PikPak 產生密碼，有效 30 天。
$protectedUrl = $protected['share_url'];
$passCode = $protected['pass_code'];
```

帳號根目錄不能分享。若磁碟根目錄已設定，可傳入空路徑分享該根目錄。Token 必須具有 PikPak Share 權限。

`listShares()` 會自動讀取所有分頁，並回傳 PikPak 原始分享記錄。`revokeShare()` 接受 `share_id`；撤銷分享連結不會刪除其檔案或目錄。

```php
$shares = $adapter->listShares();
foreach ($shares as $share) {
    echo $share['share_id'] . ': ' . $share['share_url'] . PHP_EOL;
}

$adapter->revokeShare('share-id-to-revoke');
```

## adapter 方法參考

透過 Laravel 磁碟取得 adapter 和 Flysystem operator：

```php
$adapter = $disk->getAdapter(); // PikPakFilesystemAdapter
$driver = $disk->getDriver();   // Flysystem FilesystemOperator
```

adapter 的寫入與變更方法成功時回傳 `void`，失敗時拋出例外。直接中繼資料方法回傳 `League\Flysystem\FileAttributes`，而非純量值。

| 方法 | 回傳值 | 用途 |
| --- | --- | --- |
| `getUrl($path)` | `string` | PikPak 目前的內容連結。 |
| `getPresignedUploadUrl($params, $expiresInSeconds = 900)` | `string` | 為已建立工作階段的 `resumable.params` 簽署單次 PUT URL。 |
| `getBrowserUploadAuthorization($params, $contentMd5, $size)` | `array` | 回傳 UShow 瀏覽器上傳流程需要的簽章 PUT 請求和 POST 表單。 |
| `share($path, $withPassword = false, $expirationDays = 7)` | `array` | 建立分享並回傳 PikPak 分享欄位。 |
| `shareByIds($fileIds, $withPassword = false, $expirationDays = 7)` | `array` | 為多個已知 ID 建立一個分享；受密碼保護時由 PikPak 產生密碼。 |
| `listShares()` | `array` | 自動分頁並回傳原始分享記錄。 |
| `listSharesPage($limit = 100, $pageToken = '')` | `array` | 回傳單頁分享記錄，供應用程式自行管理分頁。 |
| `revokeShare($shareId)` | `void` | 撤銷指定分享。 |
| `sourceFileId($path)` | `?string` | 回傳路徑對應的檔案 ID；查詢失敗時回傳 `null`。 |
| `getFileById($id, $query = [], $extraHeaders = [])` | `array` | 依穩定 ID 查詢 Drive 檔案或資料夾中繼資料。 |
| `getFileByIdWithStatus($id, $query = [], $extraHeaders = [])` | `array` | 依穩定 ID 查詢 Drive 中繼資料並回傳 HTTP 狀態碼。 |
| `createUploadSession($parentId, $name, $size, $sha1)` | `array` | 建立 resumable 上傳工作階段，回傳 `data` 和 HTTP `status`。 |
| `createFolderBelowId($parentId, $name)` | `array` | 在指定父資料夾 ID 下建立一個子資料夾並回傳雲端記錄；空 ID 代表帳號根目錄。 |
| `listChildren($parentId, $includeIncomplete = false)` | `array` | 自動分頁讀取子項目；可選擇包含尚未完成的上傳。 |
| `listChildrenPage($parentId, $limit = 500, $pageToken = '', $includeIncomplete = false)` | `array` | 回傳一頁雲端子項目記錄。 |
| `occupiedChildInFolder($parentId, $name)` | `?array` | 查詢直接子項目；空 ID 代表帳號根目錄。若分頁未完成而無法確認不存在，會拋出例外。 |
| `fileIdIncludingIncomplete($path)` | `?string` | 依路徑查詢檔案 ID，包括仍在處理中的上傳；路徑重複時拋出例外。 |
| `filePhaseAtPath($id, $path)` | `?string` | 只有 ID 仍對應此路徑時才回傳檔案處理階段。 |
| `fileIsCompleteAtPath($id, $path)` | `bool` | 檢查檔案是否已達到 `PHASE_TYPE_COMPLETE`。 |
| `pathOccupied($path)` | `bool` | 檢查是否有同名檔案或目錄，包括未完成的上傳。 |
| `pathIsUniqueTo($path, $id)` | `bool` | 檢查唯一同名項目是否為指定檔案 ID。 |
| `ensureFolderPathAndGetId($path)` | `string` | 建立缺少的目錄並回傳最終目錄 ID；帳號根目錄沒有物件 ID。 |
| `ensureFolderBelowId($parentId, $relativeDirectory)` | `string` | 在指定目錄 ID 下建立多層目錄並回傳最終 ID。 |
| `deleteById($id)` | `void` | 依原始 ID 刪除檔案或目錄，行為由 `use_trash` 決定。 |
| `permanentlyDeleteById($id, $expected = [])` | `void` | 驗證檔案 ID 和可選的 `parent_id`、`name`；需要時先移至回收桶，再永久刪除。不存在時視為已清理。 |
| `lastUploadedFileId()` | `string` | 最近上傳工作階段回傳的檔案中繼資料 ID；不代表檔案內容已上傳成功。 |
| `lastUploadRequests()` | `array` | 最近上傳的安全 OSS 請求摘要，不含憑證值和簽署查詢參數。 |

`sourceFileId()` 使用 adapter 執行個體內的檔案 ID 快取；Laravel 資料夾快取 TTL 不會影響此快取。`deleteById()` 接受原始 ID，因此可存取設定根目錄以外的物件。`lastUploadedFileId()` 與 `lastUploadRequests()` 會在上傳開始時重設。`lastUploadRequests()` 可用於排除問題，但不含簽署 URL 或臨時憑證。

直接建立 adapter 時，第一個參數接受 `PikPakApiClientContract`。可直接使用內建 `PikPakApiClient`；應用程式也可實作此 contract，在使用套件檔案系統和 Drive 方法時保留自有憑證取得與 API 錯誤處理。其餘建構參數依序為：`string $rootFolderId = ''`、`string $keyPrefix = ''`、`?PikPakOssUploader $uploader = null`、`string $rootFolderPath = ''`、`?Illuminate\Contracts\Cache\Repository $folderCache = null`、`int $folderCacheTtl = 3600`、`bool $useTrash = true`。

## 錯誤處理

本文表格描述成功時的回傳值。停用 `throw` 時，Laravel 包裝器可能會針對 Flysystem 例外回傳 `false` 或 `null`；啟用 `throw` 時會繼續拋出 Flysystem 例外。可透過其 `previous` 例外檢查底層 `RuntimeException` 或 `PikPakApiException`。`sourceFileId()` 是例外：查詢錯誤時會攔截例外並回傳 `null`。

```php
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;

try {
    $contents = $disk->get('photos/example.txt');
} catch (PikPakApiException $error) {
    $httpStatus = $error->httpStatus;     // ?int
    $apiErrorCode = $error->apiErrorCode; // ?string，已清理的 API 錯誤碼
} catch (RuntimeException $error) {
    // 處理無效、不存在或重名路徑等錯誤。
}
```

## PikPak 行為與限制

- 寫入時會先透過 Drive API 建立檔案中繼資料與上傳工作階段，再將位元組直接傳送至暫時 OSS 端點。小於或等於 10 MiB 的檔案使用一次簽署 PUT；較大的檔案使用 10 MiB 分段。
- 讀取會使用 PikPak 目前的內容連結；`readStream()` 會回傳內容串流。
- `delete()` 與 `deleteDirectory()` 預設透過 `files:batchTrash` 移至 PikPak 回收桶。將 `use_trash` 設為 `false` 後，會以一次 `files:batchDelete` 請求永久刪除。刪除設定的磁碟根目錄會作用於該目錄；帳號根目錄沒有遠端物件 ID，因此不能刪除。
- `copy()` 使用 PikPak 批次複製操作。目的名稱不同時，套件會解析複製物件 ID 並重新命名；若 API 未回傳 ID 且無法唯一辨識新副本，會發生錯誤。
- PikPak 目前的 Drive API 不支援變更檔案可見性。`visibility()` 回傳 private；設定 public 會拋出 Flysystem 例外，不會假裝成功。
- PikPak 不允許呼叫端指定內容 URL 的到期時間，因此本套件未自行實作 `temporaryUrl()`；Laravel 應用程式若有其他 URL 服務，可安裝 `buildTemporaryUrlsUsing()` 回呼。
- 本套件採用的 Drive API 和暫時 OSS 上傳協定依據目前整合行為，並非穩定的公開 PikPak 開發者合約；API 變動時可能需要更新本套件。
- 可建立、查詢及撤銷分享連結。受密碼保護的分享密碼由 PikPak 產生；不支援自訂密碼。

本套件不實作 refresh token 驗證、驗證碼處理或帳號管理。使用端必須保護 Bearer Token，並依讀取、上傳、移動、複製、刪除和分享操作授予適當權限。驗證失敗會拋出例外；套件不會改用其他帳號或憑證重試。

## 執行測試

安裝開發相依套件並執行模擬整合測試：

```sh
composer install
composer test
```
