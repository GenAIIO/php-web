<?php

namespace GenAI\Web\View;

use GenAI\View\Renderer;

/**
 * The default ViewEngine: a thin adapter that renders a View through php-view's
 * Renderer. The dispatcher uses this automatically when no #[ViewRegister] class
 * was found, so plain HTML controllers work with zero registration — the app
 * only has to say where its templates live (Dispatcher::setViewPath, or by
 * registering a GenAI\View\Renderer bean).
 *
 * To use a different engine entirely (Twig, Blade, ...), write your own
 * ViewEngine and mark it #[ViewRegister]; it replaces this one.
 *
 * Runtime class — compatible with PHP 5.3.29.
 */
class RendererEngine implements ViewEngine
{
    /** @var Renderer */
    private $renderer;

    public function __construct(Renderer $renderer)
    {
        $this->renderer = $renderer;
    }

    public function render(View $view)
    {
        return $this->renderer->render($view->template(), $view->data());
    }
}
