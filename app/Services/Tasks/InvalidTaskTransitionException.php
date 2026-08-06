<?php

namespace App\Services\Tasks;

use RuntimeException;

class InvalidTaskTransitionException extends RuntimeException
{
    public static function unknown(string $transition): self
    {
        return new self("\"{$transition}\" is not a task transition Miriam knows how to perform.");
    }

    public static function notAllowed(string $transition, string $status): self
    {
        return new self("A task with status \"{$status}\" cannot be moved by \"{$transition}\".");
    }
}
