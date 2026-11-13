<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Announcement_model extends Model {
    protected $table = 'announcements';
    protected $fillable = ['message', 'category', 'voice', 'is_spoken', 'spoken_at', 'created_by'];
    protected $primary_key = 'id';
}
