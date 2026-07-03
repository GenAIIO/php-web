<?php

namespace GenAI\Web\Attribute;

use GenAI\Di\Component;

/**
 * Base for the web class-stereotypes (#[Controller], #[RestController],
 * #[ViewRegister]). It extends GenAI\Di\Component, so the di ComponentProcessor
 * registers any annotated class as an autowired container bean — controllers do
 * not need #[Service]. The WebProcessor (which listens for this base) records
 * the web-specific metadata (which are REST, which is the view engine).
 *
 * Build-time only (PHP 8); a comment on the PHP 5.3 runtime.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class WebComponent extends Component
{
}
