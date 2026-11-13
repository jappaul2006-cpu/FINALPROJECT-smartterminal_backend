<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Route_model extends Model {
    protected $table = 'routes';
    protected $fillable = ['origin', 'destination', 'estimated_minutes', 'fare', 'is_active'];
    protected $primary_key = 'id';
}
