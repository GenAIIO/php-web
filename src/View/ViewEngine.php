<?php

namespace GenAI\Web\View;

/**
 * Renders a View to a string. The app provides an implementation (wrapping any
 * template engine it likes) and marks it with #[ViewRegister]; the dispatcher
 * resolves it from the container and uses it for Controller View results.
 *
 *   class PhpView implements ViewEngine {
 *       public function render(View $view) { ...return string...; }
 *   }
 *
 * Compatible with PHP 5.3.29 (no return type — that is PHP 7+).
 */
interface ViewEngine
{
    /**
     * @param View $view
     * @return string
     */
    public function render(View $view);
}
