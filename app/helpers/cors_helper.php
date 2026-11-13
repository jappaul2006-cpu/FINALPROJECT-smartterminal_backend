<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * CORS helper
 * ------------------------------------------------------------------
 * Resolves which browser origin is allowed to call the API.
 *
 * The origin list comes from the CORS_ORIGIN environment variable
 * (backend .env) and may hold several comma separated origins:
 *
 *   CORS_ORIGIN=http://localhost:5173,http://127.0.0.1:5173
 *
 * The Access-Control-Allow-Origin header can only carry ONE origin, so
 * the origin of the incoming request is echoed back when it is on the
 * list. That way the Vite dev server (localhost:5173) and a direct
 * call from a tool such as Postman both work without touching the
 * configuration again.
 *
 * Usage:
 *   $this->call->helper('cors');
 *   header('Access-Control-Allow-Origin: ' . smartterminal_cors_origin());
 */

if (!function_exists('smartterminal_cors_origins'))
{
	/**
	 * Reads the allowed origins from the environment.
	 *
	 * @return array<int,string>
	 */
	function smartterminal_cors_origins()
	{
		$raw = getenv('CORS_ORIGIN');
		if ($raw === false || $raw === '') {
			$raw = $_ENV['CORS_ORIGIN'] ?? $_SERVER['CORS_ORIGIN'] ?? '';
		}

		$origins = array();
		foreach (explode(',', (string) $raw) as $origin) {
			$origin = trim($origin);
			if ($origin !== '') {
				$origins[] = $origin;
			}
		}

		// Local development default: the Vite dev server.
		if (empty($origins)) {
			$origins = array('http://localhost:5173', 'http://127.0.0.1:5173');
		}

		return $origins;
	}
}

if (!function_exists('smartterminal_cors_origin'))
{
	/**
	 * Returns the value for the Access-Control-Allow-Origin header.
	 *
	 * @return string
	 */
	function smartterminal_cors_origin()
	{
		$origins = smartterminal_cors_origins();

		// "CORS_ORIGIN=*" opens the API to every origin (development only).
		if (in_array('*', $origins, true)) {
			return '*';
		}

		$requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

		if ($requestOrigin !== '') {
			foreach ($origins as $allowed) {
				if (strcasecmp($allowed, $requestOrigin) === 0) {
					return $requestOrigin;
				}
			}
		}

		// Unknown origin (or no origin at all): fall back to the first
		// configured one so the browser blocks the response instead of
		// the request hanging forever.
		return $origins[0];
	}
}
