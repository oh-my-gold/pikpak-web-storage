<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Upload;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class PikPakOssUploader
{
    private const MULTIPART_THRESHOLD = 10 * 1024 * 1024;
    private const PART_SIZE = 10 * 1024 * 1024;
    private const CONTENT_TYPE = 'application/octet-stream';

    private array $lastRequests = [];

    public function lastRequests(): array
    {
        return $this->lastRequests;
    }

    /** @param resource $stream */
    public function upload(array $params, $stream, int $size): void
    {
        $this->lastRequests = [];
        if (!is_resource($stream) || $size < 0) {
            throw new RuntimeException('PikPak upload requires a valid stream and size.');
        }

        $credentials = $this->credentials($params);
        if ($size <= self::MULTIPART_THRESHOLD) {
            $this->putSingle($credentials, $stream, $size);
            return;
        }

        $this->putMultipart($credentials, $stream, $size);
    }

    private function credentials(array $params): array
    {
        foreach (['endpoint', 'bucket', 'key', 'access_key_id', 'access_key_secret', 'security_token'] as $field) {
            if (!isset($params[$field]) || !is_string($params[$field]) || trim($params[$field]) === '') {
                throw new RuntimeException('PikPak upload credentials are incomplete.');
            }
        }

        $endpoint = preg_replace('#^https?://#i', '', trim((string) $params['endpoint']));
        if (!is_string($endpoint)
            || !preg_match('/\A[A-Za-z0-9.-]+\z/', $endpoint)
            || str_starts_with($endpoint, '.')
            || str_ends_with($endpoint, '.')
            || str_contains($endpoint, '..')) {
            throw new RuntimeException('PikPak upload endpoint is invalid.');
        }

        $bucket = trim((string) $params['bucket']);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{1,62}[A-Za-z0-9]$/', $bucket)) {
            throw new RuntimeException('PikPak upload bucket is invalid.');
        }

        $key = ltrim((string) $params['key'], '/');
        if ($key === '' || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new RuntimeException('PikPak upload object key is invalid.');
        }

        return [
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'key' => $key,
            'access_key_id' => (string) $params['access_key_id'],
            'access_key_secret' => (string) $params['access_key_secret'],
            'security_token' => trim((string) $params['security_token']),
        ];
    }

    private function putSingle(array $params, $stream, int $size): void
    {
        $this->rewind($stream);
        $md5Context = hash_init('md5');
        hash_update_stream($md5Context, $stream);
        $md5 = base64_encode(hash_final($md5Context, true));
        $this->rewind($stream);

        $host = $params['bucket'] . '.' . $params['endpoint'];
        $key = $params['key'];
        $url = 'https://' . $host . $this->encodedPath($key);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $securityHeader = 'x-oss-security-token:' . $params['security_token'];
        $canonicalResource = '/' . $params['bucket'] . '/' . $key;
        $signatureText = "PUT\n{$md5}\n" . self::CONTENT_TYPE . "\n{$date}\n{$securityHeader}\n{$canonicalResource}";
        $signature = base64_encode(hash_hmac('sha1', $signatureText, $params['access_key_secret'], true));

        $this->send('single-put', 'PUT', $url, [
            'Host' => $host,
            'Date' => $date,
            'Content-Type' => self::CONTENT_TYPE,
            'Content-Length' => (string) $size,
            'Content-MD5' => $md5,
            'x-oss-security-token' => $params['security_token'],
            'Authorization' => 'OSS ' . $params['access_key_id'] . ':' . $signature,
            'User-Agent' => 'oh-my-gold-pikpak-web-storage/1.0',
        ], $stream, self::CONTENT_TYPE);
    }

    private function putMultipart(array $params, $stream, int $size): void
    {
        $host = $params['bucket'] . '.' . $params['endpoint'];
        $key = $params['key'];
        $init = $this->signedRequest($params, $host, $key, 'PUT', ['uploads' => ''], '', '', 'multipart-init');
        if (!preg_match('/<UploadId>([^<]+)<\/UploadId>/', $init->body(), $matches)) {
            throw new RuntimeException('PikPak multipart upload could not be initialized.');
        }
        $uploadId = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');

        $parts = [];
        $offset = 0;
        $partNumber = 1;
        $this->rewind($stream);
        while ($offset < $size) {
            $length = min(self::PART_SIZE, $size - $offset);
            $contents = '';
            while (strlen($contents) < $length && !feof($stream)) {
                $chunk = fread($stream, $length - strlen($contents));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $contents .= $chunk;
            }
            if (strlen($contents) !== $length) {
                throw new RuntimeException('PikPak multipart source stream ended early.');
            }

            $response = $this->signedRequest($params, $host, $key, 'PUT', [
                'partNumber' => (string) $partNumber,
                'uploadId' => $uploadId,
            ], $contents, self::CONTENT_TYPE, 'multipart-part-' . $partNumber);
            $etag = trim((string) $response->header('ETag'), '" ');
            if ($etag === '') {
                throw new RuntimeException('PikPak multipart part was not acknowledged.');
            }
            $parts[] = '<Part><PartNumber>' . $partNumber . '</PartNumber><ETag>'
                . htmlspecialchars('"' . $etag . '"', ENT_XML1)
                . '</ETag></Part>';
            $offset += $length;
            $partNumber++;
        }

        $xml = '<CompleteMultipartUpload>' . implode('', $parts) . '</CompleteMultipartUpload>';
        $this->signedRequest($params, $host, $key, 'POST', ['uploadId' => $uploadId], $xml, 'application/xml', 'multipart-complete');
    }

    private function signedRequest(
        array $params,
        string $host,
        string $key,
        string $method,
        array $query,
        mixed $body,
        string $contentType,
        string $stage,
    ): Response {
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonicalQuery = implode('&', array_map(
            static fn ($name, $value): string => $value === '' ? $name : $name . '=' . $value,
            array_keys($query),
            array_values($query),
        ));
        $url = 'https://' . $host . $this->encodedPath($key) . ($queryString !== '' ? '?' . $queryString : '');
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $hasBody = !is_string($body) || $body !== '';
        $md5 = $hasBody ? base64_encode(md5(is_resource($body) ? $this->readStreamBody($body) : $body, true)) : '';
        if (is_resource($body)) {
            $this->rewind($body);
        }

        $type = $hasBody ? $contentType : '';
        $canonicalResource = '/' . $params['bucket'] . '/' . $key . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $canonicalHeaders = 'x-oss-security-token:' . $params['security_token'] . "\n";
        $stringToSign = $method . "\n" . $md5 . "\n" . $type . "\n" . $date . "\n" . $canonicalHeaders . $canonicalResource;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $params['access_key_secret'], true));
        $headers = [
            'Host' => $host,
            'Date' => $date,
            'x-oss-security-token' => $params['security_token'],
            'Authorization' => 'OSS ' . $params['access_key_id'] . ':' . $signature,
            'User-Agent' => 'oh-my-gold-pikpak-web-storage/1.0',
        ];
        if ($hasBody) {
            $headers['Content-Type'] = $type;
            $headers['Content-MD5'] = $md5;
        }

        return $this->send($stage, $method, $url, $headers, $body, $hasBody ? $type : '');
    }

    private function send(
        string $stage,
        string $method,
        string $url,
        array $headers,
        mixed $body = '',
        string $contentType = '',
    ): Response {
        $startedAt = microtime(true);
        $request = Http::withOptions([
            'connect_timeout' => 10,
            'http_errors' => false,
            'stream' => true,
            'timeout' => 120,
        ])->withHeaders($headers);
        if (!is_string($body) || $body !== '') {
            $request = $request->withBody($body, $contentType);
        }

        $this->record('request-started', $stage, $method, $url, null, $startedAt);
        try {
            $response = $request->send($method, $url);
        } catch (Throwable) {
            $this->record('request-failed', $stage, $method, $url, null, $startedAt, '', 'network-error');
            throw new RuntimeException('PikPak object storage request failed.');
        }

        $requestId = (string) ($response->header('x-oss-request-id') ?: $response->header('x-request-id') ?: '');
        $this->record('response-received', $stage, $method, $url, $response->status(), $startedAt, $requestId);
        if (!$response->successful()) {
            throw new RuntimeException('PikPak object storage returned HTTP ' . $response->status() . '.');
        }

        return $response;
    }

    private function record(
        string $event,
        string $stage,
        string $method,
        string $url,
        ?int $status,
        float $startedAt,
        string $requestId = '',
        string $error = '',
    ): void {
        $entry = [
            'event' => $event,
            'stage' => $stage,
            'method' => $method,
            'target' => $this->safeTarget($url),
            'status' => $status,
            'duration_ms' => $event === 'request-started'
                ? null
                : max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'request_id' => $requestId,
        ];
        if ($error !== '') {
            $entry['error'] = $error;
        }
        $this->lastRequests[] = $entry;
    }

    private function safeTarget(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return 'PikPak object storage';
        }

        return (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
            . $parts['host'] . ($parts['path'] ?? '');
    }

    private function encodedPath(string $key): string
    {
        return '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function readStreamBody($stream): string
    {
        $position = ftell($stream);
        $this->rewind($stream);
        $body = stream_get_contents($stream);
        if ($body === false) {
            throw new RuntimeException('PikPak multipart request body could not be read.');
        }
        if ($position !== false && fseek($stream, $position) !== 0) {
            throw new RuntimeException('PikPak multipart request stream could not be restored.');
        }

        return $body;
    }

    private function rewind($stream): void
    {
        if (rewind($stream) !== true) {
            throw new RuntimeException('PikPak upload stream must be seekable.');
        }
    }
}
