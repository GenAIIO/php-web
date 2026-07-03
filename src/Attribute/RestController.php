<?php

namespace GenAI\Web\Attribute;

/**
 * Marks a class as a REST controller: the dispatcher wraps every action's
 * return value in a JsonResponse (unless the action already returns a Response).
 * Registered as a container bean.
 *
 * path is the controller's base path, prepended to every action's #[Route]:
 * #[RestController(path: '/api/users')] + #[Route('GET', '/{id}')] -> /api/users/{id}.
 *
 * Build-time only (PHP 8).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class RestController extends WebComponent
{
    /**
     * @param string      $tag         groups this controller's endpoints in API docs (genai/openapi)
     * @param string|null $description  description for that tag/group
     */
    public function __construct(
        public string $path = '',
        public string $tag = '',
        public ?string $description = null
    ) {
    }
}
