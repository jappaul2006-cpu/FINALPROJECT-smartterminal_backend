<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Vehicle_model extends Model {
    protected $table = 'vehicles';
    protected $fillable = ['plate_number', 'model', 'capacity', 'status'];
    protected $primary_key = 'id';
}
