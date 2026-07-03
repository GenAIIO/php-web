<?php

namespace GenAI\Web\Attribute;

/**
 * Marks a class as an HTML controller. Its actions return a View (rendered by
 * the engine), a Response, or a string. Registered as a container bean.
 *
 * path is the controller's base path, prepended to every action's #[Route]:
 * #[Controller(path: '/products')] + #[Route('GET', '/{id}')] -> /products/{id}.
 * Omit it (default '') to put the full path on each #[Route].
 *
 * Build-time only (PHP 8).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Controller extends WebComponent
{
    public function __construct(public string $path = '')
    {
    }
}
