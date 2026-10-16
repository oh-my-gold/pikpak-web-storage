# Presigned Upload URL Design

## Goal

Expose a package method that returns one browser-usable PikPak upload URL for an upload session that has already been created. Document how UShow can call it while continuing to own its upload-session and file metadata lifecycle.

## Scope

- Change only `oh-my-gold/pikpak-web-storage`.
- Add a public helper on `PikPakFilesystemAdapter` and document its use in `README.md`.
- Include an UShow integration example based on UShow's existing server-created resumable session.
- Do not modify UShow or create a new Drive file/session in this helper.

## Considered Approaches

1. **Sign an existing session's PUT URL (selected).** The caller passes the session's `resumable.params`; the package returns a presigned URL string. This fits UShow's current flow, where UShow creates the session and retains `fileId` and `sessionId`.
2. **Create and manage the entire upload session in this package.** This would duplicate UShow's existing file/session lifecycle and require additional state and completion behavior.
3. **Expose only the low-level OSS signer.** This would make consumers depend on an implementation-level uploader class and handle more signing details themselves.

## Public API

Add the following public method to `PikPakFilesystemAdapter`:

```php
public function getPresignedUploadUrl(array $params, int $expiresInSeconds = 900): string
```

The method name describes obtaining a URL for an existing upload session. It does not create a PikPak file, upload session, or upload task. It derives a short-lived, single-object `PUT` URL from the temporary OSS parameters returned by PikPak.

The method returns only the URL string. It does not return `fileId`, `sessionId`, headers, or form fields. The caller retains and returns its own file/session identifiers separately.

The default URL lifetime is 900 seconds. Reject non-positive expiration values and values above 3600 seconds. The URL is an authorization credential until expiry, so callers should avoid logging it or storing it longer than needed.

## Signing and Validation

- Reuse the package's existing OSS parameter validation and OSS V1 signing conventions.
- Require the existing session fields: `endpoint`, `bucket`, `key`, `access_key_id`, `access_key_secret`, and `security_token`.
- Sign `PUT` for the session's exact object key and include the STS security token in the query string.
- Use the request content type `application/octet-stream`; consumers must send that same `Content-Type` header with the PUT.
- Keep the existing server-side upload implementation unchanged.

## UShow Integration

UShow continues to create the PikPak session through its current `UPLOAD_TYPE_RESUMABLE` flow and retain `fileId` and `sessionId`. Its backend passes the returned `resumable.params` to this package method, then includes the returned URL in its response as an `uploadUrl` string. The frontend sends the file bytes using `PUT` and `Content-Type: application/octet-stream`.

This integration requires PikPak's OSS endpoint to allow browser CORS requests from the UShow origin for `PUT` and the `Content-Type` header. The method is for a single PUT; multipart/chunked upload orchestration is outside this API.

## Verification

Add package tests for URL construction, required session parameters, expiration bounds, and that no HTTP request is sent while generating the URL. Preserve all existing tests. A live PikPak session upload remains a separate integration check because it requires real credentials and a session.
