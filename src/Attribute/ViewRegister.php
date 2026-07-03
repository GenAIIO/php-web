<?php

namespace GenAI\Web\Attribute;

/**
 * Marks the app's ViewEngine implementation as the one to use. Registered as a
 * container bean; the dispatcher resolves it to render Controller View results.
 * The app can plug in any engine by writing a ViewEngine and tagging it.
 * Build-time only (PHP 8).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ViewRegister extends WebComponent
{
}
