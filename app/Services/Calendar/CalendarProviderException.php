<?php

namespace App\Services\Calendar;

use RuntimeException;

class CalendarProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $authRequired = false,
        public readonly bool $retryable = false,
        public readonly int $httpStatus = 0,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct('Google Calendar operation failed: '.$errorCode.'.');
    }
}
