<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * OneSignal Web Push helper
 * ------------------------------------------------------------------
 * Broadcasts a notification to every OneSignal subscriber.
 *
 * The passenger display board is public and anonymous - there is no
 * passenger account and no OneSignal.login() call - so notifications are
 * broadcast to the built-in "Subscribed Users" segment instead of being
 * addressed to a single user.
 *
 * The REST API key is read from the backend .env only. It must never be
 * shipped to the frontend.
 *
 * Usage:
 *     $this->call->helper('onesignal');
 *     sendAnnouncementPush('Terminal announcement', 'Van to Naujan is delayed.');
 */

if (!function_exists('onesignal_env'))
{
	/**
	 * Reads a value from the environment (loaded from the backend .env).
	 *
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	function onesignal_env($key, $default = '')
	{
		$value = getenv($key);
		if ($value === false || $value === '') {
			$value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
		}
		return (string) $value;
	}
}

if (!function_exists('onesignal_truncate'))
{
	/**
	 * Shortens a string for a notification body (multibyte safe).
	 *
	 * @param string $text
	 * @param int $limit
	 * @return string
	 */
	function onesignal_truncate($text, $limit)
	{
		$text = trim((string) $text);
		if ($text === '') {
			return '';
		}
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($text, 'UTF-8') <= $limit) {
				return $text;
			}
			return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
		}
		if (strlen($text) <= $limit) {
			return $text;
		}
		return rtrim(substr($text, 0, $limit - 1)) . '…';
	}
}

if (!function_exists('onesignal_ca_bundle'))
{
	/**
	 * Finds a CA bundle so HTTPS calls can be verified.
	 *
	 * Some local PHP installs (WAMP on Windows for example) ship without a
	 * CA bundle, which makes every cURL call fail with
	 * "unable to get local issuer certificate". The bundled
	 * resources/cacert.pem is used as the fallback.
	 *
	 * @return string Empty string when no bundle could be found
	 */
	function onesignal_ca_bundle()
	{
		static $bundle = null;
		if ($bundle !== null) {
			return $bundle;
		}

		$candidates = [];

		$from_env = onesignal_env('ONESIGNAL_CA_BUNDLE');
		if ($from_env !== '') {
			$candidates[] = $from_env;
		}

		$ini_cainfo = ini_get('curl.cainfo');
		if (!empty($ini_cainfo)) {
			$candidates[] = $ini_cainfo;
		}

		$ini_cafile = ini_get('openssl.cafile');
		if (!empty($ini_cafile)) {
			$candidates[] = $ini_cafile;
		}

		// Bundled with the application.
		$candidates[] = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'cacert.pem';

		// Common system locations.
		$candidates[] = '/etc/ssl/certs/ca-certificates.crt';
		$candidates[] = '/etc/pki/tls/cacert.pem';
		$candidates[] = '/etc/ssl/cert.pem';
		$candidates[] = 'C:\\wamp64\\bin\\php\\php8.4.0\\extras\\ssl\\cacert.pem';

		foreach ($candidates as $candidate) {
			if ($candidate && is_file($candidate) && is_readable($candidate)) {
				$bundle = $candidate;
				return $bundle;
			}
		}

		$bundle = '';
		return $bundle;
	}
}

if (!function_exists('onesignal_api'))
{
	/**
	 * Performs a request against the OneSignal REST API.
	 *
	 * @param string $method  GET or POST
	 * @param string $url
	 * @param array|null $payload
	 * @return array [int $status, string $body]
	 */
	function onesignal_api($method, $url, $payload = null)
	{
		$api_key = onesignal_env('ONESIGNAL_REST_API_KEY');

		$ch = curl_init($url);
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Key ' . $api_key,
			],
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 15,
		];
		if ($payload !== null) {
			$options[CURLOPT_POSTFIELDS] = json_encode($payload);
		}

		$ca_bundle = onesignal_ca_bundle();
		if ($ca_bundle !== '') {
			$options[CURLOPT_CAINFO] = $ca_bundle;
		} else {
			// No CA bundle anywhere on this machine: keep the request going
			// but make the weakened verification obvious in the log.
			error_log('[OneSignal] no CA bundle found - TLS peer verification is disabled for the push request');
			$options[CURLOPT_SSL_VERIFYPEER] = false;
			$options[CURLOPT_SSL_VERIFYHOST] = 0;
		}

		curl_setopt_array($ch, $options);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($body === false) {
			error_log('[OneSignal] request failed: ' . $error);
			return [0, ''];
		}

		return [$status, (string) $body];
	}
}

