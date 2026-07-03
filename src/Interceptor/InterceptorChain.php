<?php

namespace GenAI\Web\Interceptor;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Runs the interceptor chain. It is the RequestHandler $next handed to each
 * Interceptor. An interceptor controls the flow purely by what it returns:
 *
 *   - return null              -> it did not stop the request; continue to the
 *                                 next interceptor (and ultimately the controller).
 *   - return a Response         -> use that response and stop here. This covers
 *                                 both a short-circuit (e.g. a redirect) and the
 *                                 onion style: $r = $next->handle($request);
 *                                 return $r->withHeader(...) to act on the way out.
 *
 * So a simple guard just returns null to pass and a Response to block — no need to
 * call $next at all. One instance per request; the index advances as it unwinds.
 *
 * Built so the runtime needs no closures (PHP 5.3 closures have no $this): the
 * chain is a plain RequestHandler, and the core handler is any callable.
 *
 * Compatible with PHP 5.3.29.
 */
class InterceptorChain implements RequestHandler
{
    /** @var Interceptor[] ordered */
    private $interceptors;

    /** @var callable core handler, called after the last interceptor */
    private $core;

    /** @var int */
    private $index = 0;

    /** @var bool whether the core (controller dispatch) has already run */
    private $coreInvoked = false;

    /**
     * @param Interceptor[] $interceptors ordered list
     * @param callable      $core         e.g. array($dispatcher, 'process')
     */
    public function __construct(array $interceptors, $core)
    {
        $this->interceptors = $interceptors;
        $this->core         = $core;
    }

    /**
     * @param ServerRequestInterface $request
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \RuntimeException On an invalid interceptor return value.
     */
    public function handle(ServerRequestInterface $request)
    {
        if ($this->index >= count($this->interceptors)) {
            if ($this->coreInvoked) {
                // Reached the controller twice: an interceptor called
                // $next->handle() but then returned null instead of its result.
                throw new \RuntimeException(
                    'The controller would run twice: an interceptor called $next->handle()'
                    . ' but returned null instead of the resulting Response. Either return'
                    . ' $next->handle($request), or return null only when you did not call $next.'
                );
            }
            $this->coreInvoked = true;

            return call_user_func($this->core, $request);
        }

        $interceptor = $this->interceptors[$this->index];
        $this->index++;

        $result = $interceptor->intercept($request, $this);

        if ($result === null) {
            return $this->handle($request); // did not stop the flow -> continue
        }
        if ($result instanceof ResponseInterface) {
            return $result;                 // a Response -> use it (short-circuit or wrapped)
        }

        throw new \RuntimeException(sprintf(
            '%s::intercept() must return a Response (to stop the request) or null (to continue); got %s.',
            get_class($interceptor),
            is_object($result) ? get_class($result) : gettype($result)
        ));
    }
}
