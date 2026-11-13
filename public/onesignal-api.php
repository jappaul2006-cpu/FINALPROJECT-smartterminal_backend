<?php
/**
 * First-party proxy for the OneSignal API (production).
 *
 * The OneSignal SDK hardcodes https://api.onesignal.com/ and loads the app
 * configuration with a JSONP script request. Brave Shields, uBlock and
 * similar tools block that domain, which makes OneSignal.init() wait for a
 * request that never arrives - push notifications then never work.
 *
 * This endpoint forwards the same calls from the server so the browser only
 * ever talks to our own origin:
 *
 *   /onesignal-api/sync/<appId>/web -> https://api.onesignal.com/sync/...
 *   /onesignal-api/v1/*             -> https://onesignal.com/api/v1/...
 *
 * The frontend only uses it when it answers on /onesignal-api/__health,
 * so removing this file is safe.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/onesignal-api' === substr($path, 0, 14) ? substr($path, 14) : $path;
$query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '';

// Health check: tells the frontend the proxy is available.
if (str_starts_with($path, '/__health')) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

$target = str_starts_with($path, '/v1/')
    ? 'https://onesignal.com/api/v1' . substr($path, 3)
    : 'https://api.onesignal.com' . $path;

if ($query !== '') {
    $target .= '?' . $query;
}

$headers = [];
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}
if (!empty($_SERVER['HTTP_ONESIGNAL_SUBSCRIPTION_ID'])) {
    $headers[] = 'OneSignal-Subscription-Id: ' . $_SERVER['HTTP_ONESIGNAL_SUBSCRIPTION_ID'];
}

$body = file_get_contents('php://input');

// The OneSignal web SDK registers the browser subscription without the
// "enabled" flag, so OneSignal keeps the player out of every segment
// ("Subscribed Users") and broadcasts never reach it. Flag every
// subscription as enabled/subscribed on the way through.
if ($body !== '' && $body !== false) {
    $payload = json_decode($body, true);
    if (is_array($payload)) {
        $changed = false;
        $fix = function (&$subscription) use (&$changed) {
            if (!is_array($subscription) || empty($subscription['token'])) return;
            if (!isset($subscription['enabled'])) { $subscription['enabled'] = true; $changed = true; }
            if (!isset($subscription['notification_types'])) { $subscription['notification_types'] = 1; $changed = true; }
            if (!isset($subscription['opted_in'])) { $subscription['opted_in'] = true; $changed = true; }
        };
        $walk = function (&$node) use (&$walk, &$fix, &$changed) {
            if (!is_array($node)) return;
            if (!empty($node['token']) && !empty($node['type'])) { $fix($node); }
            foreach ($node as &$value) {
                if (is_array($value)) { $walk($value); }
            }
            unset($value);
        };
        $walk($payload);
        if ($changed) {
            $body = json_encode($payload);
        }
    }
}

$ch = curl_init($target);
$options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_FOLLOWLOCATION => true,
];
if ($body !== '' && $body !== false) {
    $options[CURLOPT_POSTFIELDS] = $body;
}

$ca_bundle = dirname(__DIR__) . '/resources/cacert.pem';
if (is_file($ca_bundle)) {
    $options[CURLOPT_CAINFO] = $ca_bundle;
}

curl_setopt_array($ch, $options);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'OneSignal proxy failed: ' . $error]);
    exit;
}

http_response_code($status);
if ($content_type) {
    header('Content-Type: ' . $content_type);
}
echo $response;
