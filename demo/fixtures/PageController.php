<?php

namespace Demo;

use GenAI\Web\Attribute\Route;
use GenAI\Web\Attribute\Controller;
use GenAI\Web\View\ModelAndView;
use GenAI\Web\View\View;

/**
 * #[Controller] — actions declare only the parameters they need; the dispatcher
 * fills them from the route + request (no runtime reflection). They return a
 * view name, a View, or a ModelAndView. #[Controller] also registers this class
 * as a bean.
 *
 * Runtime class (PHP 5.3-safe); the #[...] lines are comments on 5.3.
 */
#[Controller]
class PageController
{
    // {name} arrives as $name; fill the model, set a response header, return the
    // view name (Spring-style). The dispatcher applies the header to the HtmlResponse.
    #[Route('GET', '/hello/{name}')]
    public function hello($name, ModelAndView $model)
    {
        $model->add('name', $name)->header('X-Page', 'hello');

        return 'hello';
    }

    // No params, no data: just a view name.
    #[Route('GET', '/about')]
    public function about()
    {
        return 'about';
    }

    // Or build it all up front as an immutable View.
    #[Route('GET', '/greet/{name}')]
    public function greet($name)
    {
        return new View('hello', array('name' => strtoupper($name)));
    }
}
