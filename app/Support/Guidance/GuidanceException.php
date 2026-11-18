<?php

namespace App\Support\Guidance;

use Illuminate\Validation\ValidationException;

class GuidanceException extends ValidationException
{
    public string $guidanceCode;

    public static function forCode(string $code, string $field, string $message): self
    {
        $exception = self::withMessages([$field => $message]);
        $exception->guidanceCode = $code;

        return $exception;
    }
}
