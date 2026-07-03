<?php

namespace GenAI\Web;

use GenAI\Boot\AutoConfig;
use GenAI\Http\Request;

/**
 * The HTTP front (implements GenAI\Boot\AutoConfig). genai/web requires genai/boot
 * for the contract — that's the SPI direction, not a cycle, because genai/boot
 * depends on no front. $system is the generated Cache\System; $context a
 * GenAI\Boot\Context (params left untyped to match the interface).
 *
 * Handles any non-CLI invocation: builds the compiled Router + Dispatcher over the
 * shared container, wires the optional serializer + templates, applies prod error
 * handling, and dispatches the request. This is the logic that used to live in
 * Kernel::boot()/run().
 *
 * Declared in composer.json: extra.genai.autoconfig.
 *
 * Runtime class (PHP 5.3-safe).
 */
class WebAutoConfig implements AutoConfig
{
    /** Web is the default front (priority 0); CLI verbs sit above, CLI catch-all below. */
    public function priority()
    {
        return 0;
    }

    public function required()
    {
        return array('Cache\\Router', 'Cache\\Web');
    }

    public function supports($context)
    {
        return !$context->isCli();
    }

    public function run($container, $system, $context)
    {
        $router     = new \Cache\Router();
        $dispatcher = new \Cache\Web($router, $container);   // Dispatcher subclass, metadata baked

        // genai/dto is a suggest: the serializer class is baked into Cache\System
        // (null when not installed), so no class_exists() probe.
        $serializerClass = $system->getSerializerClass();
        if ($serializerClass !== null) {
            $dispatcher->setSerializer(new $serializerClass());
        }

        $templates = $system->getTemplates();
        if (is_dir($templates)) {
            $dispatcher->setViewPath($templates);
        }

        // prod -> friendly error pages; dev (default) -> let traces surface. Driven
        // by [app] env via the AppProperty bean (resolved by id; not type-hinted, to
        // avoid referencing GenAI\Boot here).
        $app    = $container->get($system->getAppPropertyId());
        $handle = $app->isProd();
        $dispatcher->setHandleExceptions($handle);
        if ($handle) {
            set_exception_handler(function ($e) use ($dispatcher) {
                // Stamp the correlation id even on a last-resort fatal (decoupled:
                // trace_id() if genai/trace is present, else boot's baseline slot).
                $tid = function_exists('trace_id') ? trace_id() : '';
                if ($tid === '' && isset($_SERVER['GENAI_TRACE_BASELINE'])) {
                    $tid = $_SERVER['GENAI_TRACE_BASELINE'];
                }
                error_log(($tid !== '' ? '[' . $tid . '] ' : '') . (string) $e);
                $dispatcher->emit($dispatcher->defaultErrorPage(500, 'Something went wrong'));
            });
        }

        // run() options win; AppProperty fills uriKey/basePath; Request adds defaults.
        $options = $context->options();
        if (!isset($options['uriKey'])) {
            $options['uriKey'] = $app->getUriKey();
        }
        if (!isset($options['basePath'])) {
            $options['basePath'] = $app->getBasePath();
        }

        $dispatcher->run(Request::fromGlobals($options));

        return 0;
    }
}
