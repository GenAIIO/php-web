<?php

namespace GenAI\Web\Interceptor;

use GenAI\Http\Response;
use GenAI\Web\Csrf;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Verifies the CSRF token on state-changing requests (POST/PUT/PATCH/DELETE),
 * reading it from the 'csrf' form field or the X-CSRF-Token header. Safe methods
 * (GET/HEAD/OPTIONS) pass straight through. A bad/missing token stops the request
 * with 403; a valid one continues to the controller.
 *
 * This base is NOT itself an #[Intercept] — an app enables (and scopes) it with a
 * thin subclass, so it stays opt-in:
 *
 *   #[Intercept(exclude: ['/webhooks/*'])]
 *   class CsrfGuard extends \GenAI\Web\Interceptor\CsrfInterceptor {}
 *
 * Compatible with PHP 5.3.29.
 */
class CsrfInterceptor implements Interceptor
{
    private $csrf;

    public function __construct(Csrf $csrf)
    {
        $this->csrf = $csrf;
    }

    public function intercept(ServerRequestInterface $request, RequestHandler $next)
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH' && $method !== 'DELETE') {
            return null; // safe method -> nothing to verify
        }

        $body  = $request->getParsedBody();
        $token = (is_array($body) && isset($body['csrf'])) ? $body['csrf'] : $request->getHeaderLine('X-CSRF-Token');

        if ($this->csrf->check($token)) {
            return null; // valid -> continue to the controller
        }

        return new Response('Invalid or missing CSRF token.', 403);
    }
}
