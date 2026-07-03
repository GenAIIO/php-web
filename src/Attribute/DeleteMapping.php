<?php

namespace GenAI\Web\Attribute;

/**
 * Shortcut for #[Route('DELETE', ...)] — e.g. #[DeleteMapping('/{id}')]. The path
 * is relative to the controller's base path, exactly like #[Route].
 *
 * Extends Route, so RouteProcessor and WebProcessor pick it up via IS_INSTANCEOF
 * with no special handling. Build-time only (PHP 8).
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class DeleteMapping extends Route
{
    public function __construct(string $path = '', string $summary = '', ?string $description = null, ?string $response = null, array $errorCodes = [], ?string $security = null)
    {
        parent::__construct('DELETE', $path, $summary, $description, $response, $errorCodes, $security);
    }
}
