<?php
/**
 * One-off: run performance indexes against Aiven MySQL.
 * Usage: php add_indexes.php
 * Safe to re-run (duplicate-key errors are ignored).
 */
$env = [];
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';
$db   = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Exception $e) {
    echo "DB connect failed: {$e->getMessage()}\n";
    exit(1);
}

$stmts = [
    "CREATE INDEX idx_trips_status_sched ON trips (status, scheduled_departure)",
    "CREATE INDEX idx_trips_vehicle ON trips (vehicle_id)",
    "CREATE INDEX idx_trips_driver ON trips (driver_id)",
    "CREATE INDEX idx_trips_route ON trips (route_id)",
    "CREATE INDEX idx_trips_actual ON trips (actual_departure)",
    "CREATE INDEX idx_vehicles_status ON vehicles (status)",
    "CREATE INDEX idx_drivers_status ON drivers (status)",
    "CREATE INDEX idx_drivers_user ON drivers (user_id)",
    "CREATE INDEX idx_dq_status_pos ON dispatch_queue (status, queue_position)",
    "CREATE INDEX idx_dq_trip ON dispatch_queue (trip_id)",
    "CREATE INDEX idx_dq_driver ON dispatch_queue (driver_id)",
    "CREATE INDEX idx_dq_vehicle ON dispatch_queue (vehicle_id)",
    "CREATE INDEX idx_tickets_trip ON tickets (trip_id)",
    "CREATE INDEX idx_tickets_qr ON tickets (qr_code)",
    "CREATE INDEX idx_tickets_status ON tickets (status)",
    "CREATE INDEX idx_pc_trip_recorded ON passenger_counts (trip_id, recorded_at)",
    "CREATE INDEX idx_overrides_trip ON schedule_overrides (trip_id)",
    "CREATE INDEX idx_overrides_created ON schedule_overrides (created_at)",
    "CREATE INDEX idx_ann_created ON announcements (created_at)",
    "CREATE INDEX idx_users_role_active ON users (role, is_active)",
];

foreach ($stmts as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "SKIP (already exists): $sql\n";
        } else {
            echo "FAIL: $sql -- {$e->getMessage()}\n";
        }
    }
}
echo "Done.\n";