if (!function_exists('onesignal_player_ids'))
{
	/**
	 * Returns the ids of the players (browser subscriptions) of the app.
	 *
	 * Players whose push token OneSignal already invalidated (an old
	 * browser subscription that was replaced) are skipped - sending to them
	 * only makes OneSignal answer "invalid_player_ids".
	 *
	 * @param int $limit
	 * @return array
	 */
	function onesignal_player_ids($limit = 500)
	{
		$app_id = onesignal_env('ONESIGNAL_APP_ID');
		if ($app_id === '') {
			return [];
		}

		list($status, $body) = onesignal_api(
			'GET',
			'https://api.onesignal.com/api/v1/players?app_id=' . rawurlencode($app_id) . '&limit=' . (int) $limit
		);

		if ($status < 200 || $status >= 300) {
			return [];
		}

		$data = json_decode($body, true);
		$ids = [];
		foreach (($data['players'] ?? []) as $player) {
			if (empty($player['id'])) continue;
			if (!empty($player['invalid_identifier'])) continue;
			if (empty($player['identifier'])) continue;
			$ids[] = $player['id'];
		}
		return $ids;
	}
}

if (!function_exists('onesignal_invalid_player_ids'))
{
	/**
	 * Picks the player ids OneSignal rejected out of a response body.
	 *
	 * @param string $body
	 * @return array
	 */
	function onesignal_invalid_player_ids($body)
	{
		$decoded = json_decode((string) $body, true);
		$errors = $decoded['errors'] ?? null;
		if (!is_array($errors)) {
			return [];
		}
		$invalid = [];
		foreach ($errors as $key => $value) {
			if (is_string($key) && strpos($key, 'invalid_player_ids') !== false && is_array($value)) {
				foreach ($value as $id) {
					$invalid[] = (string) $id;
				}
			}
		}
		return $invalid;
	}
}

if (!function_exists('sendAnnouncementPush'))
{
	/**
	 * Sends an announcement push to all subscribed passengers.
	 *
	 * Never throws and never blocks the caller: when OneSignal is
	 * unreachable the failure is logged and false is returned, so creating
	 * an announcement still succeeds.
	 *
	 * @param string $title    Short headline, e.g. "Terminal announcement"
	 * @param string $message  Announcement body
	 * @return bool True when OneSignal accepted the notification
	 */
	function sendAnnouncementPush($title, $message)
	{
		$app_id     = onesignal_env('ONESIGNAL_APP_ID');
		$api_key    = onesignal_env('ONESIGNAL_REST_API_KEY');
		$public_url = rtrim(onesignal_env('APP_PUBLIC_URL'), '/');

		if ($app_id === '' || $api_key === '') {
			error_log('[OneSignal] push skipped - ONESIGNAL_APP_ID / ONESIGNAL_REST_API_KEY is not configured');
			return false;
		}

		$title   = onesignal_truncate($title !== '' ? $title : 'Terminal announcement', 60);
		$message = onesignal_truncate($message, 180);

		if ($title === '' && $message === '') {
			return false;
		}

		$payload = [
			'app_id'            => $app_id,
			'included_segments' => ['Subscribed Users'],
			'headings'          => ['en' => $title !== '' ? $title : 'Terminal announcement'],
			'contents'          => ['en' => $message !== '' ? $message : 'Please check the departure board.'],
			'url'               => $public_url . '/passenger-display',
		];

		if (!function_exists('curl_init')) {
			error_log('[OneSignal] push skipped - the cURL extension is not enabled');
			return false;
		}

		list($status, $body) = onesignal_api('POST', 'https://api.onesignal.com/notifications', $payload);

		// OneSignal accepted the request but the "Subscribed Users" segment
		// is empty (the browser subscriptions exist, but the players were not
		// flagged as subscribed). Fall back to the known players so the
		// announcement still reaches the passengers.
		if (strpos($body, 'not subscribed') !== false) {
			$player_ids = onesignal_player_ids();
			if (!empty($player_ids)) {
				error_log('[OneSignal] "Subscribed Users" is empty - sending to ' . count($player_ids) . ' known player(s) instead');
				$fallback = $payload;
				unset($fallback['included_segments']);
				$fallback['include_player_ids'] = $player_ids;
				list($status, $body) = onesignal_api('POST', 'https://api.onesignal.com/notifications', $fallback);

				// Drop the players OneSignal reports as invalid and try again.
				$invalid = onesignal_invalid_player_ids($body);
				if (!empty($invalid)) {
					$valid = array_values(array_diff($player_ids, $invalid));
					error_log('[OneSignal] ' . count($invalid) . ' player(s) rejected as invalid, ' . count($valid) . ' left');
					if (!empty($valid)) {
						$fallback['include_player_ids'] = $valid;
						list($status, $body) = onesignal_api('POST', 'https://api.onesignal.com/notifications', $fallback);
					}
				}
			}
		}

		if ($status < 200 || $status >= 300) {
			error_log(sprintf('[OneSignal] push failed (http %d): %s', $status, $body));
			return false;
		}

		// OneSignal answers 200 even when it could not deliver (for example
		// when nobody is subscribed yet), so surface its message in the log.
		$decoded = json_decode((string) $body, true);
		$errors = [];
		foreach (($decoded['errors'] ?? []) as $err) {
			$errors[] = is_array($err) ? json_encode($err) : (string) $err;
		}
		if ($errors) {
			error_log('[OneSignal] announcement push accepted with warnings: ' . implode('; ', $errors));
			error_log('[OneSignal] response: ' . (string) $body);
			return true;
		}

		error_log('[OneSignal] announcement push sent: ' . (string) $body);
		return true;
	}
}
