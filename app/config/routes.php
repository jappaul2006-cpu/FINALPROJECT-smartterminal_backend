<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

// ── Smart Terminal API Routes ──
// Auth (uses .env JWT_SECRET + REFRESH_TOKEN_KEY via LavaLust Api helper)
$router->post('/api/login', 'SmartApi::login');
$router->post('/api/refresh', 'SmartApi::refresh');
$router->post('/api/logout', 'SmartApi::logout');
$router->get('/api/me', 'SmartApi::me');

// Dashboard
$router->get('/api/dashboard', 'SmartApi::dashboard');

// Passenger live board + Driver trips + Reports
$router->get('/api/passenger-status', 'SmartApi::passenger_status');
$router->get('/api/drivers/{id}/trips', 'SmartApi::driver_trips');
$router->get('/api/reports/trip-history', 'SmartApi::report_trip_history');
$router->get('/api/reports/adherence', 'SmartApi::report_adherence');
$router->get('/api/reports/daily-volume', 'SmartApi::report_daily_volume');

// Routes CRUD
$router->get('/api/routes', 'SmartApi::routes');
$router->get('/api/routes/{id}', 'SmartApi::route');
$router->post('/api/routes', 'SmartApi::store_route');
$router->put('/api/routes/{id}', 'SmartApi::update_route');
$router->patch('/api/routes/{id}', 'SmartApi::update_route');
$router->delete('/api/routes/{id}', 'SmartApi::delete_route');

// Trips CRUD + status actions
$router->get('/api/trips', 'SmartApi::trips');
$router->get('/api/trips/{id}', 'SmartApi::trip');
$router->post('/api/trips', 'SmartApi::store_trip');
$router->put('/api/trips/{id}', 'SmartApi::update_trip');
$router->patch('/api/trips/{id}', 'SmartApi::update_trip');
$router->delete('/api/trips/{id}', 'SmartApi::delete_trip');
$router->post('/api/trips/{id}/boarding', 'SmartApi::boarding_trip');
$router->post('/api/trips/{id}/depart', 'SmartApi::depart_trip');
$router->post('/api/trips/{id}/complete', 'SmartApi::complete_trip');
$router->post('/api/trips/{id}/cancel', 'SmartApi::cancel_trip');

// Vehicles CRUD
$router->get('/api/vehicles', 'SmartApi::vehicles');
$router->get('/api/vehicles/{id}', 'SmartApi::vehicle');
$router->post('/api/vehicles', 'SmartApi::store_vehicle');
$router->put('/api/vehicles/{id}', 'SmartApi::update_vehicle');
$router->patch('/api/vehicles/{id}', 'SmartApi::update_vehicle');
$router->delete('/api/vehicles/{id}', 'SmartApi::delete_vehicle');

// Drivers CRUD
$router->get('/api/drivers', 'SmartApi::drivers');
$router->post('/api/drivers', 'SmartApi::store_driver');
$router->put('/api/drivers/{id}', 'SmartApi::update_driver');
$router->patch('/api/drivers/{id}', 'SmartApi::update_driver');
$router->delete('/api/drivers/{id}', 'SmartApi::delete_driver');

// Dispatch queue CRUD
$router->get('/api/dispatch-queue', 'SmartApi::dispatch_queue');
$router->post('/api/dispatch-queue', 'SmartApi::store_dispatch_queue');
$router->put('/api/dispatch-queue/{id}', 'SmartApi::update_dispatch_queue');
$router->patch('/api/dispatch-queue/{id}', 'SmartApi::update_dispatch_queue');
$router->delete('/api/dispatch-queue/{id}', 'SmartApi::delete_dispatch_queue');

// Tickets + scan
$router->get('/api/tickets', 'SmartApi::tickets');
$router->post('/api/tickets', 'SmartApi::store_ticket');
$router->post('/api/tickets/{id}/scan', 'SmartApi::scan_ticket');
$router->post('/api/tickets/{id}/cancel', 'SmartApi::cancel_ticket');
$router->delete('/api/tickets/{id}', 'SmartApi::delete_ticket');

// Announcements
$router->get('/api/announcements', 'SmartApi::announcements');
$router->post('/api/announcements', 'SmartApi::store_announcement');
$router->put('/api/announcements/{id}', 'SmartApi::update_announcement');
$router->patch('/api/announcements/{id}', 'SmartApi::update_announcement');
$router->delete('/api/announcements/{id}', 'SmartApi::delete_announcement');

// Passenger counts
$router->get('/api/passenger-counts', 'SmartApi::passenger_counts');
$router->post('/api/passenger-counts', 'SmartApi::store_passenger_count');

// Users
$router->get('/api/users', 'SmartApi::users');
$router->post('/api/users', 'SmartApi::store_user');
$router->put('/api/users/{id}', 'SmartApi::update_user');
$router->patch('/api/users/{id}', 'SmartApi::update_user');
$router->delete('/api/users/{id}', 'SmartApi::delete_user');

// Notifications (FCM stub)
$router->get('/api/notifications', 'SmartApi::notifications');
$router->post('/api/notifications', 'SmartApi::store_notification');
$router->post('/api/notifications/{id}/read', 'SmartApi::mark_notification_read');

// Device tokens (FCM registration)
$router->get('/api/device-tokens', 'SmartApi::device_tokens');
$router->post('/api/device-tokens', 'SmartApi::store_device_token');

// Schedule overrides
$router->get('/api/schedule-overrides', 'SmartApi::schedule_overrides');
$router->post('/api/schedule-overrides', 'SmartApi::store_schedule_override');
