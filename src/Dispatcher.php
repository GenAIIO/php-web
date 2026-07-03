<?php

namespace GenAI\Web;

use GenAI\Container\Container;
use GenAI\Dto\Serializer;
use GenAI\Http\HtmlResponse;
use GenAI\Http\JsonResponse;
use GenAI\Http\Request;
use GenAI\Http\Response;
use GenAI\Routing\Router;
use GenAI\View\Renderer;
use GenAI\Web\Interceptor\Interceptor;
use GenAI\Web\Interceptor\InterceptorChain;
use GenAI\Web\View\ModelAndView;
use GenAI\Web\View\RendererEngine;
use GenAI\Web\View\View;
use GenAI\Web\View\ViewEngine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * MVC dispatcher: matches a request, resolves the controller bean, calls the
 * action with the arguments it declares, then shapes the result. This is a
 * superset of GenAI\Http\Dispatcher — reach for that minimal one instead when
 * you only need routing -> controller -> Response (no views/JSON/arg plans).
 *
 * Action arguments are supplied from a plan compiled into web.php (no runtime
 * reflection). Each parameter is filled by its type/name:
 *
 *   public function show($id, ModelAndView $model, ServerRequestInterface $req)
 *           ^route value {id}     ^fresh model         ^the request
 *
 *   - a request-typed param  -> the ServerRequest
 *   - ModelAndView           -> a fresh, mutable model the action fills
 *   - another class type      -> a container service (autowired by class)
 *   - anything else           -> a request attribute by name (route values land here)
 *
 * Result shaping:
 *   - a Response             -> sent as-is
 *   - a #[RestController]    -> the return value is wrapped in JSON
 *   - a View                 -> rendered via the view engine (name + data)
 *   - a ModelAndView         -> rendered (its own view name + data)
 *   - a string (#[Controller])-> a view name, rendered with the injected model's
 *                               data (return an HtmlResponse for a raw body)
 *
 * #[Intercept] interceptors (compiled into web.php, ordered) wrap the whole
 * thing as an onion: each may short-circuit with its own Response or act on the
 * response on the way out. See GenAI\Web\Interceptor\Interceptor.
 *
 * REST controllers and the (optional) custom engine come from the compiled
 * metadata (web.php). When no #[ViewRegister] engine was registered, Views are
 * rendered with the default genai/view engine; tell it where templates live via
 * setViewPath(), or register a GenAI\View\Renderer bean in the container.
 *
 *   $d = new Dispatcher($router, $container);
 *   $d->loadMetadata(__DIR__ . '/cache/web.php');
 *   $d->run();
 *
 * Compatible with PHP 5.3.29.
 */
class Dispatcher
{
    /** @var Router */
    private $router;

    /** @var Container */
    private $container;

    /** @var array class => true */
    private $rest = array();

    /** @var string|null custom view engine bean id (#[ViewRegister]); null = use default */
    private $engineId = null;

    /** @var string|null templates dir for the default engine */
    private $viewPath = null;

    /** @var ViewEngine|null resolved engine (memoised) */
    private $engine = null;

    /** @var array handler "Class@action" => list of arg descriptors */
    private $args = array();

    /** @var array ordered list of array('id' => beanId, 'path' => pattern|null) */
    private $interceptors = array();

    /** @var Serializer|null turns a REST result into JSON-encodable data (e.g. DTO -> array) */
    private $serializer = null;

    /** @var bool catch errors and render the 500 page (prod) vs. let them surface (dev) */
    private $handleExceptions = false;

    public function __construct(Router $router, Container $container)
    {
        $this->router    = $router;
        $this->container = $container;
    }

    /**
     * Whether to catch a thrown error and render the 500 page (true, prod) or let
     * it propagate to the dev's error display (false). The Kernel sets this from
     * [app] handleExceptions. Default false — never silently swallow if unset.
     *
     * @param bool $flag
     * @return Dispatcher $this, for chaining.
     */
    public function setHandleExceptions($flag)
    {
        $this->handleExceptions = (bool) $flag;

        return $this;
    }

    /**
     * Set the Serializer applied to a #[RestController] result before JSON-encoding
     * — e.g. genai/dto's MapSerializer turns returned DTOs into arrays. Optional;
     * without one the result is encoded as-is. The Serializer interface lives in
     * genai/dto (a "suggest"); the hint resolves only when you actually pass one,
     * so php-web stays usable without that component.
     *
     * @param Serializer $serializer
     * @return Dispatcher $this, for chaining.
     */
    public function setSerializer(Serializer $serializer)
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Where the default view engine looks for templates. Ignored when a custom
     * #[ViewRegister] engine is registered, or when a GenAI\View\Renderer bean
     * already exists in the container.
     *
     * @param string $directory
     * @return Dispatcher $this, for chaining.
     */
    public function setViewPath($directory)
    {
        $this->viewPath = $directory;

        return $this;
    }

    /**
     * Apply the compiled web metadata (REST controllers, view engine, action arg
     * plans, interceptors). Called by Cache\Web::loadInto($dispatcher).
     *
     * @param array $meta
     * @return Dispatcher $this, for chaining.
     */
    public function setMetadata(array $meta)
    {
        $this->rest         = isset($meta['rest']) ? $meta['rest'] : array();
        $this->engineId     = isset($meta['engine']) ? $meta['engine'] : null;
        $this->args         = isset($meta['args']) ? $meta['args'] : array();
        $this->interceptors = isset($meta['interceptors']) ? $meta['interceptors'] : array();

        return $this;
    }

    /**
     * Dispatch a request through the interceptor chain to the controller.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws \RuntimeException
     */
    public function dispatch(ServerRequestInterface $request)
    {
        try {
            $interceptors = $this->resolveInterceptors($request->getUri()->getPath());
            if (empty($interceptors)) {
                return $this->process($request);
            }

            $chain = new InterceptorChain($interceptors, array($this, 'process'));

            return $chain->handle($request);
        } catch (\Exception $e) {
            // handleExceptions off (dev): surface the real error + trace. On (prod):
            // render the 500 page instead of a white-screen. Set by the Kernel from
            // [app] handleExceptions.
            if (!$this->handleExceptions) {
                throw $e;
            }
            return $this->serverError($request);
        }
    }

    /**
     * The core handler the interceptor chain ends at: match, resolve the
     * controller, call the action, shape the result. Public so InterceptorChain
     * can invoke it as a callable; treat it as internal (call dispatch()).
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws \RuntimeException
     */
    public function process(ServerRequestInterface $request)
    {
        try {
            $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
            if ($match === false) {
                return $this->notFound($request);
            }

            return $this->invoke($match, $request);
        } catch (\Exception $e) {
            // Build the 500 response HERE — inside the interceptor chain — so on-the-
            // way-out interceptors (e.g. trace's response header) still decorate it.
            // Dev (handleExceptions off) re-throws so the raw error + trace surface;
            // dispatch()'s outer catch stays as a safety net for interceptor failures.
            if (!$this->handleExceptions) {
                throw $e;
            }
            return $this->serverError($request);
        }
    }

    /**
     * No route matched. If the app defines a GET /errors/404 route, render it — a
     * real controller, so the error page gets the full layout + DI data and sets
     * its own status via ModelAndView->status(404). Otherwise a plain-text 404.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function notFound(ServerRequestInterface $request)
    {
        $fallback = $this->router->match('GET', '/errors/404');
        if ($fallback !== false) {
            try {
                return $this->invoke($fallback, $request);
            } catch (\Exception $e) {
                // The app's error page is broken (missing bean, bad template, ...).
                // Never white-screen on an error page — fall through to the default.
            }
        }

        return $this->defaultErrorPage(404, 'Page not found');
    }

    /**
     * An uncaught exception reached the dispatcher (prod). If the app defines a
     * GET /errors/500 route, render it; otherwise the framework default. Falls back
     * to the default if the app's own 500 page is itself broken.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function serverError($request)
    {
        $fallback = $this->router->match('GET', '/errors/500');
        if ($fallback !== false) {
            try {
                return $this->invoke($fallback, $request);
            } catch (\Exception $e) {
                // the app's 500 page is broken too — fall through to the default
            }
        }

        return $this->defaultErrorPage(500, 'Something went wrong');
    }

    /**
     * The framework's built-in error page — a self-contained HTML response with no
     * app data, used when the app hasn't provided its own (a GET /errors/<status>
     * route). Apps override by defining that route (see notFound()).
     *
     * @param int    $status
     * @param string $message
     * @return HtmlResponse
     */
    public function defaultErrorPage($status, $message)
    {
        $status  = (int) $status;
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        // A detail line that fits the status family (don't say "not found" on a 500).
        if ($status >= 500) {
            $detail = 'Something went wrong on our end. Please try again later.';
        } elseif ($status === 404) {
            $detail = 'The page you requested could not be found.';
        } else {
            $detail = "Sorry, we couldn't process that request.";
        }
        $detail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,follow">'   // error pages must not be indexed
            . '<title>' . $status . ' ' . $message . '</title><style>'
            . 'html,body{height:100%;margin:0}'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
            . 'background:#f6f7f9;color:#3a3f47;display:flex;align-items:center;justify-content:center}'
            . '.box{text-align:center;padding:40px 24px}'
            . '.code{font-size:72px;font-weight:800;color:#c9ced6;margin:0;line-height:1}'
            . 'h1{font-size:20px;font-weight:700;margin:10px 0 4px}'
            . 'p{color:#8a909a;margin:0 0 18px}'
            . 'a{display:inline-block;color:#fff;background:#5b8def;text-decoration:none;'
            . 'font-weight:700;border-radius:10px;padding:10px 18px}'
            . '</style></head><body><div class="box">'
            . '<p class="code">' . $status . '</p>'
            . '<h1>' . $message . '</h1>'
            . '<p>The page you requested could not be found.</p>'
            . '<a href="/">Go to homepage</a>'
            . '</div></body></html>';

        return new HtmlResponse($html, $status, array('Cache-Control' => 'no-store'));
    }

    /**
     * Invoke a matched handler and shape its return value into a Response.
     *
     * @param \GenAI\Routing\RouteMatch $match
     * @param ServerRequestInterface    $request
     * @return ResponseInterface
     * @throws \RuntimeException
     */
    private function invoke($match, $request)
    {
        foreach ($match->getParams() as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        list($class, $action) = $this->parse($match->getHandler());

        // Assemble the action's declared arguments from the compiled plan. $model
        // (if the action asked for a ModelAndView) is kept so a returned view-name
        // string can be rendered with the data the action put into it.
        $model     = null;
        $arguments = $this->arguments($class . '@' . $action, $request, $model);

        $controller = $this->container->get($class);
        $result     = call_user_func_array(array($controller, $action), $arguments);

        // Action already produced a response.
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        // REST controller: run the result through the serializer (if any), wrap in JSON.
        if (isset($this->rest[$class])) {
            $payload = $this->serializer !== null ? $this->serializer->serialize($result) : $result;
            return new JsonResponse($payload);
        }

        // #[Controller] from here. A View carries a template + data.
        if ($result instanceof View) {
            return $this->html($this->engine()->render($result), null);
        }

        // A ModelAndView carrying its own view name (and any headers).
        if ($result instanceof ModelAndView) {
            return $this->html($this->renderModel($result), $result);
        }

        // A bare string is a view name -- rendered with the injected model's data
        // (empty if the action didn't ask for a ModelAndView). For a raw HTML body,
        // return new HtmlResponse($html) explicitly.
        if (is_string($result)) {
            $data = $model !== null ? $model->all() : array();
            return $this->html($this->engine()->render(new View($result, $data)), $model);
        }

        // void/null but the action filled a ModelAndView that knows its own view.
        if ($result === null && $model !== null && $model->getView() !== null) {
            return $this->html($this->renderModel($model), $model);
        }

        throw new \RuntimeException(
            'A #[Controller] action must return a View, a ModelAndView, a view-name string, or a Response; got '
            . gettype($result) . '.'
        );
    }

    /**
     * Resolve the interceptor beans that apply to this path, in compiled order.
     * An interceptor with a 'path' pattern only runs when it fnmatch()es; one with
     * 'exclude' patterns is skipped when the path matches any of them.
     *
     * @param string $path request path
     * @return Interceptor[]
     */
    private function resolveInterceptors($path)
    {
        $resolved = array();
        foreach ($this->interceptors as $entry) {
            if ($entry['path'] !== null && !fnmatch($entry['path'], $path)) {
                continue;
            }
            if ($this->matchesAny($path, isset($entry['exclude']) ? $entry['exclude'] : array())) {
                continue;
            }
            $resolved[] = $this->container->get($entry['id']);
        }

        return $resolved;
    }

    /**
     * @param string $path
     * @param array  $patterns fnmatch() patterns
     * @return bool true if $path matches any pattern
     */
    private function matchesAny($path, $patterns)
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the argument list for an action from its compiled plan. Falls back to
     * passing just the request when no plan exists (e.g. metadata not loaded).
     *
     * @param string                 $handler "Class@action"
     * @param ServerRequestInterface $request
     * @param ModelAndView|null      $model   set by-reference if the action wants one
     * @return array
     */
    private function arguments($handler, ServerRequestInterface $request, &$model)
    {
        if (!isset($this->args[$handler])) {
            return array($request);
        }

        $arguments = array();
        foreach ($this->args[$handler] as $arg) {
            switch ($arg['source']) {
                case 'request':
                    $arguments[] = $request;
                    break;
                case 'model':
                    $model       = new ModelAndView();
                    $arguments[] = $model;
                    break;
                case 'service':
                    $arguments[] = $this->container->get($arg['id']);
                    break;
                case 'form':
                    $arguments[] = $this->bindForm($arg, $request);
                    break;
                case 'attribute':
                default:
                    $arguments[] = $request->getAttribute($arg['name']);
                    break;
            }
        }

        return $arguments;
    }

    /**
     * Instantiate a form and bind the request body into it using the compiled
     * plan (public property or setXxx() per field) — no runtime reflection. Only
     * declared fields present in the body are set (no mass-assignment).
     *
     * @param array                  $arg     the 'form' arg descriptor (class + bind)
     * @param ServerRequestInterface $request
     * @return object the bound form
     */
    private function bindForm($arg, ServerRequestInterface $request)
    {
        $class = $arg['class'];
        $form  = new $class();

        $body = $request->getParsedBody();
        if (is_array($body) && isset($arg['bind'])) {
            foreach ($arg['bind'] as $field => $writer) {
                if (!array_key_exists($field, $body)) {
                    continue;
                }
                if ($writer['type'] === 'setter') {
                    $setter = $writer['name'];
                    $form->$setter($body[$field]);
                } else {
                    $prop = $writer['name'];
                    $form->$prop = $body[$field];
                }
            }
        }

        return $form;
    }

    /**
     * @param ModelAndView $model
     * @return string
     * @throws \RuntimeException When the model has no view name.
     */
    private function renderModel(ModelAndView $model)
    {
        if ($model->getView() === null) {
            throw new \RuntimeException('A returned ModelAndView has no view name (call setView()).');
        }

        return $this->engine()->render(new View($model->getView(), $model->all()));
    }

    /**
     * Build an HtmlResponse for a rendered body, applying any headers the model
     * carried (a template-returning action never holds the response itself).
     *
     * @param string            $body
     * @param ModelAndView|null $model
     * @return HtmlResponse
     */
    private function html($body, $model)
    {
        $status   = ($model instanceof ModelAndView) ? $model->getStatus() : 200;
        $response = new HtmlResponse($body, $status);

        if ($model instanceof ModelAndView) {
            foreach ($model->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * @param ServerRequestInterface|null $request
     * @return void
     */
    public function run($request = null)
    {
        if ($request === null) {
            $request = Request::fromGlobals();
        }

        $this->emit($this->dispatch($request));
    }

    /**
     * @param ResponseInterface $response
     * @return void
     */
    public function emit(ResponseInterface $response)
    {
        if (!headers_sent()) {
            header(
                sprintf(
                    'HTTP/%s %d %s',
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase()
                ),
                true,
                $response->getStatusCode()
            );
            foreach ($response->getHeaders() as $name => $values) {
                // The response is authoritative: the first value of each header
                // REPLACES anything the SAPI already sent (e.g. PHP's session
                // cache-limiter Cache-Control), and extra values append. Set-Cookie
                // is the exception — it's legitimately repeated, never replaced.
                $replace = (strtolower($name) !== 'set-cookie');
                foreach ($values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }
        }

        echo (string) $response->getBody();
    }

    /**
     * Resolve the view engine (memoised):
     *   1. a #[ViewRegister] engine, if one was registered;
     *   2. otherwise the default genai/view engine, backed by an existing
     *      GenAI\View\Renderer bean if present, else one built from setViewPath().
     *
     * @return ViewEngine
     * @throws \RuntimeException
     */
    private function engine()
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        if ($this->engineId !== null) {
            return $this->engine = $this->container->get($this->engineId);
        }

        // Default engine: prefer a Renderer the app already registered, so its
        // template dir / extension are honoured; otherwise build one from viewPath.
        if ($this->container->has('GenAI\\View\\Renderer')) {
            $renderer = $this->container->get('GenAI\\View\\Renderer');
        } elseif ($this->viewPath !== null) {
            $renderer = new Renderer($this->viewPath);
        } else {
            throw new \RuntimeException(
                'Cannot render a View: no #[ViewRegister] engine, no GenAI\\View\\Renderer'
                . ' bean, and no Dispatcher::setViewPath() was called.'
            );
        }

        return $this->engine = new RendererEngine($renderer);
    }

    /**
     * @param mixed $handler
     * @return array array(class, action)
     * @throws \RuntimeException
     */
    private function parse($handler)
    {
        if (is_string($handler) && strpos($handler, '@') !== false) {
            return explode('@', $handler, 2);
        }

        throw new \RuntimeException('Dispatcher expects a "Class@action" route handler.');
    }
}
