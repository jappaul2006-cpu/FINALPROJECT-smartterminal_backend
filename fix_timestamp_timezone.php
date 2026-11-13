<?php
/**
 * One-off: move every machine written timestamp from UTC to Asia/Manila.
 *
 * Why: the Aiven MySQL server runs in UTC, so columns declared with
 * DEFAULT CURRENT_TIMESTAMP stored UTC, while every timestamp written by
 * PHP (scanned_at, queued_at, recorded_at, last_login_at, ...) stored local
 * time. The frontend reads those naive strings as local time, so a
 * just-created announcement showed up as "8 hours ago" and announcements
 * made between 00:00 and 08:00 did not show on the passenger board at all.
 *
 * The Database library now sets the MySQL session time zone to the app
 * time zone, so CURRENT_TIMESTAMP writes local time from now on. This
 * script converts the rows that were already written by MySQL.
 *
 * Only columns written by MySQL are touched. Timestamps that a person
 * typed in (scheduled_departure, spoken_at, ...) keep their meaning.
 *
 * Usage: php fix_timestamp_timezone.php [--dry-run]
 * Safe to re-run: a marker in app_meta stops it from running twice.
 */
$dry_run = in_array('--dry-run', $argv ?? [], true);

$env = [];
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$host    = $env['DB_HOST'] ?? 'localhost';
$port    = $env['DB_PORT'] ?? '3306';
$db      = $env['DB_NAME'] ?? '';
$user    = $env['DB_USER'] ?? '';
$pass    = $env['DB_PASSWORD'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';
$app_tz  = 'Asia/Manila';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Exception $e) {
    echo "DB connect failed: {$e->getMessage()}\n";
    exit(1);
}

// The app time zone, so NOW() and CURRENT_TIMESTAMP speak local time.
$offset = (new DateTimeZone($app_tz))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
$sign = $offset < 0 ? '-' : '+';
$offset = abs($offset);
$hours   = str_pad((string) intdiv($offset, 3600), 2, '0', STR_PAD_LEFT);
$minutes = str_pad((string) intdiv($offset % 3600, 60), 2, '0', STR_PAD_LEFT);
$tz_sql  = "'{$sign}{$hours}:{$minutes}'";
$pdo->exec("SET time_zone = $tz_sql");

echo "App time zone : $app_tz (UTC{$sign}{$hours}:{$minutes})\n";
echo "Mode          : " . ($dry_run ? "dry run (nothing is written)" : "apply") . "\n\n";

// Marker table: keeps the fix idempotent even if it is run twice.
$pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (
    meta_key   VARCHAR(64) NOT NULL PRIMARY KEY,
    meta_value VARCHAR(255) NULL,
    updated_at DATETIME NULL
)");

$marker = 'timestamps.timezone';
$done = $pdo->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ?");
$done->execute([$marker]);
if ($done->fetchColumn()) {
    echo "Already applied (marker: $marker). Nothing to do.\n";
    exit(0);
}

// Tables whose created_at was written by MySQL's CURRENT_TIMESTAMP default.
// The framework's own "migrations" table is left alone: it is metadata and
// the migration runner compares it with NOW().
$tables = [];
foreach ($pdo->query("SELECT table_name AS tbl FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'") as $row) {
    $table = $row['tbl'];
    if ($table === 'migrations') continue;
    $col = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'created_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) continue;
    if (stripos((string) $col['Default'], 'CURRENT_TIMESTAMP') === false) continue;
    $tables[] = $table;
}

echo "Tables to convert (" . count($tables) . "):\n";
foreach ($tables as $table) {
    $before = $pdo->query("SELECT MIN(created_at) mn, MAX(created_at) mx, COUNT(*) c FROM `$table`")->fetch(PDO::FETCH_ASSOC);
    $count = (int) $before['c'];
    if ($count === 0) {
        echo "  - $table: empty, skipped\n";
        continue;
    }
    if (!$dry_run) {
        $pdo->exec("UPDATE `$table` SET created_at = DATE_ADD(created_at, INTERVAL $offset SECOND)");
    }
    $after = $pdo->query("SELECT MIN(created_at) mn, MAX(created_at) mx FROM `$table`")->fetch(PDO::FETCH_ASSOC);
    echo "  - $table: {$before['mn']} .. {$before['mx']}  ->  {$after['mn']} .. {$after['mx']}  ({$count} rows)\n";
}

if (!$dry_run) {
    $stmt = $pdo->prepare("INSERT INTO app_meta (meta_key, meta_value, updated_at) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)");
    $stmt->execute([$marker, $app_tz, date('Y-m-d H:i:s')]);
    echo "\nDone. Marker stored in app_meta ($marker = $app_tz).\n";
} else {
    echo "\nDry run - nothing was written.\n";
}
