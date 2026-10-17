<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

abstract class BoilerplateException extends Exception
{
    /**
     * A machine-readable short code for API consumers.
     *
     * @example 'OPERATION_NOT_ALLOWED', 'BUSINESS_RULE_VIOLATED'
     */
    protected string $shortCode = 'BOILERPLATE_ERROR';

    /**
     * The HTTP status code for this exception.
     */
    protected int $statusCode = 422;

    public function __construct(string $message = '', int $statusCode = 0, ?\Throwable $previous = null)
    {
        if ($statusCode > 0) {
            $this->statusCode = $statusCode;
        }

        parent::__construct($message, 0, $previous);
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $this->shortCode,
                'message' => $this->getMessage(),
            ], $this->statusCode);
        }

        return Inertia::render('error-page', [
            'status' => $this->statusCode,
            'message' => $this->getMessage(),
        ])->toResponse($request)->setStatusCode($this->statusCode);
    }
}
