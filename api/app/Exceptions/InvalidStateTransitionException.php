<?php

namespace App\Exceptions;

use Throwable;

class InvalidStateTransitionException extends ApiException
{
    public function __construct(
        string $message,
        string $errorCode = 'ILLEGAL_STATE_TRANSITION',
        int $statusCode = 422,
        ?string $field = 'status',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $field, $previous);
    }
}
