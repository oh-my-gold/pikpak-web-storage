<?php

declare(strict_types=1);

namespace OhMyGold\PikPakWebStorage\Exceptions;

use RuntimeException;

final class PikPakApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $apiErrorCode = null,
    ) {
        parent::__construct($message);
    }
}
