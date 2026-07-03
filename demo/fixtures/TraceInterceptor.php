<?php

namespace Demo;

use GenAI\Web\Attribute\Intercept;
use GenAI\Web\Interceptor\Interceptor;
use GenAI\Web\Interceptor\RequestHandler;

/**
 * Runs around every request except /about (see `exclude`). Adds a header on the
 * way out, and could just as easily short-circuit (return a Response without
 * calling $next) -- which is how an auth interceptor would redirect to /login.
 *
 * Runtime class (PHP 5.3-safe).
 */
#[Intercept(order: 0, exclude: ['/about'])]
class TraceInterceptor implements Interceptor
{
    public function intercept($request, RequestHandler $next)
    {
        $response = $next->handle($request);   // proceed to the next interceptor / controller

        return $response->withHeader('X-Trace', 'demo');
    }
}
