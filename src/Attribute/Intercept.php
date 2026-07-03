<?php

namespace GenAI\Web\Attribute;

/**
 * Marks an Interceptor class so the Dispatcher runs it around controllers.
 *
 *   #[Intercept(order: 10, path: '/api/*')]
 *   class AuthInterceptor implements \GenAI\Web\Interceptor\Interceptor { ... }
 *
 * Extends WebComponent, so the di ComponentProcessor registers the interceptor as
 * an autowired bean (it can depend on other services) and the WebProcessor adds
 * it to the compiled chain.
 *
 *   - order:  lower runs further out (earlier on the way in, later on the way out).
 *   - path:   optional fnmatch() pattern; the interceptor only runs for matching
 *             request paths. Null = all requests.
 *   - exclude: fnmatch() patterns to skip (Spring's excludePathPatterns). The
 *             interceptor does NOT run when the path matches any of them — e.g.
 *             an auth interceptor with exclude: ['/login', '/health', '/'].
 *
 * Build-time only (PHP 8); a comment on the PHP 5.3 runtime.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Intercept extends WebComponent
{
    public function __construct(
        public int $order = 0,
        public ?string $path = null,
        public array $exclude = []
    ) {
    }
}
