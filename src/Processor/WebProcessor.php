<?php

namespace GenAI\Web\Processor;

use GenAI\Attribute\AttributeProcessor;
use GenAI\Attribute\Context;
use GenAI\Web\Attribute\Controller;
use GenAI\Web\Attribute\Intercept;
use GenAI\Web\Attribute\RestController;
use GenAI\Web\Attribute\Route;
use GenAI\Web\Attribute\ViewRegister;
use GenAI\Web\Attribute\WebComponent;
use GenAI\Web\View\ModelAndView;

/**
 * Records the web metadata the runtime Dispatcher needs:
 *   - which controllers are REST (their actions get JSON-wrapped),
 *   - which class is the #[ViewRegister] engine (if any),
 *   - an argument plan per action, so the dispatcher can pass each action its
 *     declared parameters (route value, the request, a ModelAndView, a service)
 *     WITHOUT any runtime reflection.
 *
 * It does NOT register beans — the di ComponentProcessor does that, since the web
 * attributes extend GenAI\Di\Component.
 *
 * Listens for the WebComponent base, so one processor sees #[Controller],
 * #[RestController] and #[ViewRegister] (via IS_INSTANCEOF). Dumps cache/web.php:
 *
 *   return array(
 *       'rest'   => array('App\\Api\\UserApi' => true, ...),
 *       'engine'       => 'App\\View\\PhpView', // null when no #[ViewRegister] -> default engine
 *       'interceptors' => array(               // #[Intercept], ordered (lower = outermost)
 *           array('id' => 'App\\AuthInterceptor', 'path' => null, 'exclude' => array('/login', '/')),
 *       ),
 *       'args'   => array(
 *           'App\\PageController@show' => array(
 *               array('source' => 'attribute', 'name' => 'id'),   // route value {id}
 *               array('source' => 'model'),                       // a fresh ModelAndView
 *               array('source' => 'request'),                     // the ServerRequest
 *               array('source' => 'service', 'id' => 'App\\Repo'), // container->get(...)
 *           ),
 *       ),
 *   );
 *
 * At most one #[ViewRegister] is allowed (a second one is a build error). Omit it
 * to fall back to the default genai/view engine.
 *
 * Build-time only (PHP 8). The dumped file is PHP 5.3-safe.
 */
class WebProcessor implements AttributeProcessor
{
    /** @var array<string, bool> */
    private array $rest = [];

    /** @var string|null */
    private ?string $engine = null;

    /** @var array<string, array> handler "Class@action" => list of arg descriptors */
    private array $args = [];

    /** @var array<int, array{id:string,order:int,path:?string}> */
    private array $interceptors = [];

    public function getAttributeClass(): string
    {
        return WebComponent::class; // matches Controller / RestController / ViewRegister / Intercept
    }

    public function process(object $attribute, \Reflector $target): void
    {
        /** @var \ReflectionClass $target */
        if ($attribute instanceof RestController) {
            $this->rest[$target->getName()] = true;
        }
        if ($attribute instanceof ViewRegister) {
            if ($this->engine !== null) {
                throw new \LogicException(
                    'Only one #[ViewRegister] view engine is allowed, but found both "'
                    . $this->engine . '" and "' . $target->getName()
                    . '". Remove one (omit #[ViewRegister] entirely to use the default genai/view engine).'
                );
            }
            $this->engine = $target->getName();

            return; // an engine isn't a controller; it has no actions to plan
        }
        if ($attribute instanceof Intercept) {
            $this->interceptors[] = array(
                'id'      => $target->getName(),
                'order'   => $attribute->order,
                'path'    => $attribute->path,
                'exclude' => $attribute->exclude,
            );

            return; // an interceptor isn't a controller
        }

        // #[Controller] / #[RestController]: plan the arguments of each action.
        if ($attribute instanceof Controller || $attribute instanceof RestController) {
            $this->planActions($target);
        }
    }

