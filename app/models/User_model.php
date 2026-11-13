<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model {
    protected $table = 'users';
    protected $fillable = ['full_name', 'username', 'email', 'password', 'role', 'contact_no', 'is_active', 'last_login_at'];
    protected $primary_key = 'id';
}
