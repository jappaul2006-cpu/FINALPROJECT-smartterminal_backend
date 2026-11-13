<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Notification_model extends Model {
    protected $table = 'notifications';
    protected $fillable = ['user_id', 'trip_id', 'type', 'title', 'message', 'payload', 'pushed', 'is_read'];
    protected $primary_key = 'id';
}
