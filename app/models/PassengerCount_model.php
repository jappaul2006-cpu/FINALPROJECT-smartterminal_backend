<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PassengerCount_model extends Model {
    protected $table = 'passenger_counts';
    protected $fillable = ['trip_id', 'passengers_waiting', 'passengers_boarded', 'seats_available', 'recorded_by', 'recorded_at'];
    protected $primary_key = 'id';
}
