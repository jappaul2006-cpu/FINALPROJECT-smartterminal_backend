<?php
/**
 * Sends a test push to the OneSignal "Subscribed Users" segment and prints
 * what OneSignal answered - handy for checking the REST API key and seeing
 * whether anybody is subscribed yet.
 *
 * It uses the same helper as the announcement endpoint, so what you see
 * here is exactly what happens when staff publish an announcement.
 *
 * Usage: php send_test_push.php ["your message"]
 */
$env = [];
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$app_id  = $env['ONESIGNAL_APP_ID'] ?? '';
$api_key = $env['ONESIGNAL_REST_API_KEY'] ?? '';

if ($app_id === '' || $api_key === '') {
    echo "ONESIGNAL_APP_ID / ONESIGNAL_REST_API_KEY missing from .env\n";
    exit(1);
}

// Load the helper the same way the framework does.
if (!defined('PREVENT_DIRECT_ACCESS')) {
    define('PREVENT_DIRECT_ACCESS', true);
}
putenv('ONESIGNAL_APP_ID=' . $app_id);
putenv('ONESIGNAL_REST_API_KEY=' . $api_key);
putenv('APP_PUBLIC_URL=' . ($env['APP_PUBLIC_URL'] ?? ''));
require_once __DIR__ . '/app/helpers/onesignal_helper.php';

$message = $argv[1] ?? 'Test notification from the SmartTerminal backend.';

echo "== REST API key check ==\n";
list($s, $b) = onesignal_api('GET', 'https://api.onesignal.com/api/v1/apps/' . $app_id);
echo "GET /api/v1/apps/{id} -> HTTP $s\n";
if ($s >= 200 && $s < 300) {
    $app = json_decode($b, true);
    echo "app name   : " . ($app['name'] ?? '?') . "\n";
    echo "players    : " . ($app['players'] ?? '?') . "\n";
    echo "messageable: " . ($app['messageable_players'] ?? '?') . "\n";
} else {
    echo substr($b, 0, 300) . "\n";
}

echo "\n== subscribers ==\n";
$ids = onesignal_player_ids();
echo "known players (ids) : " . count($ids) . "\n";
if (count($ids) === 0) {
    echo "=> No player yet. Open the passenger board, click \"Notify me of announcements\"\n";
    echo "   and allow the notification prompt.\n";
}

echo "\n== sending test notification (same helper as the announcements) ==\n";
$ok = sendAnnouncementPush('Yellow Mega Van · Test', $message);
echo "result     : " . ($ok ? 'sent' : 'failed') . "\n";
echo "check C:\\wamp64\\logs\\php_error.log for the [OneSignal] line with the API answer.\n";
