<?php

namespace GenAI\Web\View;

/**
 * What a controller returns to say "render this template with this data" —
 * instead of rendering itself. The dispatcher hands it to the ViewEngine. So a
 * controller never needs to inject a renderer.
 *
 *   return new View('user/profile', array('user' => $user));
 *
 * Compatible with PHP 5.3.29.
 */
class View
{
    /** @var string */
    private $template;

    /** @var array */
    private $data;

    /**
     * @param string $template
     * @param array  $data
     */
    public function __construct($template, $data = array())
    {
        $this->template = $template;
        $this->data     = $data;
    }

    /**
     * @return string
     */
    public function template()
    {
        return $this->template;
    }

    /**
     * @return array
     */
    public function data()
    {
        return $this->data;
    }
}
