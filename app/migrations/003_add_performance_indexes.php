<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Add_performance_indexes {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    /**
     * Add indexes that speed up the admin hot paths:
     *  - trips(status, scheduled_departure) for Dashboard / Trips / Reports
     *  - dispatch_queue(status, queue_position) for Dispatch Queue
     *  - vehicles(status), drivers(status) for Fleet / Dashboard counts
     *  - tickets(trip_id), passenger_counts(trip_id, recorded_at)
     * On a remote Aiven MySQL each unindexed COUNT(*) / WHERE is a
     * full scan, so cold GETs were ~0.5-1s per query.
     * Safe to re-run: duplicate-key errors are swallowed.
     */
    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('trips')) return;

        $stmts = [
            "CREATE INDEX idx_trips_status_sched ON trips (status, scheduled_departure)",
            "CREATE INDEX idx_trips_vehicle ON trips (vehicle_id)",
            "CREATE INDEX idx_trips_driver ON trips (driver_id)",
            "CREATE INDEX idx_trips_route ON trips (route_id)",
            "CREATE INDEX idx_trips_actual ON trips (actual_departure)",
        ];
        if ($this->_lava->dbforge->table_exists('vehicles')) {
            $stmts[] = "CREATE INDEX idx_vehicles_status ON vehicles (status)";
        }
        if ($this->_lava->dbforge->table_exists('drivers')) {
            $stmts[] = "CREATE INDEX idx_drivers_status ON drivers (status)";
            $stmts[] = "CREATE INDEX idx_drivers_user ON drivers (user_id)";
        }
        if ($this->_lava->dbforge->table_exists('dispatch_queue')) {
            $stmts[] = "CREATE INDEX idx_dq_status_pos ON dispatch_queue (status, queue_position)";
            $stmts[] = "CREATE INDEX idx_dq_trip ON dispatch_queue (trip_id)";
            $stmts[] = "CREATE INDEX idx_dq_driver ON dispatch_queue (driver_id)";
            $stmts[] = "CREATE INDEX idx_dq_vehicle ON dispatch_queue (vehicle_id)";
        }
        if ($this->_lava->dbforge->table_exists('tickets')) {
            $stmts[] = "CREATE INDEX idx_tickets_trip ON tickets (trip_id)";
            $stmts[] = "CREATE INDEX idx_tickets_qr ON tickets (qr_code)";
            $stmts[] = "CREATE INDEX idx_tickets_status ON tickets (status)";
        }
        if ($this->_lava->dbforge->table_exists('passenger_counts')) {
            $stmts[] = "CREATE INDEX idx_pc_trip_recorded ON passenger_counts (trip_id, recorded_at)";
        }
        if ($this->_lava->dbforge->table_exists('schedule_overrides')) {
            $stmts[] = "CREATE INDEX idx_overrides_trip ON schedule_overrides (trip_id)";
            $stmts[] = "CREATE INDEX idx_overrides_created ON schedule_overrides (created_at)";
        }
        if ($this->_lava->dbforge->table_exists('announcements')) {
            $stmts[] = "CREATE INDEX idx_ann_created ON announcements (created_at)";
        }
        if ($this->_lava->dbforge->table_exists('users')) {
            $stmts[] = "CREATE INDEX idx_users_role_active ON users (role, is_active)";
        }

        foreach ($stmts as $sql) {
            try {
                $stmt = $this->_lava->db->raw($sql, []);
                // Fetch forces execution for CREATE INDEX in some drivers.
                if ($stmt) $stmt->fetch();
            } catch (\Throwable $e) {
                // Duplicate / unsupported index — ignore (safe to re-run).
            }
        }
    }

    public function down()
    {
        // Intentionally leave indexes in place (dropping them would slow queries again).
    }
}
