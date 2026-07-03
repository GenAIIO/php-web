<?php

namespace GenAI\Web\Attribute;

/**
 * Marks a controller method as a route, e.g. #[Route('GET', '/users/{id}')].
 *
 * The path is relative to the controller's base path: a method #[Route('GET',
 * '/{id}')] inside #[Controller(path: '/products')] serves /products/{id}.
 *
 * Lives in genai/web (not genai/routing) because it pairs with #[Controller]/
 * #[RestController] — genai/routing stays a standalone matching engine. Read by
 * RouteProcessor during compilation; BUILD-TIME ONLY (PHP 8). On the PHP 5.3
 * runtime the #[Route(...)] line is a plain comment.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @param string      $summary     one-line label for API docs (genai/openapi)
     * @param string|null $description  longer description for API docs
     * @param string|null $response     response DTO class for the 200 schema; append
     *                                  "[]" for an array, e.g. GameView::class . '[]'
     * @param int[]       $errorCodes   error statuses this action may return, e.g.
     *                                  [404, 422, 500]; each is documented with the
     *                                  shared Error schema in the OpenAPI spec
     * @param string|null $security     name of a security scheme this action requires
     *                                  (genai/openapi): 'bearer' (Authorization: Bearer)
     *                                  or 'apiKey' (X-KidSafe-Token header). null = public.
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $summary = '',
        public ?string $description = null,
        public ?string $response = null,
        public array $errorCodes = [],
        public ?string $security = null
    ) {
    }
}
