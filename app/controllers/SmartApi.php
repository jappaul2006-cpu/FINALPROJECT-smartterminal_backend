<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartApi extends Controller {

    /**
     * Called before every controller action.
     * Sets CORS headers so the Vue frontend can call this API.
     * Also advertises short-lived HTTP caching for GET lists so
     * browsers/proxies can revalidate instead of re-downloading.
     */
    public function before_action() {
        $response = load_class('response', 'kernel');
        $this->call->helper('cors');
        $corsOrigin = function_exists('smartterminal_cors_origin')
            ? smartterminal_cors_origin()
            : (getenv('CORS_ORIGIN') ?: 'http://localhost:5173');
        $response->add_header('Access-Control-Allow-Origin', $corsOrigin);
        $response->add_header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->add_header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $response->add_header('Cache-Control', 'private, max-age=15, stale-while-revalidate=30');
            $response->add_header('Vary', 'Authorization, Accept-Encoding');
        }

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $response->send_no_content();
            exit;
        }

        // Serve hot GET lists straight from the file cache BEFORE
        // opening the (remote Aiven) DB connection. Opening the PDO
        // costs ~500ms per request (TLS + TCP to the cloud); a cache
        // hit should pay only PHP + a file read (~50ms).
        $earlyMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($earlyMethod === 'GET') {
            if ($this->try_early_cache_hit($response)) {
                exit;
            }
        }

        // Load database library so models can access $this->db
        // (deferred until after the early cache check above).
        $this->call->library('database');

        // JWT gate for writes (uses .env JWT_SECRET via LavaLust Api helper).
        // GETs stay public for the TV passenger board; the Vue router enforces login in UI.
        // Writes require a valid Bearer access token; dispatcher-only writes are role-checked.
        $this->gate_writes();

        // Invalidate server-side GET caches synchronously BEFORE the write,
        // plus again in shutdown as backup. Clearing before the write is
        // reliable (no dependence on shutdown timing) and the next GET
        // repopulates with fresh data. Failed writes just mean a colder
        // next read - harmless.
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $pos = strpos($uri, '/api/');
            if ($pos !== false) $uri = substr($uri, $pos);
            // Auth endpoints don't affect list caches.
            if (!in_array($uri, ['/api/login', '/api/refresh', '/api/logout'], true)) {
                $keys = $this->cache_keys_for_write($uri);
                if (!empty($keys)) {
                    try { $this->api_cache_invalidate($keys); } catch (\Throwable $e) {}
                    $self = $this;
                    register_shutdown_function(function () use ($self, $keys) {
                        try { $self->api_cache_invalidate($keys); } catch (\Throwable $e) {}
                    });
                }
            }
        }
    }

    private function gate_writes() {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD'], true)) return;
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Normalize URIs when the app is served from a subdirectory (e.g. WAMP).
        $pos = strpos($uri, '/api/');
        if ($pos !== false) $uri = substr($uri, $pos);
        $public = ['/api/login', '/api/refresh', '/api/logout'];
        if (in_array($uri, $public, true)) return;

        // User management (create/edit/delete accounts) is admin-only.
        // Dispatchers keep every other admin function.
        if (preg_match('#^/api/users(?:/\d+)?$#', $uri)) {
            $this->admin_auth();
            return;
        }

        $driverAllowed = [
            '#^/api/trips/\d+/(boarding|depart|complete|cancel)$#',
            '#^/api/tickets/\d+/scan$#',
            '#^/api/notifications#',
            '#^/api/device-tokens#',
        ];
        foreach ($driverAllowed as $re) {
            if (preg_match($re, $uri)) {
                $this->any_auth();
                return;
            }
        }
        // Everything else that writes is dispatcher/admin only.
        $this->dispatcher_auth();
    }

    // â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function json_input() {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data)) return $data;
        }
        return $_POST ?: [];
    }

    private function now() {
        return date('Y-m-d H:i:s');
    }

    private function public_user($user) {
        if (!$user) return null;
        unset($user['password']);
        return $user;
    }

    private function notify($userId, $tripId, $type, $title, $message, $payload = null) {
        try {
            $model = $this->call->model('Notification_model');
            $payloadJson = $payload !== null ? (is_string($payload) ? $payload : json_encode($payload)) : null;
            return $model->insert([
                'user_id'  => $userId ? (int) $userId : null,
                'trip_id'  => $tripId ? (int) $tripId : null,
                'type'     => substr((string) $type, 0, 30),
                'title'    => substr((string) $title, 0, 100),
                'message'  => substr((string) $message, 0, 255),
                'payload'  => $payloadJson,
                'pushed'   => 0,
                'is_read'  => 0,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function make_qr($tripId) {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return 'ST-T' . (int) $tripId . '-' . $suffix;
    }

    private function count_where($table, $conditions) {
        $this->db->table($table);
        if (!empty($conditions)) {
            $this->db->where($conditions);
        }
        return (int) $this->db->count();
    }

    // â”€â”€ Short-lived file cache for GET lists â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // The DB lives on Aiven (remote), so every uncached GET pays
    // TLS + TCP + a remote round-trip. A 10-30s file cache keyed
    // by resource + invalidated on writes avoids most of that.
    // Best-effort: if runtime/cache is not writable we just skip caching.

    private function api_cache_lib() {
        return $this->call->library('cache');
    }

    private function api_cache_get($key) {
        if (!empty($_GET['_fresh'])) return null;
        try {
            $v = $this->api_cache_lib()->get($key);
            return $v === false ? null : $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function api_cache_put($key, $value, $ttlSeconds) {
        try {
            $this->api_cache_lib()->write($value, $key, (int) $ttlSeconds);
        } catch (\Throwable $e) {
            // ignore cache write failures
        }
    }

    private function api_cache_invalidate(array $keys) {
        if (empty($keys)) return;
        try {
            $cache = $this->api_cache_lib();
            foreach ($keys as $k) {
                try { $cache->delete($k); } catch (\Throwable $e2) {}
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** Return cached value or run $loader() once, then cache it. */
    private function cached_list($key, $ttlSeconds, callable $loader) {
        if (empty($_GET['_fresh'])) {
            $hit = $this->api_cache_get($key);
            if ($hit !== null) return $hit;
        }
        $fresh = $loader();
        $this->api_cache_put($key, $fresh, $ttlSeconds);
        return $fresh;
    }

    /**
     * Early cache serve for hot GET lists (called from before_action
     * before the DB connection is opened). Returns true if it sent
     * a cached response (caller must exit).
     */
    private function try_early_cache_hit($response): bool {
        try {
            // ?_fresh=1 (frontend force fetch) bypasses all caching so even
            // manual Navicat edits show up immediately.
            if (!empty($_GET['_fresh'])) return false;
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $pos = strpos($uri, '/api/');
            if ($pos !== false) $uri = substr($uri, $pos);
            // Strip trailing slash (except root) for exact matching.
            if ($uri !== '/' && substr($uri, -1) === '/') $uri = rtrim($uri, '/');

            $map = [
                '/api/dashboard'              => ['api_dashboard', 'Dashboard stats retrieved'],
                '/api/routes'                 => ['api_routes', 'Routes retrieved'],
                '/api/trips'                  => ['api_trips', 'Trips retrieved'],
                '/api/vehicles'               => ['api_vehicles', 'Vehicles retrieved'],
                '/api/drivers'                => ['api_drivers', 'Drivers retrieved'],
                '/api/dispatch-queue'         => ['api_dq', 'Dispatch queue retrieved'],
                '/api/tickets'                => ['api_tickets', 'Tickets retrieved'],
                '/api/announcements'          => ['api_ann', 'Announcements retrieved'],
                '/api/passenger-counts'       => ['api_pc', 'Passenger counts retrieved'],
                '/api/users'                  => ['api_users', 'Users retrieved'],
                '/api/schedule-overrides'     => ['api_overrides', 'Schedule overrides retrieved'],
                '/api/reports/trip-history'   => ['api_report_trip_history', 'Trip history retrieved'],
                '/api/reports/adherence'      => ['api_adherence', 'Schedule adherence retrieved'],
                '/api/reports/daily-volume'   => ['api_daily_volume', 'Daily volume retrieved'],
            ];

            if (isset($map[$uri])) {
                [$key, $msg] = $map[$uri];
                $hit = $this->api_cache_get($key);
                if ($hit !== null) {
                    $response->send_json_success($hit, $msg);
                    return true;
                }
                return false;
            }

            if ($uri === '/api/passenger-status') {
                $key = 'api_passenger_status' . ((($_GET['include_cancelled'] ?? '') === '1') ? '_inc_cancelled' : '_live');
                $hit = $this->api_cache_get($key);
                if ($hit !== null) {
                    $response->send_json_success($hit, 'Passenger status retrieved');
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // fall through to normal DB path on any cache error
        }
        return false;
    }

    /** Map a write URI to the GET cache keys it affects. */
    private function cache_keys_for_write(string $uri): array {
        $common = ['api_dashboard', 'api_passenger_status_live', 'api_passenger_status_inc_cancelled'];
        if (strpos($uri, '/api/routes') === 0) {
            return array_merge(['api_routes', 'api_report_trip_history'], $common);
        }
        if (strpos($uri, '/api/trips') === 0) {
            return array_merge(['api_trips', 'api_dq', 'api_vehicles', 'api_drivers', 'api_report_trip_history', 'api_adherence'], $common);
        }
        if (strpos($uri, '/api/vehicles') === 0) {
            return array_merge(['api_vehicles', 'api_report_trip_history'], $common);
        }
        if (strpos($uri, '/api/drivers') === 0) {
            return array_merge(['api_drivers', 'api_report_trip_history'], $common);
        }
        if (strpos($uri, '/api/dispatch-queue') === 0) {
            return array_merge(['api_dq', 'api_trips', 'api_vehicles', 'api_drivers'], $common);
        }
        if (strpos($uri, '/api/tickets') === 0) {
            return array_merge(['api_tickets'], $common);
        }
        if (strpos($uri, '/api/announcements') === 0) {
            return ['api_ann'];
        }
        if (strpos($uri, '/api/passenger-counts') === 0) {
            return array_merge(['api_pc', 'api_dq', 'api_daily_volume', 'api_adherence'], $common);
        }
        if (strpos($uri, '/api/users') === 0) {
            return ['api_users'];
        }
        if (strpos($uri, '/api/schedule-overrides') === 0) {
            return array_merge(['api_overrides', 'api_trips', 'api_report_trip_history', 'api_adherence'], $common);
        }
        if (strpos($uri, '/api/notifications') === 0 || strpos($uri, '/api/device-tokens') === 0) {
            return [];
        }
        // Unknown write: play safe and clear the hot caches.
        return ['api_dashboard', 'api_passenger_status_live', 'api_passenger_status_inc_cancelled'];
    }

    /**
     * Bidirectional sync: Trips <-> Dispatch Queue (same mapping as the
     * Navicat query the user ran):
     *   scheduled/delayed -> waiting
     *   boarding          -> boarding
     *   departed/completed-> departed
     *   cancelled         -> removed (Shown as Deleted in History)
     * and reverse:
     *   waiting  -> scheduled
     *   boarding -> boarding
     *   departed -> departed
     *   removed  -> cancelled
     */
    private function queue_status_for_trip(string $tripStatus): ?string {
        $s = strtolower(trim($tripStatus));
        $map = [
            'scheduled' => 'waiting',
            'delayed'   => 'waiting',
            'boarding'  => 'boarding',
            'departed'  => 'departed',
            'completed' => 'departed',
            'cancelled' => 'removed',
        ];
        return $map[$s] ?? null;
    }

    private function trip_status_for_queue(string $queueStatus): ?string {
        $s = strtolower(trim($queueStatus));
        $map = [
            'waiting'  => 'scheduled',
            'boarding' => 'boarding',
            'departed' => 'departed',
            'removed'  => 'cancelled',
        ];
        return $map[$s] ?? null;
    }

    /** After trips.status changes, mirror it onto dispatch_queue rows for that trip. */
    private function sync_queue_from_trip(int $tripId, string $tripStatus): void {
        try {
            $dqStatus = $this->queue_status_for_trip($tripStatus);
            if ($dqStatus === null) return;
            $dq = $this->call->model('DispatchQueue_model');
            $rows = $dq->_find_all_by('trip_id', $tripId);
            if (!is_array($rows)) return;
            foreach ($rows as $r) {
                // Only move active/history rows in sync; never resurrect a
                // removed (Deleted) row back to waiting unless trip went back
                // to scheduled (reopen). departed stays departed.
                $cur = strtolower((string)($r['status'] ?? ''));
                if ($cur === $dqStatus) continue;
                // Don't flip a removed row back unless the trip was rescheduled.
                if ($cur === 'removed' && $dqStatus !== 'waiting') continue;
                try { $dq->_update((int)$r['id'], ['status' => $dqStatus]); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {}
    }

    private function assert_resource_free(int $vehicleId, int $driverId, ?int $excludeTripId): ?string {
        // Vehicle/driver with a non-completed trip (scheduled/boarding/departed)
        // cannot be assigned to another trip.
        try {
            $model = $this->call->model('Trip_model');
            foreach ($model->all() as $t) {
                if ($excludeTripId !== null && (int) $t['id'] === $excludeTripId) continue;
                $s = strtolower((string)($t['status'] ?? ''));
                if (!in_array($s, ['scheduled', 'boarding', 'departed'], true)) continue;
                if ((int)($t['vehicle_id'] ?? 0) === $vehicleId) return "Vehicle is already assigned to Trip #{$t['id']} ({$s}). Complete or cancel that trip first.";
                if ((int)($t['driver_id'] ?? 0) === $driverId) return "Driver is already assigned to Trip #{$t['id']} ({$s}). Complete or cancel that trip first.";
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /** After dispatch_queue.status changes, mirror it onto the linked trip. */
    private function sync_trip_from_queue(int $tripId, string $queueStatus): void {
        try {
            $tStatus = $this->trip_status_for_queue($queueStatus);
            if ($tStatus === null) return;
            $t = $this->call->model('Trip_model');
            $trip = $t->find($tripId);
            if (!$trip) return;
            if (strtolower((string)($trip['status'] ?? '')) === $tStatus) {
                // Still recompute in case status already matches but
                // vehicle/driver status went stale (e.g. manual DB edit).
                if (!empty($trip['vehicle_id'])) $this->recompute_vehicle_status((int) $trip['vehicle_id']);
                if (!empty($trip['driver_id'])) $this->recompute_driver_status((int) $trip['driver_id']);
                return;
            }
            try { $t->_update($tripId, ['status' => $tStatus]); } catch (\Throwable $e) {}
            if (!empty($trip['vehicle_id'])) $this->recompute_vehicle_status((int) $trip['vehicle_id']);
            if (!empty($trip['driver_id'])) $this->recompute_driver_status((int) $trip['driver_id']);
        } catch (\Throwable $e) {}
    }

    /**
     * On trip completion, auto-save passenger_counts for that trip:
     * boarded = scanned/boarded tickets, waiting = still-pending tickets,
     * seats_available = capacity - boarded (pending did not board).
     * Creates the row if missing, otherwise updates the latest row.
     */
    private function save_completed_passenger_count(int $tripId): void {
        try {
            $trip = $this->call->model('Trip_model')->find($tripId);
            if (!$trip) return;
            $tickets = $this->call->model('Ticket_model')->_find_all_by('trip_id', $tripId);
            if (!is_array($tickets)) $tickets = [];
            $boarded = 0;
            $pending = 0;
            foreach ($tickets as $t) {
                $s = strtolower((string)($t['status'] ?? ''));
                if ($s === 'boarded' || $s === 'scanned' || !empty($t['scanned_at'])) $boarded++;
                elseif ($s === 'pending') $pending++;
                // cancelled is ignored (seat restored)
            }
            // Legacy fallback: no ticket rows but manual counts exist, keep them.
            // (When tickets exist, tickets are the source of truth.)
            $cap = 0;
            if (!empty($trip['vehicle_id'])) {
                try {
                    $veh = $this->call->model('Vehicle_model')->find((int) $trip['vehicle_id']);
                    if (isset($veh['capacity'])) $cap = (int) $veh['capacity'];
                } catch (\Throwable $e) {}
            }
            if ($cap <= 0) $cap = 15;
            $free = max($cap - $boarded, 0);
            $pcModel = $this->call->model('PassengerCount_model');
            $existing = $pcModel->_find_all_by('trip_id', $tripId);
            if (is_array($existing) && !empty($existing)) {
                usort($existing, function ($a, $b) { return (int)$b['id'] <=> (int)$a['id']; });
                $pcModel->_update((int) $existing[0]['id'], [
                    'passengers_waiting' => $pending,
                    'passengers_boarded' => $boarded,
                    'seats_available' => $free,
                ]);
            } else {
                $pcModel->insert([
                    'trip_id' => $tripId,
                    'passengers_waiting' => $pending,
                    'passengers_boarded' => $boarded,
                    'seats_available' => $free,
                    'recorded_by' => isset($trip['created_by']) ? (int) $trip['created_by'] : null,
                    'recorded_at' => $this->now(),
                ]);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Recompute a vehicle's operational status from its trips:
     *   scheduled / boarding -> queued
     *   departed              -> dispatched
     *   no active trips       -> available
     * Manual 'maintenance' is an override and is never auto-changed.
     */
    private function recompute_vehicle_status(int $vehicleId): void {
        try {
            $vm = $this->call->model('Vehicle_model');
            $veh = $vm->find($vehicleId);
            if (!$veh) return;
            if (strtolower((string)($veh['status'] ?? '')) === 'maintenance') return;
            $trips = $this->call->model('Trip_model')->_find_all_by('vehicle_id', $vehicleId);
            if (!is_array($trips)) $trips = [];
            $active = array_filter($trips, function ($t) {
                return in_array(strtolower((string)($t['status'] ?? '')), ['scheduled', 'boarding', 'departed'], true);
            });
            if (empty($active)) {
                $next = 'available';
            } else {
                $hasDeparted = false;
                foreach ($active as $t) {
                    if (strtolower((string)($t['status'] ?? '')) === 'departed') { $hasDeparted = true; break; }
                }
                $next = $hasDeparted ? 'dispatched' : 'queued';
            }
            if (strtolower((string)($veh['status'] ?? '')) !== $next) {
                $vm->_update($vehicleId, ['status' => $next]);
            }
        } catch (\Throwable $e) {}
    }

    private function assert_vehicle_assignable(int $vehicleId): ?string {
        try {
            $veh = $this->call->model('Vehicle_model')->find($vehicleId);
            if ($veh && strtolower((string)($veh['status'] ?? '')) === 'maintenance') {
                return "Vehicle {$veh['plate_number']} is in maintenance and cannot be assigned to a trip.";
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Recompute a driver's operational status from its trips:
     *   scheduled / boarding / departed -> on_trip
     *   no active trips                  -> active
     * Manual 'inactive' is an override and is never auto-changed.
     */
    private function recompute_driver_status(int $driverId): void {
        try {
            $dm = $this->call->model('Driver_model');
            $drv = $dm->find($driverId);
            if (!$drv) return;
            if (strtolower((string)($drv['status'] ?? '')) === 'inactive') return;
            $trips = $this->call->model('Trip_model')->_find_all_by('driver_id', $driverId);
            if (!is_array($trips)) $trips = [];
            $hasActive = false;
            foreach ($trips as $t) {
                if (in_array(strtolower((string)($t['status'] ?? '')), ['scheduled', 'boarding', 'departed'], true)) { $hasActive = true; break; }
            }
            $next = $hasActive ? 'on_trip' : 'active';
            if (strtolower((string)($drv['status'] ?? '')) !== $next) {
                $dm->_update($driverId, ['status' => $next]);
            }
        } catch (\Throwable $e) {}
    }

    private function assert_driver_assignable(int $driverId): ?string {
        try {
            $drv = $this->call->model('Driver_model')->find($driverId);
            if ($drv && strtolower((string)($drv['status'] ?? '')) === 'inactive') {
                return "Driver {$drv['full_name']} is inactive and cannot be assigned to a trip.";
            }
        } catch (\Throwable $e) {}
        return null;
    }

    // â”€â”€ JWT (LavaLust Api library, uses .env JWT_SECRET + REFRESH_TOKEN_KEY) â”€â”€

    private function api_lib() {
        return $this->call->library('api');
    }

    private function scopes_for_role($role) {
        $map = [
            'admin'      => ['read', 'write', 'delete'],
            'dispatcher' => ['read', 'write'],
            'driver'     => ['read', 'write'],
        ];
        return $map[$role] ?? ['read'];
    }

    /**
     * Require a valid JWT access token for the given roles.
     * On failure the Api library responds 401 and exits.
     * On wrong role, responds 403 and exits.
     */
    private function require_auth(?array $allowedRoles = null) {
        $payload = $this->api_lib()->require_jwt();
        if ($allowedRoles !== null && !in_array($payload['role'] ?? '', $allowedRoles, true)) {
            load_class('response', 'kernel')->send_forbidden('Insufficient role for this action');
            // Response::send() does not exit â€” halt so the gated write never runs.
            exit;
        }
        return $payload;
    }

    private function dispatcher_auth() {
        return $this->require_auth(['admin', 'dispatcher']);
    }

    private function admin_auth() {
        return $this->require_auth(['admin']);
    }

    private function any_auth() {
        return $this->require_auth(['admin', 'dispatcher', 'driver']);
    }

    // â”€â”€ Auth â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * POST /api/login  {username, password}
     * Verifies bcrypt password against existing `users` table,
     * then issues JWT access + refresh tokens via LavaLust Api helper
     * (app/config/api.php reads .env JWT_SECRET + REFRESH_TOKEN_KEY).
     * Response data = user fields + `token`, `refresh_token`, `tokens`.
     */
    public function login() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            $username = trim($input['username'] ?? $input['email'] ?? '');
            $password = (string) ($input['password'] ?? '');
            if ($username === '' || $password === '') {
                $response->send_json_error('Username and password are required', 422);
                return;
            }
            $userModel = $this->call->model('User_model');
            $user = $userModel->_find_by('username', $username);
            if (!$user) {
                $user = $userModel->_find_by('email', $username);
            }
            if (!$user || !password_verify($password, $user['password'])) {
                $response->send_json_error('Invalid credentials', 401);
                return;
            }
            if ((int) ($user['is_active'] ?? 1) !== 1) {
                $response->send_json_error('Account is inactive', 403);
                return;
            }
            $userModel->_update((int) $user['id'], ['last_login_at' => $this->now()]);
            $fresh = $userModel->find($user['id']);
            $role = $fresh['role'] ?? 'user';
            $tokens = $this->api_lib()->issue_tokens([
                'id'     => (int) $fresh['id'],
                'role'   => $role,
                'scopes' => $this->scopes_for_role($role),
            ]);
            $payload = $this->public_user($fresh);
            $payload['token'] = $tokens['access_token'];
            $payload['refresh_token'] = $tokens['refresh_token'];
            $payload['tokens'] = $tokens;
            $response->send_json_success($payload, 'Logged in');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/refresh  {refresh_token} */
    public function refresh() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            $refreshToken = (string) ($input['refresh_token'] ?? '');
            if ($refreshToken === '') {
                $response->send_json_error('refresh_token is required', 422);
                return;
            }
            $apiLib = $this->api_lib();
            $payload = $apiLib->validate_jwt($refreshToken, 'refresh');
            if (!$payload) {
                $response->send_json_error('Invalid refresh token', 401);
                return;
            }
            // Must exist in refresh_tokens (hashed with REFRESH_TOKEN_KEY) and not expired
            $hashed = hash_hmac('sha256', $refreshToken, getenv('REFRESH_TOKEN_KEY') ?: '');
            $stmt = $this->db->raw('SELECT * FROM refresh_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1', [$hashed]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$found) {
                $response->send_json_error('Refresh token expired or revoked', 401);
                return;
            }
            $userModel = $this->call->model('User_model');
            $user = $userModel->find($payload['sub']);
            if (!$user || (int) ($user['is_active'] ?? 1) !== 1) {
                $response->send_json_error('User not found or inactive', 401);
                return;
            }
            $apiLib->revoke_refresh_token($refreshToken);
            $tokens = $apiLib->issue_tokens([
                'id'     => (int) $user['id'],
                'role'   => $user['role'],
                'scopes' => $this->scopes_for_role($user['role']),
            ]);
            $out = $this->public_user($user);
            $out['token'] = $tokens['access_token'];
            $out['refresh_token'] = $tokens['refresh_token'];
            $out['tokens'] = $tokens;
            $response->send_json_success($out, 'Tokens refreshed');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/logout  {refresh_token} (revokes refresh token) */
    public function logout() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            $refreshToken = (string) ($input['refresh_token'] ?? '');
            if ($refreshToken !== '') {
                $this->api_lib()->revoke_refresh_token($refreshToken);
            }
            $response->send_json_success(null, 'Logged out');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** GET /api/me â€” current user from JWT */
    public function me() {
        try {
            $response = load_class('response', 'kernel');
            $payload = $this->any_auth();
            $user = $this->call->model('User_model')->find($payload['sub']);
            if (!$user) {
                $response->send_not_found('User not found');
                return;
            }
            $response->send_json_success($this->public_user($user), 'Current user');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Dashboard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * GET /api/dashboard
     */
    public function dashboard() {
        try {
            $response = load_class('response', 'kernel');
            $stats = $this->cached_list('api_dashboard', 10, function () {
            $userModel   = $this->call->model('User_model');
            $driverModel = $this->call->model('Driver_model');
            $vehicleModel = $this->call->model('Vehicle_model');
            $tripModel   = $this->call->model('Trip_model');
            $routeModel  = $this->call->model('Route_model');
            $ticketModel = $this->call->model('Ticket_model');

            return [
                'total_users'     => (int) $userModel->count(),
                'total_drivers'   => (int) $driverModel->count(),
                'total_vehicles'  => (int) $vehicleModel->count(),
                'total_routes'    => (int) $routeModel->count(),
                'total_trips'     => (int) $tripModel->count(),
                'total_tickets'   => (int) $ticketModel->count(),
                // NOTE: Model->_filter()->count() loses the filter (Model::_count resets the table),
                // so filtered counts use $this->db directly.
                'active_drivers'  => $this->count_where('drivers', ['status' => 'active']),
                'on_trip_drivers' => $this->count_where('drivers', ['status' => 'on_trip']),
                'available_vehicles' => $this->count_where('vehicles', ['status' => 'available']),
                'scheduled_trips'   => $this->count_where('trips', ['status' => 'scheduled']),
                'boarding_trips'    => $this->count_where('trips', ['status' => 'boarding']),
                'departed_trips'    => $this->count_where('trips', ['status' => 'departed']),
                'completed_trips'   => $this->count_where('trips', ['status' => 'completed']),
                'cancelled_trips'   => $this->count_where('trips', ['status' => 'cancelled']),
                'waiting_queue'     => $this->count_where('dispatch_queue', ['status' => 'waiting']),
            ];
            });

            $response->send_json_success($stats, 'Dashboard stats retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Routes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function routes() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_routes', 30, function () {
                return $this->call->model('Route_model')->all();
            });
            $response->send_json_success($data, 'Routes retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function route($id) {
        try {
            $response = load_class('response', 'kernel');
            $routeModel = $this->call->model('Route_model');
            $data = $routeModel->find($id);
            if (!$data) {
                $response->send_not_found('Route not found');
                return;
            }
            $response->send_json_success($data, 'Route retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_route() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['origin']) || empty($input['destination'])) {
                $response->send_json_error('origin and destination are required', 422);
                return;
            }
            $model = $this->call->model('Route_model');
            $id = $model->insert([
                'origin'            => trim($input['origin']),
                'destination'       => trim($input['destination']),
                'estimated_minutes' => isset($input['estimated_minutes']) ? (int) $input['estimated_minutes'] : null,
                'fare'              => $input['fare'] ?? null,
                'is_active'         => isset($input['is_active']) ? (int) $input['is_active'] : 1,
            ]);
            $response->send_json_success($model->find($id), 'Route created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_route($id) {
        try {
            $response = load_class('response', 'kernel');
            $model = $this->call->model('Route_model');
            if (!$model->find($id)) { $response->send_not_found('Route not found'); return; }
            $input = $this->json_input();
            $data = [];
            foreach (['origin','destination','estimated_minutes','fare','is_active'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            $model->_update((int) $id, $data);
            $response->send_json_success($model->find($id), 'Route updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_route($id) {
        try {
            $response = load_class('response', 'kernel');
            $model = $this->call->model('Route_model');
            if (!$model->find($id)) { $response->send_not_found('Route not found'); return; }
            $model->_delete((int) $id);
            $response->send_json_success(null, 'Route deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Trips (Dispatcher assigns vehicle+driver, Driver updates status) â”€â”€

    public function trips() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_trips', 15, function () {
                return $this->call->model('Trip_model')->all();
            });
            $response->send_json_success($data, 'Trips retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $tripModel = $this->call->model('Trip_model');
            $data = $tripModel->find($id);
            if (!$data) {
                $response->send_not_found('Trip not found');
                return;
            }
            $response->send_json_success($data, 'Trip retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_trip() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            foreach (['vehicle_id','driver_id','route_id','scheduled_departure'] as $f) {
                if (empty($input[$f])) {
                    $response->send_json_error("{$f} is required", 422);
                    return;
                }
            }
            $model = $this->call->model('Trip_model');
            // Busy vehicles/drivers cannot be reused until their trip completes/cancels.
            $busyErr = $this->assert_resource_free((int) $input['vehicle_id'], (int) $input['driver_id'], null);
            if ($busyErr !== null) { $response->send_json_error($busyErr, 422); return; }
            // Maintenance vehicles cannot be assigned to new trips.
            $maintErr = $this->assert_vehicle_assignable((int) $input['vehicle_id']);
            if ($maintErr !== null) { $response->send_json_error($maintErr, 422); return; }
            // Inactive drivers cannot be assigned to new trips.
            $inactErr = $this->assert_driver_assignable((int) $input['driver_id']);
            if ($inactErr !== null) { $response->send_json_error($inactErr, 422); return; }
            $id = $model->insert([
                'vehicle_id'           => (int) $input['vehicle_id'],
                'driver_id'            => (int) $input['driver_id'],
                'route_id'             => (int) $input['route_id'],
                'scheduled_departure'  => $input['scheduled_departure'],
                'actual_departure'     => $input['actual_departure'] ?? null,
                'status'               => $input['status'] ?? 'scheduled',
                'created_by'           => isset($input['created_by']) ? (int) $input['created_by'] : null,
            ]);
            // Notify assigned driver (FCM stub: notification row + device_tokens lookup)
            $driver = $this->call->model('Driver_model')->find($input['driver_id']);
            if ($driver && !empty($driver['user_id'])) {
                $this->notify($driver['user_id'], $id, 'schedule', 'New trip assigned', 'Trip #' . $id . ' assigned. Departure: ' . $input['scheduled_departure'], ['trip_id' => (int) $id]);
            }
            // Vehicle status follows its trip(s): scheduled/boarding -> queued.
            $this->recompute_vehicle_status((int) $input['vehicle_id']);
            // Driver status follows its trip(s): active trip -> on_trip.
            $this->recompute_driver_status((int) $input['driver_id']);
            $response->send_json_success($model->find($id), 'Trip created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $model = $this->call->model('Trip_model');
            $existing = $model->find($id);
            if (!$existing) { $response->send_not_found('Trip not found'); return; }
            $input = $this->json_input();
            $data = [];
            foreach (['vehicle_id','driver_id','route_id','scheduled_departure','actual_departure','status','created_by'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            // If vehicle/driver are being changed, the new assignment must
            // not be busy on another unfinished trip (itself excluded).
            if (isset($data['vehicle_id']) || isset($data['driver_id'])) {
                $nv = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : (int) $existing['vehicle_id'];
                $nd = isset($data['driver_id']) ? (int) $data['driver_id'] : (int) $existing['driver_id'];
                $busyErr = $this->assert_resource_free($nv, $nd, (int) $id);
                if ($busyErr !== null) { $response->send_json_error($busyErr, 422); return; }
                // Maintenance vehicles cannot be assigned.
                if (isset($data['vehicle_id'])) {
                    $maintErr = $this->assert_vehicle_assignable($nv);
                    if ($maintErr !== null) { $response->send_json_error($maintErr, 422); return; }
                }
                // Inactive drivers cannot be assigned.
                if (isset($data['driver_id'])) {
                    $inactErr = $this->assert_driver_assignable($nd);
                    if ($inactErr !== null) { $response->send_json_error($inactErr, 422); return; }
                }
            }
            $oldVehicleId = (int)($existing['vehicle_id'] ?? 0);
            $oldDriverId = (int)($existing['driver_id'] ?? 0);
            $model->_update((int) $id, $data);
            if (isset($data['status'])) {
                $this->sync_queue_from_trip((int) $id, (string) $data['status']);
                if (strtolower((string) $data['status']) === 'completed') $this->save_completed_passenger_count((int) $id);
            }
            // Vehicle status follows trips (queued/dispatched/available).
            if ($oldVehicleId) $this->recompute_vehicle_status($oldVehicleId);
            if (isset($data['vehicle_id'])) $this->recompute_vehicle_status((int) $data['vehicle_id']);
            elseif (isset($data['status'])) $this->recompute_vehicle_status((int)($existing['vehicle_id'] ?? 0));
            // Driver status follows trips (on_trip/active).
            if ($oldDriverId) $this->recompute_driver_status($oldDriverId);
            if (isset($data['driver_id'])) $this->recompute_driver_status((int) $data['driver_id']);
            elseif (isset($data['status'])) $this->recompute_driver_status((int)($existing['driver_id'] ?? 0));
            $response->send_json_success($model->find($id), 'Trip updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $model = $this->call->model('Trip_model');
            $trip = $model->find($id);
            if (!$trip) { $response->send_not_found('Trip not found'); return; }
            $vid = (int)($trip['vehicle_id'] ?? 0);
            $did = (int)($trip['driver_id'] ?? 0);
            $model->_delete((int) $id);
            if ($vid) $this->recompute_vehicle_status($vid);
            if ($did) $this->recompute_driver_status($did);
            $response->send_json_success(null, 'Trip deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/trips/{id}/depart â€” Driver live status */
    public function boarding_trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $tripModel = $this->call->model('Trip_model');
            $trip = $tripModel->find($id);
            if (!$trip) { $response->send_not_found('Trip not found'); return; }
            $tripModel->_update((int) $id, ['status' => 'boarding']);
            $this->sync_queue_from_trip((int) $id, 'boarding');
            if (!empty($trip['vehicle_id'])) $this->recompute_vehicle_status((int) $trip['vehicle_id']);
            if (!empty($trip['driver_id'])) $this->recompute_driver_status((int) $trip['driver_id']);
            $response->send_json_success($tripModel->find($id), 'Trip boarding');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function depart_trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $tripModel = $this->call->model('Trip_model');
            $trip = $tripModel->find($id);
            if (!$trip) { $response->send_not_found('Trip not found'); return; }
            $now = $this->now();
            $tripModel->_update((int) $id, ['status' => 'departed', 'actual_departure' => $now]);
            // Mark queue entry departed
            $dqModel = $this->call->model('DispatchQueue_model');
            $entries = $dqModel->_find_all_by('trip_id', (int) $id);
            if (is_array($entries)) {
                foreach ($entries as $row) {
                    $dqModel->_update((int) $row['id'], ['status' => 'departed', 'departed_at' => $now]);
                }
            }
            // Vehicle dispatched + driver on_trip
            $vModel = $this->call->model('Vehicle_model');
            if (!empty($trip['vehicle_id'])) {
                try { $vModel->_update((int) $trip['vehicle_id'], ['status' => 'dispatched']); } catch (\Throwable $x) {}
            }
            $dModel = $this->call->model('Driver_model');
            $driver = null;
            if (!empty($trip['driver_id'])) {
                $driver = $dModel->find($trip['driver_id']);
                if ($driver) {
                    try { $dModel->_update((int) $trip['driver_id'], ['status' => 'on_trip']); } catch (\Throwable $x) {}
                }
            }
            if ($driver && !empty($driver['user_id'])) {
                $this->notify($driver['user_id'], (int) $id, 'dispatch', 'Van departed', 'Trip #' . $id . ' marked departed at ' . $now, ['trip_id' => (int) $id]);
            }
            $response->send_json_success($tripModel->find($id), 'Trip departed');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/trips/{id}/complete */
    public function complete_trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $tripModel = $this->call->model('Trip_model');
            $trip = $tripModel->find($id);
            if (!$trip) { $response->send_not_found('Trip not found'); return; }
            $tripModel->_update((int) $id, ['status' => 'completed']);
            $this->sync_queue_from_trip((int) $id, 'completed');
            $this->save_completed_passenger_count((int) $id);
            if (!empty($trip['vehicle_id'])) $this->recompute_vehicle_status((int) $trip['vehicle_id']);
            if (!empty($trip['driver_id'])) $this->recompute_driver_status((int) $trip['driver_id']);
            $response->send_json_success($tripModel->find($id), 'Trip completed');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/trips/{id}/cancel */
    public function cancel_trip($id) {
        try {
            $response = load_class('response', 'kernel');
            $tripModel = $this->call->model('Trip_model');
            $trip = $tripModel->find($id);
            if (!$trip) { $response->send_not_found('Trip not found'); return; }
            $tripModel->_update((int) $id, ['status' => 'cancelled']);
            $this->sync_queue_from_trip((int) $id, 'cancelled');
            if (!empty($trip['vehicle_id'])) $this->recompute_vehicle_status((int) $trip['vehicle_id']);
            if (!empty($trip['driver_id'])) $this->recompute_driver_status((int) $trip['driver_id']);
            $response->send_json_success($tripModel->find($id), 'Trip cancelled');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** GET /api/drivers/{id}/trips â€” Driver assigned trips */
    public function driver_trips($driverId) {
        try {
            $response = load_class('response', 'kernel');
            // A driver may only see their OWN trips. Admin/dispatcher
            // may open any driver's trips.
            $payload = $this->any_auth();
            if (($payload['role'] ?? '') === 'driver') {
                $uid = (int) ($payload['sub'] ?? 0);
                $mine = null;
                $mineRows = $this->call->model('Driver_model')->_find_all_by('user_id', $uid);
                if (is_array($mineRows) && !empty($mineRows)) $mine = (int) $mineRows[0]['id'];
                if ($mine === null || $mine !== (int) $driverId) {
                    $response->send_forbidden('Drivers can only view their own trips');
                    return;
                }
            }
            $tripModel = $this->call->model('Trip_model');
            $trips = $tripModel->_find_all_by('driver_id', (int) $driverId);
            // Attach queue position if exists
            $dqModel = $this->call->model('DispatchQueue_model');
            $queue = $dqModel->_find_all_by('driver_id', (int) $driverId);
            $positionByTrip = [];
            if (is_array($queue)) {
                foreach ($queue as $q) {
                    if (!empty($q['trip_id'])) $positionByTrip[(int) $q['trip_id']] = $q;
                }
            }
            $out = [];
            foreach ((array) $trips as $t) {
                $t['queue'] = $positionByTrip[(int) $t['id']] ?? null;
                $out[] = $t;
            }
            $response->send_json_success($out, 'Driver trips retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Vehicles â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function vehicles() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_vehicles', 30, function () {
                return $this->call->model('Vehicle_model')->all();
            });
            $response->send_json_success($data, 'Vehicles retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function vehicle($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Vehicle_model');
            $d = $m->find($id);
            if (!$d) { $response->send_not_found('Vehicle not found'); return; }
            $response->send_json_success($d, 'Vehicle retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_vehicle() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['plate_number'])) { $response->send_json_error('plate_number is required', 422); return; }
            $m = $this->call->model('Vehicle_model');
            $id = $m->insert([
                'plate_number' => trim($input['plate_number']),
                'model'        => $input['model'] ?? null,
                'capacity'     => isset($input['capacity']) ? (int) $input['capacity'] : null,
                'status'       => $input['status'] ?? 'available',
            ]);
            $response->send_json_success($m->find($id), 'Vehicle created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_vehicle($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Vehicle_model');
            if (!$m->find($id)) { $response->send_not_found('Vehicle not found'); return; }
            $input = $this->json_input();
            $data = [];
            foreach (['plate_number','model','capacity','status'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            $m->_update((int) $id, $data);
            $response->send_json_success($m->find($id), 'Vehicle updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_vehicle($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Vehicle_model');
            if (!$m->find($id)) { $response->send_not_found('Vehicle not found'); return; }
            $m->_delete((int) $id);
            $response->send_json_success(null, 'Vehicle deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Drivers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function drivers() {
        try {
            $response = load_class('response', 'kernel');
            // Backfill any driver-role user that has no profile yet.
            $this->ensure_driver_profiles();
            $data = $this->cached_list('api_drivers', 30, function () {
                return $this->call->model('Driver_model')->all();
            });
            $response->send_json_success($data, 'Drivers retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_driver() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['full_name'])) { $response->send_json_error('full_name is required', 422); return; }
            $m = $this->call->model('Driver_model');
            // Idempotent: a driver user already got an auto profile at
            // user creation. Update it (e.g. Fleet adds a license_no)
            // instead of inserting a duplicate row.
            if (!empty($input['user_id'])) {
                $existingRows = $m->_find_all_by('user_id', (int) $input['user_id']);
                if (is_array($existingRows) && !empty($existingRows)) {
                    $row = $existingRows[0];
                    $upd = [];
                    foreach (['full_name','license_no','contact_no','status'] as $f) {
                        if (array_key_exists($f, $input) && $input[$f] !== null && $input[$f] !== '') $upd[$f] = $input[$f];
                    }
                    if (!empty($upd)) $m->_update((int) $row['id'], $upd);
                    $response->send_json_success($m->find($row['id']), 'Driver updated', 201);
                    return;
                }
            }
            $id = $m->insert([
                'user_id'    => isset($input['user_id']) ? (int) $input['user_id'] : null,
                'full_name'  => trim($input['full_name']),
                // '' not NULL: license_no is a non-null column.
                'license_no' => !empty($input['license_no']) ? trim((string) $input['license_no']) : '',
                'contact_no' => $input['contact_no'] ?? null,
                'status'     => $input['status'] ?? 'active',
            ]);
            $response->send_json_success($m->find($id), 'Driver created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_driver($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Driver_model');
            if (!$m->find($id)) { $response->send_not_found('Driver not found'); return; }
            $input = $this->json_input();
            $data = [];
            foreach (['user_id','full_name','license_no','contact_no','status'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            // If admin/reactivating with 'active' but the driver still has
            // active trips, keep the trip-derived 'on_trip' status.
            // 'inactive' stays as manual override (never auto-changed).
            if (isset($data['status']) && strtolower((string) $data['status']) === 'active') {
                $trips = $this->call->model('Trip_model')->_find_all_by('driver_id', (int) $id);
                if (is_array($trips)) {
                    foreach ($trips as $t) {
                        if (in_array(strtolower((string)($t['status'] ?? '')), ['scheduled', 'boarding', 'departed'], true)) {
                            $data['status'] = 'on_trip';
                            break;
                        }
                    }
                }
            }
            $m->_update((int) $id, $data);
            $response->send_json_success($m->find($id), 'Driver updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_driver($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Driver_model');
            if (!$m->find($id)) { $response->send_not_found('Driver not found'); return; }
            $m->_delete((int) $id);
            $response->send_json_success(null, 'Driver deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Dispatch Queue â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function dispatch_queue() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_dq', 10, function () {
                return $this->call->model('DispatchQueue_model')->all();
            });
            $response->send_json_success($data, 'Dispatch queue retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_dispatch_queue() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            foreach (['vehicle_id','driver_id','route_id'] as $f) {
                if (empty($input[$f])) { $response->send_json_error("{$f} is required", 422); return; }
            }
            $m = $this->call->model('DispatchQueue_model');
            $position = $input['queue_position'] ?? null;
            if ($position === null) {
                $all = $m->all() ?: [];
                $max = 0;
                foreach ((array) $all as $r) { if ((int) ($r['queue_position'] ?? 0) > $max) $max = (int) $r['queue_position']; }
                $position = $max + 1;
            }
            $id = $m->insert([
                'vehicle_id'     => (int) $input['vehicle_id'],
                'driver_id'      => (int) $input['driver_id'],
                'route_id'       => (int) $input['route_id'],
                'trip_id'        => isset($input['trip_id']) ? (int) $input['trip_id'] : null,
                'queue_position' => (int) $position,
                'status'         => $input['status'] ?? 'waiting',
                'queued_at'      => $input['queued_at'] ?? $this->now(),
                'departed_at'    => $input['departed_at'] ?? null,
            ]);
            $response->send_json_success($m->find($id), 'Added to dispatch queue', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_dispatch_queue($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('DispatchQueue_model');
            if (!$m->find($id)) { $response->send_not_found('Queue entry not found'); return; }
            $input = $this->json_input();
            $data = [];
            foreach (['vehicle_id','driver_id','route_id','trip_id','queue_position','status','queued_at','departed_at'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            $m->_update((int) $id, $data);
            // Reverse sync: dispatch status -> linked trip status
            // (boarding -> boarding, departed -> departed, removed -> cancelled).
            if (isset($data['status'])) {
                $updated = $m->find($id);
                $tid = !empty($updated['trip_id']) ? (int) $updated['trip_id'] : (!empty($data['trip_id']) ? (int) $data['trip_id'] : 0);
                if ($tid) $this->sync_trip_from_queue($tid, (string) $data['status']);
            }
            $response->send_json_success($m->find($id), 'Queue updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_dispatch_queue($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('DispatchQueue_model');
            if (!$m->find($id)) { $response->send_not_found('Queue entry not found'); return; }
            $m->_delete((int) $id);
            $response->send_json_success(null, 'Queue entry deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Tickets (QR) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function tickets() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_tickets', 10, function () {
                return $this->call->model('Ticket_model')->all();
            });
            $response->send_json_success($data, 'Tickets retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_ticket() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['trip_id']) || empty($input['passenger_name'])) {
                $response->send_json_error('trip_id and passenger_name are required', 422);
                return;
            }
            // Do not issue tickets for finished trips.
            $tripForCheck = $this->call->model('Trip_model')->find((int) $input['trip_id']);
            if (!$tripForCheck) { $response->send_not_found('Trip not found'); return; }
            $tripStatus = strtolower((string)($tripForCheck['status'] ?? ''));
            if ($tripStatus !== 'boarding') {
                $response->send_json_error("Tickets can only be issued for trips that are currently boarding (current status: {$tripStatus}).", 422);
                return;
            }
            $m = $this->call->model('Ticket_model');
            $qr = $input['qr_code'] ?? $this->make_qr($input['trip_id']);
            // Auto-assign the next available seat (1..capacity) for this
            // trip, skipping seats held by non-cancelled tickets. Cancelled
            // seats are reusable so a cancelled seat is restored.
            $seatNo = isset($input['seat_no']) && $input['seat_no'] !== null && $input['seat_no'] !== '' ? (int) $input['seat_no'] : null;
            if ($seatNo === null) {
                $trip = $this->call->model('Trip_model')->find((int) $input['trip_id']);
                $vehicle = !empty($trip['vehicle_id']) ? $this->call->model('Vehicle_model')->find((int) $trip['vehicle_id']) : null;
                $cap = isset($vehicle['capacity']) ? (int) $vehicle['capacity'] : 0;
                $taken = [];
                try {
                    $rows = $m->_find_all_by('trip_id', (int) $input['trip_id']);
                    if (is_array($rows)) {
                        foreach ($rows as $r) {
                            if (strtolower((string)($r['status'] ?? '')) !== 'cancelled' && !empty($r['seat_no'])) $taken[(int) $r['seat_no']] = true;
                        }
                    }
                } catch (\Throwable $e) {}
                $next = 1;
                if ($cap > 0) { while ($next <= $cap && isset($taken[$next])) $next++; }
                else { while (isset($taken[$next])) $next++; }
                // Prevent over-booking beyond capacity (when capacity known).
                if ($cap > 0 && $next > $cap) {
                    $response->send_json_error('No seats remaining for this trip.', 422);
                    return;
                }
                $seatNo = $next;
            }
            $id = $m->insert([
                'trip_id'        => (int) $input['trip_id'],
                'passenger_name' => trim($input['passenger_name']),
                'contact_no'     => $input['contact_no'] ?? null,
                'seat_no'        => $seatNo,
                'qr_code'        => $qr,
                'status'         => $input['status'] ?? 'pending',
                'scanned_at'     => null,
                'scanned_by'     => null,
            ]);
            $response->send_json_success($m->find($id), 'Ticket created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/tickets/{id}/scan  {scanned_by} */
    public function scan_ticket($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Ticket_model');
            $ticket = $m->find($id);
            if (!$ticket) { $response->send_not_found('Ticket not found'); return; }
            if (strtolower((string)($ticket['status'] ?? '')) === 'cancelled') {
                $response->send_json_error('This ticket was cancelled and cannot be boarded.', 422);
                return;
            }
            if (!empty($ticket['scanned_at'])) {
                $response->send_json_error('Ticket already scanned.', 422);
                return;
            }
            $input = $this->json_input();
            // Status enum is pending/boarded/cancelled - scanned means boarded.
            $data = [
                'scanned_at' => $this->now(),
                'scanned_by' => isset($input['scanned_by']) ? (int) $input['scanned_by'] : null,
                'status'     => 'boarded',
            ];
            $m->_update((int) $id, $data);
            // Auto-add to the trip's boarded count: keep the latest
            // passenger_counts in sync so Reports / Dispatch stay correct.
            try {
                $pcModel = $this->call->model('PassengerCount_model');
                $counts = $pcModel->_find_all_by('trip_id', (int) $ticket['trip_id']);
                if (is_array($counts) && !empty($counts)) {
                    usort($counts, function ($a, $b) { return (int)$b['id'] <=> (int)$a['id']; });
                    $latest = $counts[0];
                    $free = (int)($latest['seats_available'] ?? 0) - 1;
                    if ($free < 0) $free = 0;
                    $pcModel->_update((int) $latest['id'], [
                        'passengers_boarded' => (int)($latest['passengers_boarded'] ?? 0) + 1,
                        'seats_available' => $free,
                    ]);
                } else {
                    $trip = $this->call->model('Trip_model')->find((int) $ticket['trip_id']);
                    $cap = 0;
                    if (!empty($trip['vehicle_id'])) {
                        $veh = $this->call->model('Vehicle_model')->find((int) $trip['vehicle_id']);
                        $cap = isset($veh['capacity']) ? (int) $veh['capacity'] : 0;
                    }
                    $free = $cap > 0 ? max($cap - 1, 0) : 0;
                    $pcModel->insert([
                        'trip_id' => (int) $ticket['trip_id'],
                        'passengers_waiting' => 0,
                        'passengers_boarded' => 1,
                        'seats_available' => $free,
                        'recorded_by' => isset($input['scanned_by']) ? (int) $input['scanned_by'] : null,
                        'recorded_at' => $this->now(),
                    ]);
                }
            } catch (\Throwable $e) {}
            $response->send_json_success($m->find($id), 'Ticket boarded');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** POST /api/tickets/{id}/cancel - marks ticket cancelled and restores the seat. */
    public function cancel_ticket($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Ticket_model');
            $ticket = $m->find($id);
            if (!$ticket) { $response->send_not_found('Ticket not found'); return; }
            if (strtolower((string)($ticket['status'] ?? '')) === 'cancelled') {
                $response->send_json_success($m->find($id), 'Ticket already cancelled');
                return;
            }
            $wasBoarded = !empty($ticket['scanned_at']) || strtolower((string)($ticket['status'] ?? '')) === 'boarded';
            $m->_update((int) $id, ['status' => 'cancelled']);
            // Restore the seat in the latest passenger count for this trip,
            // if one exists, so Dispatch/Trips seats show an extra free seat.
            try {
                $pcModel = $this->call->model('PassengerCount_model');
                $counts = $pcModel->_find_all_by('trip_id', (int) $ticket['trip_id']);
                if (is_array($counts) && !empty($counts)) {
                    usort($counts, function ($a, $b) { return (int)$b['id'] <=> (int)$a['id']; });
                    $latest = $counts[0];
                    $free = (int)($latest['seats_available'] ?? 0) + 1;
                    $boarded = (int)($latest['passengers_boarded'] ?? 0) - ($wasBoarded ? 1 : 0);
                    if ($boarded < 0) $boarded = 0;
                    $pcModel->_update((int) $latest['id'], [
                        'seats_available' => $free,
                        'passengers_boarded' => $boarded,
                    ]);
                }
            } catch (\Throwable $e) {}
            $response->send_json_success($m->find($id), 'Ticket cancelled and seat restored');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_ticket($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Ticket_model');
            if (!$m->find($id)) { $response->send_not_found('Ticket not found'); return; }
            $m->_delete((int) $id);
            $response->send_json_success(null, 'Ticket deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Announcements (Voice/IVR) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function announcements() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_ann', 15, function () {
                return $this->call->model('Announcement_model')->all();
            });
            $response->send_json_success($data, 'Announcements retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_announcement() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['message'])) { $response->send_json_error('message is required', 422); return; }
            $m = $this->call->model('Announcement_model');
            $id = $m->insert([
                'message'    => substr(trim($input['message']), 0, 255),
                'category'   => $input['category'] ?? 'general',
                'voice'      => $input['voice'] ?? 'fil-PH',
                'is_spoken'  => isset($input['is_spoken']) ? (int) $input['is_spoken'] : 0,
                'spoken_at'  => $input['spoken_at'] ?? null,
                'created_by' => isset($input['created_by']) ? (int) $input['created_by'] : null,
            ]);
            $created = $m->find($id);

            // Notify every subscribed passenger. This runs after the row is
            // saved and is wrapped in its own try/catch: a push failure is
            // logged but must never stop the announcement from being created.
            try {
                $this->call->helper('onesignal');
                sendAnnouncementPush(
                    'Yellow Mega Van · Terminal Announcement',
                    $created['message'] ?? ($input['message'] ?? '')
                );
            } catch (\Throwable $pushError) {
                error_log('[OneSignal] announcement push error: ' . $pushError->getMessage());
            }

            $response->send_json_success($created, 'Announcement created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_announcement($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Announcement_model');
            if (!$m->find($id)) { $response->send_not_found('Announcement not found'); return; }
            $input = $this->json_input();
            $data = [];
            foreach (['message','category','voice','is_spoken','spoken_at','created_by'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            $m->_update((int) $id, $data);
            $response->send_json_success($m->find($id), 'Announcement updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_announcement($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Announcement_model');
            if (!$m->find($id)) { $response->send_not_found('Announcement not found'); return; }
            $m->_delete((int) $id);
            $response->send_json_success(null, 'Announcement deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Passenger Counts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function passenger_counts() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_pc', 10, function () {
                return $this->call->model('PassengerCount_model')->all();
            });
            $response->send_json_success($data, 'Passenger counts retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_passenger_count() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['trip_id'])) { $response->send_json_error('trip_id is required', 422); return; }
            $m = $this->call->model('PassengerCount_model');
            $id = $m->insert([
                'trip_id'              => (int) $input['trip_id'],
                'passengers_waiting'   => isset($input['passengers_waiting']) ? (int) $input['passengers_waiting'] : 0,
                'passengers_boarded'   => isset($input['passengers_boarded']) ? (int) $input['passengers_boarded'] : 0,
                'seats_available'      => isset($input['seats_available']) ? (int) $input['seats_available'] : 0,
                'recorded_by'          => isset($input['recorded_by']) ? (int) $input['recorded_by'] : null,
                'recorded_at'          => $input['recorded_at'] ?? $this->now(),
            ]);
            $response->send_json_success($m->find($id), 'Passenger count recorded', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Users â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function users() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_users', 30, function () {
                $rows = $this->call->model('User_model')->all() ?: [];
                foreach ((array) $rows as $i => $u) { unset($rows[$i]['password']); }
                return $rows;
            });
            $response->send_json_success($data, 'Users retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /**
     * Self-heal orphan driver users: every users row with role=driver
     * should have a drivers profile row (status active). Creates any
     * that are missing (e.g. accounts created before auto-sync).
     */
    private function ensure_driver_profiles(): void {
        try {
            $users = $this->call->model('User_model')->all();
            if (!is_array($users)) return;
            $drivers = $this->call->model('Driver_model')->all();
            $haveUser = [];
            if (is_array($drivers)) {
                foreach ($drivers as $d) {
                    if (!empty($d['user_id'])) $haveUser[(int) $d['user_id']] = true;
                }
            }
            $dm = $this->call->model('Driver_model');
            foreach ($users as $u) {
                if (strtolower((string)($u['role'] ?? '')) !== 'driver') continue;
                $uid = (int)($u['id'] ?? 0);
                if (!$uid || isset($haveUser[$uid])) continue;
                try {
                    $dm->insert([
                        'user_id'    => $uid,
                        'full_name'  => trim((string)($u['full_name'] ?? 'Driver')),
                        'license_no' => '',
                        'contact_no' => $u['contact_no'] ?? null,
                        'status'     => 'active',
                    ]);
                    $haveUser[$uid] = true;
                } catch (\Throwable $e) {
                    error_log('ensure_driver_profiles failed for user ' . $uid . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {}
    }

    public function store_user() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            foreach (['full_name','username','password','role'] as $f) {
                if (empty($input[$f])) { $response->send_json_error("{$f} is required", 422); return; }
            }
            // Only one admin account is allowed (seeded). New accounts
            // can only be dispatchers or drivers.
            $role = strtolower(trim((string) $input['role']));
            if ($role === 'admin') {
                $response->send_json_error('Only one admin account is allowed. Role cannot be set to admin.', 422);
                return;
            }
            if (!in_array($role, ['dispatcher', 'driver'], true)) {
                $response->send_json_error('Invalid role. Allowed: dispatcher, driver.', 422);
                return;
            }
            $m = $this->call->model('User_model');
            $id = $m->insert([
                'full_name'  => trim($input['full_name']),
                'username'   => trim($input['username']),
                'email'      => $input['email'] ?? null,
                'password'   => password_hash($input['password'], PASSWORD_BCRYPT),
                'role'       => $role,
                'contact_no' => $input['contact_no'] ?? null,
                'is_active'  => isset($input['is_active']) ? (int) $input['is_active'] : 1,
            ]);
            $user = $this->public_user($m->find($id));
            // Driver accounts automatically get a drivers profile row
            // saved at the same time, starting as 'active'.
            if ($role === 'driver') {
                try {
                    $dm = $this->call->model('Driver_model');
                    $did = $dm->insert([
                        'user_id'    => (int) $id,
                        'full_name'  => trim($input['full_name']),
                        // Empty string (not NULL): license_no is a
                        // non-null column, and the Users form has no
                        // license input.
                        'license_no' => !empty($input['license_no']) ? trim((string) $input['license_no']) : '',
                        'contact_no' => $input['contact_no'] ?? null,
                        'status'     => 'active',
                    ]);
                    $user['driver'] = $did ? $dm->find($did) : null;
                } catch (\Throwable $e) {
                    error_log('store_user driver insert failed: ' . $e->getMessage());
                }
            }
            $response->send_json_success($user, 'User created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function update_user($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('User_model');
            $existing = $m->find($id);
            if (!$existing) { $response->send_not_found('User not found'); return; }
            $isAdmin = strtolower((string)($existing['role'] ?? '')) === 'admin';
            $input = $this->json_input();
            // The admin account can only have its password reset.
            if ($isAdmin) {
                if (array_key_exists('is_active', $input)) {
                    $response->send_json_error('Admin account cannot be deactivated.', 422);
                    return;
                }
                if (array_key_exists('role', $input) && strtolower(trim((string) $input['role'])) !== 'admin') {
                    $response->send_json_error('Admin role cannot be changed.', 422);
                    return;
                }
            } elseif (isset($input['role']) && strtolower(trim((string) $input['role'])) === 'admin') {
                $response->send_json_error('Only one admin account is allowed. Role cannot be set to admin.', 422);
                return;
            }
            $data = [];
            foreach (['full_name','username','email','role','contact_no','is_active'] as $f) {
                if (array_key_exists($f, $input)) $data[$f] = $input[$f];
            }
            if (isset($data['role'])) $data['role'] = strtolower(trim((string) $data['role']));
            if (array_key_exists('password', $input) && $input['password'] !== '') {
                $data['password'] = password_hash($input['password'], PASSWORD_BCRYPT);
            }
            if (!$data) { $response->send_json_error('No fields to update', 422); return; }
            $m->_update((int) $id, $data);
            $response->send_json_success($this->public_user($m->find($id)), 'User updated');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function delete_user($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('User_model');
            $existing = $m->find($id);
            if (!$existing) { $response->send_not_found('User not found'); return; }
            if (strtolower((string)($existing['role'] ?? '')) === 'admin') {
                $response->send_json_error('Admin account cannot be deleted.', 422);
                return;
            }
            // Cascade: a deleted driver user also deletes its drivers
            // profile row(s).
            try {
                $dm = $this->call->model('Driver_model');
                $profiles = $dm->_find_all_by('user_id', (int) $id);
                if (is_array($profiles)) {
                    foreach ($profiles as $p) {
                        try { $dm->_delete((int) $p['id']); } catch (\Throwable $e2) {}
                    }
                }
            } catch (\Throwable $e) {
                error_log('delete_user driver cascade failed for user ' . $id . ': ' . $e->getMessage());
            }
            $m->_delete((int) $id);
            $response->send_json_success(null, 'User deleted');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Notifications (FCM stub) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function notifications() {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Notification_model');
            $userId = trim($_GET['user_id'] ?? '');
            $data = $userId !== '' ? $m->_find_all_by('user_id', (int) $userId) : $m->all();
            $response->send_json_success($data, 'Notifications retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_notification() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['title']) || empty($input['message'])) {
                $response->send_json_error('title and message are required', 422);
                return;
            }
            $m = $this->call->model('Notification_model');
            $id = $m->insert([
                'user_id'  => isset($input['user_id']) ? (int) $input['user_id'] : null,
                'trip_id'  => isset($input['trip_id']) ? (int) $input['trip_id'] : null,
                'type'     => $input['type'] ?? 'schedule',
                'title'    => substr(trim($input['title']), 0, 100),
                'message'  => substr(trim($input['message']), 0, 255),
                'payload'  => isset($input['payload']) ? (is_string($input['payload']) ? $input['payload'] : json_encode($input['payload'])) : null,
                'pushed'   => isset($input['pushed']) ? (int) $input['pushed'] : 0,
                'is_read'  => 0,
            ]);
            $response->send_json_success($m->find($id), 'Notification created', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function mark_notification_read($id) {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('Notification_model');
            if (!$m->find($id)) { $response->send_not_found('Notification not found'); return; }
            $m->_update((int) $id, ['is_read' => 1]);
            $response->send_json_success($m->find($id), 'Notification marked as read');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Device Tokens (FCM registration) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function device_tokens() {
        try {
            $response = load_class('response', 'kernel');
            $m = $this->call->model('DeviceToken_model');
            $userId = trim($_GET['user_id'] ?? '');
            $data = $userId !== '' ? $m->_find_all_by('user_id', (int) $userId) : $m->all();
            $response->send_json_success($data, 'Device tokens retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_device_token() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['token']) || empty($input['user_id'])) {
                $response->send_json_error('user_id and token are required', 422);
                return;
            }
            $m = $this->call->model('DeviceToken_model');
            // Upsert: deactivate old tokens for same user+token
            $existing = $m->_find_all_by('token', $input['token']);
            if (is_array($existing)) {
                foreach ($existing as $row) {
                    if ((int) $row['user_id'] === (int) $input['user_id']) {
                        $m->_update((int) $row['id'], ['is_active' => 1]);
                        $response->send_json_success($m->find($row['id']), 'Device token registered');
                        return;
                    }
                }
            }
            $id = $m->insert([
                'user_id'    => (int) $input['user_id'],
                'token'      => trim($input['token']),
                'platform'   => $input['platform'] ?? 'web',
                'user_agent' => substr((string) ($input['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
                'is_active'  => 1,
            ]);
            $response->send_json_success($m->find($id), 'Device token registered', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Schedule Overrides â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function schedule_overrides() {
        try {
            $response = load_class('response', 'kernel');
            $data = $this->cached_list('api_overrides', 30, function () {
                return $this->call->model('ScheduleOverride_model')->all();
            });
            $response->send_json_success($data, 'Schedule overrides retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    public function store_schedule_override() {
        try {
            $response = load_class('response', 'kernel');
            $input = $this->json_input();
            if (empty($input['trip_id']) || empty($input['new_departure'])) {
                $response->send_json_error('trip_id and new_departure are required', 422);
                return;
            }
            $tripModel = $this->call->model('Trip_model');
            $trip = $tripModel->find($input['trip_id']);
            if (!$trip) { $response->send_not_found('Trip not found'); return; }
            $m = $this->call->model('ScheduleOverride_model');
            $id = $m->insert([
                'trip_id'        => (int) $input['trip_id'],
                'old_departure'  => $trip['scheduled_departure'] ?? null,
                'new_departure'  => $input['new_departure'],
                'reason'         => substr((string) ($input['reason'] ?? ''), 0, 255),
                'overridden_by'  => isset($input['overridden_by']) ? (int) $input['overridden_by'] : null,
            ]);
            // Enforce new schedule on trip + notify driver
            $tripModel->_update((int) $trip['id'], ['scheduled_departure' => $input['new_departure']]);
            $driver = !empty($trip['driver_id']) ? $this->call->model('Driver_model')->find($trip['driver_id']) : null;
            if ($driver && !empty($driver['user_id'])) {
                $this->notify($driver['user_id'], (int) $trip['id'], 'schedule', 'Departure changed', 'Trip #' . $trip['id'] . ' new departure: ' . $input['new_departure'] . ($input['reason'] ? ' (' . $input['reason'] . ')' : ''), ['trip_id' => (int) $trip['id']]);
            }
            $response->send_json_success($m->find($id), 'Schedule override saved', 201);
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Passenger Status Screen (live board) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * GET /api/passenger-status
     * Joins trips + vehicles + drivers + routes + latest passenger_counts + queue.
     * Poll this endpoint every 10s from the Passenger Display view.
     */
    public function passenger_status() {
        try {
            $response = load_class('response', 'kernel');
            $cacheKey = 'api_passenger_status' . ((($_GET['include_cancelled'] ?? '') === '1') ? '_inc_cancelled' : '_live');
            $cached = $this->api_cache_get($cacheKey);
            if ($cached !== null) {
                $response->send_json_success($cached, 'Passenger status retrieved');
                return;
            }
            $trips = $this->call->model('Trip_model')->all() ?: [];
            $vehicles = $this->call->model('Vehicle_model')->all() ?: [];
            $drivers = $this->call->model('Driver_model')->all() ?: [];
            $routes = $this->call->model('Route_model')->all() ?: [];
            $counts = $this->call->model('PassengerCount_model')->all() ?: [];
            $queue = $this->call->model('DispatchQueue_model')->all() ?: [];

            $vById = $dById = $rById = [];
            foreach ((array) $vehicles as $v) $vById[(int) $v['id']] = $v;
            foreach ((array) $drivers as $d) $dById[(int) $d['id']] = $d;
            foreach ((array) $routes as $r) $rById[(int) $r['id']] = $r;

            $latestCountByTrip = [];
            foreach ((array) $counts as $c) {
                $tid = (int) $c['trip_id'];
                if (!isset($latestCountByTrip[$tid]) || (int) $c['id'] > (int) $latestCountByTrip[$tid]['id']) {
                    $latestCountByTrip[$tid] = $c;
                }
            }
            $queueByTrip = $queueByDriverVehicle = [];
            foreach ((array) $queue as $q) {
                if (!empty($q['trip_id'])) $queueByTrip[(int) $q['trip_id']] = $q;
                $key = (int) $q['driver_id'] . '-' . (int) $q['vehicle_id'];
                $queueByDriverVehicle[$key] = $q;
            }

            $out = [];
            foreach ((array) $trips as $t) {
                // Live board shows scheduled/departed + waiting queue; skip fully cancelled display unless needed
                $status = strtolower((string) ($t['status'] ?? ''));
                if ($status === 'cancelled' && ($_GET['include_cancelled'] ?? '') !== '1') {
                    continue;
                }
                $v = $vById[(int) ($t['vehicle_id'] ?? 0)] ?? null;
                $d = $dById[(int) ($t['driver_id'] ?? 0)] ?? null;
                $r = $rById[(int) ($t['route_id'] ?? 0)] ?? null;
                $pc = $latestCountByTrip[(int) $t['id']] ?? null;
                $q = $queueByTrip[(int) $t['id']] ?? null;
                if (!$q && $v && $d) {
                    $q = $queueByDriverVehicle[(int) $d['id'] . '-' . (int) $v['id']] ?? null;
                }
                $out[] = [
                    'trip_id'              => (int) $t['id'],
                    'scheduled_departure'  => $t['scheduled_departure'] ?? null,
                    'actual_departure'     => $t['actual_departure'] ?? null,
                    'trip_status'          => $t['status'] ?? null,
                    'plate_number'         => $v['plate_number'] ?? null,
                    'vehicle_model'        => $v['model'] ?? null,
                    'vehicle_capacity'     => isset($v['capacity']) ? (int) $v['capacity'] : null,
                    'vehicle_status'       => $v['status'] ?? null,
                    'driver_name'          => $d['full_name'] ?? null,
                    'driver_status'        => $d['status'] ?? null,
                    'origin'               => $r['origin'] ?? null,
                    'destination'          => $r['destination'] ?? null,
                    'fare'                 => $r['fare'] ?? null,
                    'estimated_minutes'    => isset($r['estimated_minutes']) ? (int) $r['estimated_minutes'] : null,
                    'passengers_waiting'   => isset($pc['passengers_waiting']) ? (int) $pc['passengers_waiting'] : null,
                    'passengers_boarded'   => isset($pc['passengers_boarded']) ? (int) $pc['passengers_boarded'] : null,
                    'seats_available'      => isset($pc['seats_available']) ? (int) $pc['seats_available'] : null,
                    'recorded_at'          => $pc['recorded_at'] ?? null,
                    'queue_position'       => isset($q['queue_position']) ? (int) $q['queue_position'] : null,
                    'queue_status'         => $q['status'] ?? null,
                ];
            }
            // Sort by scheduled departure (soonest first), then queue position
            usort($out, function ($a, $b) {
                $sa = strtotime($a['scheduled_departure'] ?: '9999-12-31');
                $sb = strtotime($b['scheduled_departure'] ?: '9999-12-31');
                if ($sa === $sb) return ($a['queue_position'] ?? 999) <=> ($b['queue_position'] ?? 999);
                return $sa <=> $sb;
            });
            $this->api_cache_put($cacheKey, $out, 5);
            $response->send_json_success($out, 'Passenger status retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    // â”€â”€ Reports â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /** GET /api/reports/trip-history */
    public function report_trip_history() {
        try {
            $response = load_class('response', 'kernel');
            $cached = $this->api_cache_get('api_report_trip_history');
            if ($cached !== null) {
                $response->send_json_success($cached, 'Trip history retrieved');
                return;
            }
            $trips = (array) ($this->call->model('Trip_model')->all() ?: []);
            $vehicles = $this->call->model('Vehicle_model')->all() ?: [];
            $drivers = $this->call->model('Driver_model')->all() ?: [];
            $routes = $this->call->model('Route_model')->all() ?: [];
            $vById = $dById = $rById = [];
            foreach ((array) $vehicles as $v) $vById[(int) $v['id']] = $v;
            foreach ((array) $drivers as $d) $dById[(int) $d['id']] = $d;
            foreach ((array) $routes as $r) $rById[(int) $r['id']] = $r;
            $out = [];
            foreach ($trips as $t) {
                $status = strtolower((string) ($t['status'] ?? ''));
                if (!in_array($status, ['completed','departed','cancelled'], true)) continue;
                $delayMin = null;
                if (!empty($t['actual_departure']) && !empty($t['scheduled_departure'])) {
                    $delayMin = (int) round((strtotime($t['actual_departure']) - strtotime($t['scheduled_departure'])) / 60);
                }
                $out[] = [
                    'trip_id'             => (int) $t['id'],
                    'status'              => $t['status'],
                    'scheduled_departure' => $t['scheduled_departure'],
                    'actual_departure'    => $t['actual_departure'],
                    'delay_minutes'       => $delayMin,
                    'plate_number'        => $vById[(int) $t['vehicle_id']]['plate_number'] ?? null,
                    'driver_name'         => $dById[(int) $t['driver_id']]['full_name'] ?? null,
                    'destination'         => $rById[(int) $t['route_id']]['destination'] ?? null,
                    'origin'              => $rById[(int) $t['route_id']]['origin'] ?? null,
                    'created_at'          => $t['created_at'] ?? null,
                ];
            }
            usort($out, function ($a, $b) {
                return strtotime($b['scheduled_departure'] ?: '0') <=> strtotime($a['scheduled_departure'] ?: '0');
            });
            $this->api_cache_put('api_report_trip_history', $out, 15);
            $response->send_json_success($out, 'Trip history retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** GET /api/reports/adherence */
    public function report_adherence() {
        try {
            $response = load_class('response', 'kernel');
            $cachedAd = $this->api_cache_get('api_adherence');
            if ($cachedAd !== null) {
                $response->send_json_success($cachedAd, 'Schedule adherence retrieved');
                return;
            }
            $trips = (array) ($this->call->model('Trip_model')->all() ?: []);
            $rows = [];
            $onTime = $late = $early = $noActual = 0;
            foreach ($trips as $t) {
                if (empty($t['scheduled_departure']) || empty($t['actual_departure'])) {
                    $noActual++;
                    continue;
                }
                $delay = (int) round((strtotime($t['actual_departure']) - strtotime($t['scheduled_departure'])) / 60);
                // On-time window: -5 to +15 minutes
                if ($delay >= -5 && $delay <= 15) $onTime++;
                elseif ($delay > 15) $late++;
                else $early++;
                $rows[] = ['trip_id' => (int) $t['id'], 'status' => $t['status'], 'scheduled' => $t['scheduled_departure'], 'actual' => $t['actual_departure'], 'delay_minutes' => $delay];
            }
            $totalWithActual = $onTime + $late + $early;
            $summary = [
                'on_time'       => $onTime,
                'late'          => $late,
                'early'         => $early,
                'no_actual'     => $noActual,
                'total_with_actual' => $totalWithActual,
                'adherence_percent' => $totalWithActual > 0 ? round($onTime / $totalWithActual * 100, 1) : null,
            ];
            $order = ['summary' => $summary, 'rows' => $rows];
            $this->api_cache_put('api_adherence', $order, 15);
            $response->send_json_success($order, 'Schedule adherence retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }

    /** GET /api/reports/daily-volume */
    public function report_daily_volume() {
        try {
            $response = load_class('response', 'kernel');
            $cachedVol = $this->api_cache_get('api_daily_volume');
            if ($cachedVol !== null) {
                $response->send_json_success($cachedVol, 'Daily volume retrieved');
                return;
            }
            $counts = (array) ($this->call->model('PassengerCount_model')->all() ?: []);
            $byDate = [];
            foreach ($counts as $c) {
                $date = substr((string) ($c['recorded_at'] ?? ''), 0, 10);
                if ($date === '' || $date === '0000-00-00') continue;
                if (!isset($byDate[$date])) {
                    $byDate[$date] = ['date' => $date, 'boarded' => 0, 'waiting' => 0, 'records' => 0];
                }
                $byDate[$date]['boarded'] += (int) ($c['passengers_boarded'] ?? 0);
                $byDate[$date]['waiting'] += (int) ($c['passengers_waiting'] ?? 0);
                $byDate[$date]['records']++;
            }
            $rows = array_values($byDate);
            usort($rows, function ($a, $b) { return strcmp($a['date'], $b['date']); });
            $this->api_cache_put('api_daily_volume', $rows, 15);
            $response->send_json_success($rows, 'Daily volume retrieved');
        } catch (\Throwable $e) {
            load_class('response', 'kernel')->send_server_error($e->getMessage());
        }
    }
}


