<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors extends \Illuminate\Http\Middleware\HandleCors
{
    public function handle($request, Closure $next): Response
    {
        if ($request instanceof Request && $request->is('api/business', 'api/business/*')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
