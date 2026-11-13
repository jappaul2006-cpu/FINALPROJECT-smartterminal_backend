<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Trip_model extends Model {
    protected $table = 'trips';
    protected $fillable = ['vehicle_id', 'driver_id', 'route_id', 'scheduled_departure', 'actual_departure', 'status', 'created_by'];
    protected $primary_key = 'id';
}
