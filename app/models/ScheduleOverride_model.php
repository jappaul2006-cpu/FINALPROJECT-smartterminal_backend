<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ScheduleOverride_model extends Model {
    protected $table = 'schedule_overrides';
    protected $fillable = ['trip_id', 'old_departure', 'new_departure', 'reason', 'overridden_by'];
    protected $primary_key = 'id';
}
