<?php

/**
 * php-web demo — the whole MVC slice in one file.
 *
 *   composer install      (or `composer update` if you change requires)
 *   php example.php
 *
 * This demo uses the DEFAULT view engine: there is no #[ViewRegister] class, so
 * Views render through php-view's Renderer automatically — we only tell the
 * dispatcher where templates live (setViewPath). To override, write a class that
 * implements ViewEngine and mark it #[ViewRegister]; only one is allowed.
 *
 * BUILD-TIME (PHP 8): the scanner finds three processors by type and runs them
 * over the fixtures:
 *   GenAI\Di\Processor   ComponentProcessor -> cache/Container.php
 *                        (#[Controller]/#[RestController] extend the di
 *                         Component, so they register as beans)
 *   GenAI\Web\Processor  RouteProcessor     -> cache/Router.php  (#[Route], with the
 *                                             controller base path prepended)
 *   GenAI\Web\Processor  WebProcessor       -> cache/Web.php
 *                        (the #[RestController] set + the view engine id,
 *                         which is null here -> default engine)
 *
 * RUNTIME (PHP 5.3-safe): load the three compiled files, then dispatch. No
 * scanning, no reflection — just array/closure lookups.
 */

use GenAI\Attribute\Context;
use GenAI\Attribute\Scanner;
use GenAI\Container\Container;
use GenAI\Dto\MapSerializer;
use GenAI\Http\Request;
use GenAI\Routing\Router;
use GenAI\Web\Dispatcher;

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';

@mkdir(__DIR__ . '/cache', 0777, true);

// ----- build -----------------------------------------------------------------
$scanner = new Scanner($loader);
$scanner->scan(array(
    'Demo',                   // the controllers, view engine, config (targets)
    'GenAI\\Di\\Processor',   // ships ComponentProcessor       -> Container.php
    'GenAI\\Web\\Processor',  // ships RouteProcessor + WebProcessor -> Router.php, Web.php
    'GenAI\\Dto\\Processor',  // ships DtoProcessor              -> Dto.php
));
$scanner->compile(new Context(__DIR__ . '/config', __DIR__ . '/cache'));

echo "===== cache/Web.php =====\n";
echo file_get_contents(__DIR__ . '/cache/Web.php') . "\n";

// ----- runtime ---------------------------------------------------------------
// Every compiled file is a Cache\* subclass, ready on construction.
$router     = new \Cache\Router();                      // extends Router
$container  = new \Cache\Container();                   // extends Container
$dispatcher = new \Cache\Web($router, $container);      // extends Dispatcher (web metadata baked)
$dispatcher->setSerializer(new \Cache\Dto());           // extends MapSerializer (#[Dto] map baked)
$dispatcher->setViewPath(__DIR__ . '/templates');       // default engine: where templates live

$requests = array(
    new Request('GET',  '/hello/world'),  // $name + ModelAndView -> 'hello' view name
    new Request('GET',  '/about'),        // no params -> 'about' view name
    new Request('GET',  '/greet/bob'),    // $name -> immutable View('hello', ...)
    new Request('GET',  '/api/ping'),     // #[RestController] -> data wrapped as JSON
    new Request('GET',  '/api/users/7'),  // #[RestController] -> JSON
    new Request('GET',  '/api/dto/9'),    // #[RestController] -> #[Dto] -> JSON (private props!)
    new Request('POST', '/api/users'),    // returns a Response -> passthrough (201)
    new Request('GET',  '/nope'),         // no match -> 404
);

echo "===== dispatch =====\n";
foreach ($requests as $request) {
    $response = $dispatcher->dispatch($request);
    printf(
        "%-4s %-16s -> %d  [X-Trace: %s] [X-Page: %s]\n",  // X-Trace: interceptor; X-Page: ModelAndView
        $request->getMethod(),
        $request->getUri()->getPath(),
        $response->getStatusCode(),
        $response->getHeaderLine('X-Trace'),
        $response->getHeaderLine('X-Page')
    );
    echo '    ' . trim((string) $response->getBody()) . "\n";
}
