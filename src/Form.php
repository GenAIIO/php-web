<?php

namespace GenAI\Web;

/**
 * Marker for a form/DTO that the dispatcher binds from the request body and
 * injects into an action parameter (Spring's @ModelAttribute, option 2):
 *
 *   #[PostMapping('/signup')]
 *   public function signup(SignupForm $form, ModelAndView $model) { ... }  // $form is bound
 *
 * WebProcessor compiles a bind plan for each form param (field -> public property
 * or setXxx() setter), so the dispatcher binds it reflection-free — and a private
 * field's setter is where the form can normalize (trim, lowercase, ...).
 *
 * This only does binding; pair it with genai/validation to check the bound form.
 * You can still bind by hand instead (Validator::bind() — option 1); both work.
 *
 * Compatible with PHP 5.3.29.
 */
interface Form
{
}
