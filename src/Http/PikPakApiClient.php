<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Http;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OhMyGold\PikPakWebStorage\Exceptions\PikPakApiException;

final class PikPakApiClient
{
    private const API_HOST = 'api-drive.mypikpak.com';

    public function __construct(
        private readonly string $token,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException('A PikPak Bearer token is required.');
        }
    }

    /** Isolates cached IDs between credentials without exposing the token. */
    public function cacheIdentity(): string
    {
        return hash('sha256', $this->token);
    }

    public function get(string $url, array $query = []): array
    {
        return $this->request('GET', $url, $query);
    }

    public function post(string $url, array $body = []): array
    {
        return $this->request('POST', $url, [], $body);
    }

    public function patch(string $url, array $body = []): array
    {
        return $this->request('PATCH', $url, [], $body);
    }

    private function request(string $method, string $url, array $query = [], array $body = []): array
    {
        $this->assertDriveApiUrl($url);

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'zh-TW,en;q=0.8',
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Origin' => 'https://mypikpak.com',
            'Referer' => 'https://mypikpak.com/',
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36',
        ];

        $request = Http::withHeaders($headers)->connectTimeout(10)->timeout(30);
        $response = match ($method) {
            'GET' => $request->get($url, $query),
            'POST' => $request->post($url, $body),
            'PATCH' => $request->patch($url, $body),
            default => throw new InvalidArgumentException('Unsupported PikPak API method.'),
        };

        $data = $response->json();
        if (!$response->successful()) {
            $code = $this->safeErrorCode($data);
            $message = 'PikPak Drive API request failed with HTTP ' . $response->status() . '.';
            if ($code !== null) {
                $message .= ' API error code: ' . $code . '.';
            }
            throw new PikPakApiException($message, $response->status(), $code);
        }

        if ($response->body() === '') {
            return [];
        }
        if (!is_array($data)) {
            throw new PikPakApiException('PikPak Drive API returned an invalid response.', $response->status());
        }
        if (!empty($data['error']) || !empty($data['error_code'])) {
            $code = $this->safeErrorCode($data);
            $message = 'PikPak Drive API returned an error.';
            if ($code !== null) {
                $message .= ' API error code: ' . $code . '.';
            }
            throw new PikPakApiException($message, $response->status(), $code);
        }

        return $data;
    }

    private function assertDriveApiUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::API_HOST) {
            throw new InvalidArgumentException('PikPak API requests must target the Drive API over HTTPS.');
        }
    }

    private function safeErrorCode(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }

        $value = $data['error_code'] ?? $data['error'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $code = (string) $value;
        if ($code === '' || strlen($code) > 64 || !preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
            return null;
        }

        return $code;
    }
}
