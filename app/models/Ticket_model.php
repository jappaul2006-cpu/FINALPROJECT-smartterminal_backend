<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Ticket_model extends Model {
    protected $table = 'tickets';
    protected $fillable = ['trip_id', 'passenger_name', 'contact_no', 'seat_no', 'qr_code', 'status', 'scanned_at', 'scanned_by'];
    protected $primary_key = 'id';
}
