<?php

namespace Demo;

use GenAI\Http\JsonResponse;
use GenAI\Web\Attribute\Route;
use GenAI\Web\Attribute\RestController;
use Psr\Http\Message\ServerRequestInterface;

/**
 * #[RestController] — the dispatcher JSON-wraps each action's return value, so
 * actions just return data. Actions still declare only what they need: a route
 * value ($id), the request, etc. Return a Response directly for full control.
 *
 * Runtime class (PHP 5.3-safe).
 */
#[RestController]
class ApiController
{
    // No params.
    #[Route('GET', '/api/ping')]
    public function ping()
    {
        return array('pong' => true);
    }

    // {id} arrives as $id -- no need to take the whole request.
    #[Route('GET', '/api/users/{id}')]
    public function user($id)
    {
        return array('id' => $id, 'name' => 'alice');
    }

    // Return a #[Dto] (private props) -- it serializes via its compiled getter map.
    #[Route('GET', '/api/dto/{id}')]
    public function dto($id)
    {
        return new UserDTO($id, 'Alice');   // -> {"id":"...","name":"Alice"}
    }

    // Take the request when you actually want it.
    #[Route('POST', '/api/users')]
    public function create(ServerRequestInterface $request)
    {
        return new JsonResponse(array('created' => true), 201); // explicit Response -> passthrough
    }
}
