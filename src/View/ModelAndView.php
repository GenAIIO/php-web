<?php

namespace GenAI\Web\View;

/**
 * The mutable companion to View. An action declares it as a parameter, fills it
 * with data, then returns a view-name string (Spring-style):
 *
 *   public function show($id, ModelAndView $model)
 *   {
 *       $model->add('user', $repo->find($id));
 *       return 'user/profile';        // rendered with the model's data
 *   }
 *
 * Or carry the view name on the object itself and return it directly:
 *
 *   return $model->setView('user/profile');
 *
 * It can also carry response headers, since a template-returning action never
 * holds the HtmlResponse the dispatcher builds:
 *
 *   $model->header('Cache-Control', 'no-store');
 *
 * The dispatcher injects a fresh instance per request (see the compiled arg
 * plan in web.php), turns it into a View for the engine, and applies its headers
 * to the resulting HtmlResponse.
 *
 * Compatible with PHP 5.3.29.
 */
class ModelAndView
{
    /** @var string|null */
    private $view;

    /** @var array */
    private $data;

    /** @var array name => value (PSR-7 withHeader value: string or string[]) */
    private $headers = array();

    /** @var int HTTP status for the rendered page (e.g. 404 for an error template). */
    private $status = 200;

    /**
     * @param string|null $view
     * @param array       $data
     */
    public function __construct($view = null, $data = array())
    {
        $this->view = $view;
        $this->data = $data;
    }

    /**
     * Set the HTTP status for the rendered page — e.g. render an error template
     * with a non-200 code:  return (new ModelAndView('errors/404'))->status(404);
     *
     * @param int $code
     * @return ModelAndView $this, for chaining.
     */
    public function status($code)
    {
        $this->status = (int) $code;

        return $this;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set a response header, applied to the rendered HtmlResponse.
     *
     * @param string          $name
     * @param string|string[] $value
     * @return ModelAndView $this, for chaining.
     */
    public function header($name, $value)
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @return array name => value
     */
    public function headers()
    {
        return $this->headers;
    }

    /**
     * Add (or overwrite) one model attribute.
     *
     * @param string $key
     * @param mixed  $value
     * @return ModelAndView $this, for chaining.
     */
    public function add($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * @param string $view
     * @return ModelAndView $this, for chaining.
     */
    public function setView($view)
    {
        $this->view = $view;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getView()
    {
        return $this->view;
    }

    /**
     * @return array
     */
    public function all()
    {
        return $this->data;
    }
}
