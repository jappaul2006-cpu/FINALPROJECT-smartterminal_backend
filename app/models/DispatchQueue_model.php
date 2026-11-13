<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DispatchQueue_model extends Model {
    protected $table = 'dispatch_queue';
    protected $fillable = ['vehicle_id', 'driver_id', 'route_id', 'trip_id', 'queue_position', 'status', 'queued_at', 'departed_at'];
    protected $primary_key = 'id';
}
