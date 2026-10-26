<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Http;

/**
 * API transport contract used by the adapter.
 *
 * Applications can provide their own credential-aware client while keeping
 * Drive requests and filesystem behavior in this package.
 */
interface PikPakApiClientContract
{
    public function cacheIdentity(): string;

    public function get(string $url, array $query = [], array $extraHeaders = []): array;

    public function getWithStatus(string $url, array $query = [], array $extraHeaders = []): array;

    public function post(string $url, array $body = [], array $extraHeaders = []): array;

    public function postWithStatus(string $url, array $body = [], array $extraHeaders = []): array;

    public function patch(string $url, array $body = [], array $extraHeaders = []): array;
}
