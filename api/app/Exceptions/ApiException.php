<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Throwable;

class ApiException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;
    protected ?string $field;

    public function __construct(
        string $message,
        string $errorCode = 'BAD_REQUEST',
        int $statusCode = 400,
        ?string $field = null,
        ?Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->field = $field;

        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'field' => $this->field,
            ],
        ], $this->statusCode);
    }
}
