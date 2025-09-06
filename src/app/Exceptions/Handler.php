<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof AccessDeniedHttpException) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Forbidden',
            ], 403);
        }
        return parent::render($request, $exception);
    }
    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // ...existing code...
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // Always return JSON for API requests to avoid Route [login] not defined error
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