    /**
     * Build an arg plan for every #[Route] action declared on the controller.
     *
     * @param \ReflectionClass $class
     * @return void
     */
    private function planActions(\ReflectionClass $class)
    {
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            // IS_INSTANCEOF so the #[Route] shortcuts (#[GetMapping] etc.) count too.
            if (count($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF)) === 0) {
                continue; // only routable actions get a plan
            }

            $plan = array();
            foreach ($method->getParameters() as $param) {
                $plan[] = $this->describeParameter($param);
            }
            $this->args[$class->getName() . '@' . $method->getName()] = $plan;
        }
    }

    /**
     * Decide, at build time, how the runtime should supply one action parameter:
     *   - a request type        -> the ServerRequest
     *   - ModelAndView          -> a fresh ModelAndView
     *   - a GenAI\Web\Form       -> a new instance bound from the request body
     *                               (its compiled bind plan rides along)
     *   - any other class type   -> a container service (by class id)
     *   - anything else          -> a request attribute by name (route values land
     *                               here, since the dispatcher sets them as attrs)
     *
     * @param \ReflectionParameter $param
     * @return array
     */
    private function describeParameter(\ReflectionParameter $param)
    {
        $type = $param->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            if (is_a($name, 'Psr\\Http\\Message\\MessageInterface', true)) {
                return array('source' => 'request');
            }
            if ($name === ModelAndView::class || is_a($name, ModelAndView::class, true)) {
                return array('source' => 'model');
            }
            if (is_a($name, 'GenAI\\Web\\Form', true)) {
                return array('source' => 'form', 'class' => $name, 'bind' => $this->bindPlan(new \ReflectionClass($name)));
            }

            return array('source' => 'service', 'id' => $name);
        }

        return array('source' => 'attribute', 'name' => $param->getName());
    }

    /**
     * Compile how to write each of a form's fields from the request body: a public
     * property is set directly; a private/protected one via its public setXxx()
     * (where the form can normalize). Fields without a writer are skipped. The
     * dispatcher applies this plan with no runtime reflection.
     *
     * @param \ReflectionClass $class
     * @return array field => array('type' => 'prop'|'setter', 'name' => ...)
     */
    private function bindPlan(\ReflectionClass $class)
    {
        $plan = array();
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $field = $property->getName();
            if ($property->isPublic()) {
                $plan[$field] = array('type' => 'prop', 'name' => $field);
                continue;
            }
            $setter = 'set' . ucfirst($field);
            if ($class->hasMethod($setter) && $class->getMethod($setter)->isPublic()) {
                $plan[$field] = array('type' => 'setter', 'name' => $setter);
            }
        }

        return $plan;
    }

    public function compile(Context $context): void
    {
        // Sort by order (lower = outermost). Build runs on PHP 8, where usort is
        // stable, so equal-order interceptors keep their declaration order.
        $ordered = $this->interceptors;
        usort($ordered, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        $interceptors = array();
        foreach ($ordered as $entry) {
            $interceptors[] = array(
                'id'      => $entry['id'],
                'path'    => $entry['path'],
                'exclude' => $entry['exclude'],
            );
        }

        $data = array(
            'rest'         => $this->rest,
            'engine'       => $this->engine,
            'args'         => $this->args,
            'interceptors' => $interceptors,
        );

        $source = "<?php\n\n"
            . "namespace Cache;\n\n"
            . "// Generated by GenAI\\Web\\Processor\\WebProcessor - do not edit by hand.\n"
            . "// The compiled dispatcher: new \\Cache\\Web(\$router, \$container) carries the metadata.\n\n"
            . "class Web extends \\GenAI\\Web\\Dispatcher\n"
            . "{\n"
            . "    public function __construct(\\GenAI\\Routing\\Router \$router, \\GenAI\\Container\\Container \$container)\n"
            . "    {\n"
            . "        parent::__construct(\$router, \$container);\n"
            . '        $this->setMetadata(' . var_export($data, true) . ");\n"
            . "    }\n"
            . "}\n";

        $path  = $context->output('Web.php');
        $bytes = @file_put_contents($path, $source);
        if ($bytes === false) {
            throw new \RuntimeException('Could not write web metadata to "' . $path . '".');
        }
    }
}
