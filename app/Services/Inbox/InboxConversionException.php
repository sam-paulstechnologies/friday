<?php

namespace App\Services\Inbox;

use RuntimeException;

class InboxConversionException extends RuntimeException
{
    public static function for(string $reason): self
    {
        return new self(match ($reason) {
            'cancelled' => 'That capture was dismissed, so it cannot be converted.',
            'workspace_missing' => 'Miriam could not find a workspace it is allowed to write this task into.',
            default => 'Miriam could not convert that capture. The original wording has been kept.',
        });
    }
}
