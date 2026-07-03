<?php

namespace GenAI\Web\Interceptor;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The "rest of the pipeline" handed to an Interceptor as $next: call
 * handle($request) to proceed to the next interceptor (and ultimately the
 * controller), getting back a Response.
 *
 * Mirrors PSR-15's RequestHandlerInterface, which we can't use directly because
 * it requires PHP 7. InterceptorChain is the implementation.
 *
 * Compatible with PHP 5.3.29.
 */
interface RequestHandler
{
    /**
     * @param ServerRequestInterface $request
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handle(ServerRequestInterface $request);
}
