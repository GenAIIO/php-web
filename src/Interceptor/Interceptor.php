<?php

namespace GenAI\Web\Interceptor;

use Psr\Http\Message\ServerRequestInterface;

/**
 * An HTTP interceptor — a request/response hook (Spring's HandlerInterceptor).
 * Mark an implementation with #[Intercept] and the Dispatcher runs it around the
 * controller, in order. The return value drives the flow:
 *
 *   - return null       -> you did NOT stop the request; the chain continues.
 *   - return a Response -> use it and stop here.
 *
 * The common guard case needs no $next at all — just check and return:
 *
 *   class AuthInterceptor implements Interceptor {
 *       public function intercept($request, RequestHandler $next) {
 *           if (empty($_SESSION['user'])) {
 *               return new RedirectResponse('/login');   // stop
 *           }
 *           return null;                                  // continue
 *       }
 *   }
 *
 * Use $next only when you want to act on the way OUT (onion style): get the
 * downstream response, modify it, and return it:
 *
 *   $response = $next->handle($request);
 *   return $response->withHeader('X-Trace', '1');
 *
 * (If you call $next->handle(), return its result — don't return null, or the
 * controller would run twice.)
 *
 * Compatible with PHP 5.3.29.
 */
interface Interceptor
{
    /**
     * @param ServerRequestInterface $request
     * @param RequestHandler         $next    call $next->handle($request) to act on the way out
     * @return \Psr\Http\Message\ResponseInterface|null Response to stop, null to continue
     */
    public function intercept(ServerRequestInterface $request, RequestHandler $next);
}
