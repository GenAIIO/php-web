<?php

namespace GenAI\Web\Processor;

use GenAI\Attribute\AttributeProcessor;
use GenAI\Attribute\Context;
use GenAI\Routing\Route\Definition;
use GenAI\Routing\RouterRegister;
use GenAI\Web\Attribute\Controller;
use GenAI\Web\Attribute\RestController;
use GenAI\Web\Attribute\Route;

/**
 * Turns #[Route] methods into a compiled routes file (Cache\Router), using the
 * genai/routing engine (RouterRegister/RouteDumper) to bake the regexes at build.
 *
 * The full path is the controller's base path + the action's route: a method
 * #[Route('GET', '/{id}')] inside #[Controller(path: '/products')] registers as
 * /products/{id}. That is why this processor lives in genai/web — it reads the
 * #[Controller]/#[RestController] class stereotype — while genai/routing stays a
 * standalone matcher that only ever sees finished paths.
 *
 * It also validates each action against its (full) path at build time: every
 * {placeholder} must have a same-named action parameter, and on PHP 7+ that
 * parameter may not be class-typed (a route value is a URL string). Mistakes fail
 * the compile with a clear message rather than surfacing as nulls at runtime.
 *
 * Separate from WebProcessor by design: this one owns routing (Cache\Router),
 * WebProcessor owns the dispatch metadata (Cache\Web). BUILD-TIME ONLY (PHP 8).
 */
class RouteProcessor implements AttributeProcessor
{
    private RouterRegister $router;

    public function __construct()
    {
        $this->router = new RouterRegister();
    }

    public function getAttributeClass(): string
    {
        return Route::class;
    }

    public function process(object $attribute, \Reflector $target): void
    {
        /** @var \ReflectionMethod $target */
        $path = $this->fullPath($target->getDeclaringClass(), $attribute->path);
        $this->assertActionMatchesPath($path, $target);

        $handler = $target->getDeclaringClass()->getName() . '@' . $target->getName();
        $this->router->set(Definition::of($attribute->method, $path, $handler));
    }

    /**
     * Prefix the action's route with its controller's base path.
     *
     * @param \ReflectionClass $class     the declaring controller
     * @param string           $routePath the method's #[Route] path
     * @return string the full path to register
     */
    private function fullPath(\ReflectionClass $class, $routePath)
    {
        return $this->joinPath($this->basePath($class), $routePath);
    }

    /**
     * The base path from the class's #[Controller]/#[RestController], or '' when
     * the class has neither (a bare #[Route], registered at the root).
     *
     * @param \ReflectionClass $class
     * @return string
     */
    private function basePath(\ReflectionClass $class)
    {
        foreach (array(Controller::class, RestController::class) as $stereotype) {
            $attributes = $class->getAttributes($stereotype, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($attributes)) {
                return $attributes[0]->newInstance()->path;
            }
        }

        return '';
    }

    /**
     * Join a base and a route path into one normalized path, e.g.
     * ('/products', '/{id}') -> '/products/{id}', ('/products', '/') -> '/products',
     * ('', '/users') -> '/users'.
     *
     * @param string $base
     * @param string $path
     * @return string
     */
    private function joinPath($base, $path)
    {
        $base = rtrim($base, '/');
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Ensure the action can actually receive every path placeholder.
     *
     * @param string            $path   the full path, e.g. '/products/{id}'
     * @param \ReflectionMethod $method the action
     * @return void
     * @throws \LogicException When a placeholder has no matching parameter, or
     *                         (PHP 7+) is bound to a class-typed parameter.
     */
    private function assertActionMatchesPath($path, \ReflectionMethod $method)
    {
        if (!preg_match_all('/\{(\w+)(?::[^}]+)?\}/', $path, $matches)) {
            return; // a static path -> nothing to bind
        }

        $params = array();
        foreach ($method->getParameters() as $param) {
            $params[$param->getName()] = $param;
        }

        $where = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';

        foreach ($matches[1] as $name) {
            if (!isset($params[$name])) {
                throw new \LogicException(
                    'Route "' . $path . '" on ' . $where . ' has a {' . $name
                    . '} placeholder, but the action declares no $' . $name . ' parameter.'
                );
            }

            // getType() only exists on PHP 7.0+; on older build PHP we just skip
            // the type check (the runtime is 5.3 and has no scalar type hints).
            if (PHP_VERSION_ID >= 70000) {
                $type = $params[$name]->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    throw new \LogicException(
                        'Route "' . $path . '" on ' . $where . ' binds {' . $name . '} to $'
                        . $name . ' typed "' . $type->getName() . '", but a route value is a'
                        . ' string. Use a scalar type (e.g. string/int) or no type.'
                    );
                }
            }
        }
    }

    public function compile(Context $context): void
    {
        $this->router->dumpToFile($context->output('Router.php')); // class Cache\Router
    }
}
