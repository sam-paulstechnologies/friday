<?php

namespace App\Services\Inbox;

use RuntimeException;

class CaptureFailedException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('There was nothing to capture.');
    }

    public static function noWorkspace(): self
    {
        return new self('Miriam could not find a workspace it is allowed to write into. Your text was not saved — please copy it before leaving this page.');
    }
}
