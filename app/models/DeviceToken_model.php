<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DeviceToken_model extends Model {
    protected $table = 'device_tokens';
    protected $fillable = ['user_id', 'token', 'platform', 'user_agent', 'is_active'];
    protected $primary_key = 'id';
}
