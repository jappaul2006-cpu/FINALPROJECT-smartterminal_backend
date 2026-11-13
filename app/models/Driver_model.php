<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Driver_model extends Model {
    protected $table = 'drivers';
    protected $fillable = ['user_id', 'full_name', 'license_no', 'contact_no', 'status'];
    protected $primary_key = 'id';
}
