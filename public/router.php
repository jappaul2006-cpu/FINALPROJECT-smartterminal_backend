<?php
/**
 * Router for PHP built-in web server (php -S).
 *
 * LavaLust relies on Apache .htaccess rewriting to route everything
 * through public/index.php. The PHP built-in server has no .htaccess,
 * so we run it like:
 *
 *   php -S localhost:8000 -t public public/router.php
 *
 * (or simply: php -S localhost:8000 public/router.php)
 *
 * Existing files (favicon.ico, etc.) are served directly; everything
 * else is handed to the LavaLust front controller.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// Serve real files directly (static assets only, not PHP files).
if (is_file($file) && !str_ends_with(strtolower($path), '.php')) {
    return false;
}

require __DIR__ . '/index.php';
