<?php

namespace GenAI\Web;

use GenAI\Session\Session;

/**
 * CSRF token: one per session (stored under 'csrf'). token() goes in the form's
 * hidden field; check() compares a submitted value in constant time. Used by both
 * the controller (to render the field) and CsrfInterceptor (to verify on POST).
 *
 * Depends on genai/session — but genai/web never scans this class, so it only
 * loads when an app actually uses CSRF (the app then has genai/session).
 *
 * Compatible with PHP 5.3.29.
 */
class Csrf
{
    private $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function token()
    {
        $token = $this->session->get('csrf');
        if (!$token) {
            $token = bin2hex($this->randomBytes(16));
            $this->session->set('csrf', $token);
        }
        return $token;
    }

    public function check($token)
    {
        $stored = $this->session->get('csrf');
        return $stored && $this->equals($stored, (string) $token);
    }

    private function equals($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals((string) $a, (string) $b);
        }
        $a = (string) $a;
        $b = (string) $b;
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $r = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $r |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $r === 0;
    }

    private function randomBytes($n)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($n);
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $b = openssl_random_pseudo_bytes($n);
            if ($b !== false) {
                return $b;
            }
        }
        $b = '';
        for ($i = 0; $i < $n; $i++) {
            $b .= chr(mt_rand(0, 255));
        }
        return $b;
    }
}
