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

$router->add_global_middleware('cors');

$router->get('/', 'Auth::login');
$router->get('auth/login', 'Auth::login');
$router->post('auth/authenticate', 'Auth::authenticate');
$router->post('auth/logout', 'Auth::logout');

$router->get('admin', 'Admin::index');
$router->get('admin/reports/hazards', 'AdminReportController::hazard_pdf');
$router->get('admin/tables/{table}', 'Admin::table');
$router->get('admin/tables/{table}/create', 'Admin::create');
$router->post('admin/tables/{table}/store', 'Admin::store');
$router->get('admin/tables/{table}/edit/{id}', 'Admin::edit');
$router->post('admin/tables/{table}/update/{id}', 'Admin::update');
$router->post('admin/tables/{table}/delete/{id}', 'Admin::delete');

$router->get('category', 'Category::index');
$router->get('category/create', 'Category::create');
$router->post('category/store', 'Category::store');
$router->get('category/edit/{id}', 'Category::edit');
$router->post('category/update/{id}', 'Category::update');
$router->post('category/delete/{id}', 'Category::delete');

$router->post('api/auth/logout', 'Api_auth::logout');
$router->get('api/auth/me', 'Api_auth::me')->middleware(['rate_limit', 'auth', 'active_account']);

$router->group([
    'prefix' => 'api/admin',
    'middleware' => ['rate_limit', 'auth', 'active_account', 'admin'],
], function ($router) {
    $router->get('reports/hazards', 'AdminReportController::hazard_pdf');
    $router->get('reports/options', 'AdminReportController::report_options');
    $router->get('reports', 'AdminReportController::report_pdf');
    $router->get('dashboard', 'Api_admin::dashboard');
    $router->get('barangays/incident-counts', 'Api_admin::barangay_incident_counts');
    $router->get('tables', 'Api_admin::tables');
    $router->get('tables/{table}', 'Api_admin::table');
    $router->get('tables/{table}/options', 'Api_admin::options');
    $router->get('tables/{table}/{id}', 'Api_admin::show');
    $router->post('tables/{table}', 'Api_admin::store');
    $router->put('tables/{table}/{id}', 'Api_admin::update');
    $router->post('tables/{table}/update/{id}', 'Api_admin::update');
    $router->delete('tables/{table}/{id}', 'Api_admin::delete');
    $router->post('tables/{table}/delete/{id}', 'Api_admin::delete');
    $router->post('announcements', 'Api_notifications::announce');
    $router->post('users/{id}/warning-strike', 'Api_admin_moderation::issueWarningStrike');
    $router->post('users/{id}/suspension', 'Api_admin_moderation::setSuspension');
    $router->post('reports/{id}/resolve', 'Api_admin_moderation::resolveReport');
    $router->get('external-apis', 'ExternalApiController::adminApis');
    $router->post('external-apis/{key}/sync', 'ExternalApiController::syncApi');
    $router->patch('external-apis/{key}/config', 'ExternalApiController::updateConfig');
    $router->post('external-apis/{key}/config', 'ExternalApiController::updateConfig');
    $router->patch('external-events/{id}/visibility', 'ExternalApiController::toggleVisibility');
    $router->post('external-events/{id}/visibility', 'ExternalApiController::toggleVisibility');
    $router->get('system-apis', 'SystemApiController::index');
    $router->post('system-apis', 'SystemApiController::create');
    $router->put('system-apis/{id}', 'SystemApiController::update');
    $router->post('system-apis/{id}/test', 'SystemApiController::test');
    $router->post('system-apis/{id}/reset', 'SystemApiController::resetCache');
    $router->delete('system-apis/{id}', 'SystemApiController::delete');
});

$router->get('api/categories', 'Api_category::index');
$router->get('api/categories/{id}', 'Api_category::show');
$router->post('api/categories', 'Api_category::store')->middleware(['rate_limit', 'auth', 'active_account', 'admin']);
$router->put('api/categories/{id}', 'Api_category::update')->middleware(['rate_limit', 'auth', 'active_account', 'admin']);
$router->post('api/categories/update/{id}', 'Api_category::update')->middleware(['rate_limit', 'auth', 'active_account', 'admin']);
$router->delete('api/categories/{id}', 'Api_category::delete')->middleware(['rate_limit', 'auth', 'active_account', 'admin']);
$router->post('api/categories/delete/{id}', 'Api_category::delete')->middleware(['rate_limit', 'auth', 'active_account', 'admin']);





// Resident Authentication API
$router->post('api/auth/login', 'Api_auth::login')->middleware('rate_limit');
$router->post('api/auth/register', 'Api_auth::register')->middleware('rate_limit');
$router->post('api/auth/send-registration-verification', 'Api_auth::sendRegistrationVerification')->middleware('rate_limit');
$router->post('api/auth/verify-registration-otp', 'Api_auth::verifyRegistrationOtp')->middleware('rate_limit');
$router->post('api/auth/verify-email', 'Api_auth::verifyEmail')->middleware('rate_limit');
$router->post('api/auth/resend-verification', 'Api_auth::resendVerificationEmail')->middleware('rate_limit');
$router->post('api/auth/request-password-reset', 'Api_auth::requestPasswordReset')->middleware('rate_limit');
$router->post('api/auth/reset-password', 'Api_auth::resetPassword')->middleware('rate_limit');

// Barangay dropdown API
$router->get('api/barangays', 'Api_barangays::index');
$router->get('api/location-suggestions', 'Api_barangays::suggestions');


// Resident Reports API
$router->get('api/media/evidence/{filename}', 'Api_media::evidence');
$router->get('api/weather/calapan', 'Api_weather::calapan')->middleware('rate_limit');
$router->get('api/external/resident-feed', 'ExternalApiController::residentFeed')->middleware('rate_limit');
$router->get('api/map/reports', 'Api_map::reports')->middleware('rate_limit');

// Hazard categories and stations dropdowns
$router->group([
    'prefix' => 'api',
    'middleware' => ['rate_limit', 'auth', 'active_account'],
], function ($router) {
    $router->get('notifications', 'Api_notifications::index');
    $router->post('notifications/{id}/read', 'Api_notifications::markRead');
    $router->get('reports/community', 'Api_reports::community');
    $router->get('reports/community-map', 'Api_reports::communityMap');
    $router->get('reports', 'Api_reports::index');
    $router->post('reports', 'Api_reports::store');
    $router->post('reports/{id}/vote', 'Api_reports::vote');
    $router->get('reports/{id}', 'Api_reports::show');
    $router->put('reports/{id}', 'Api_reports::update');
    $router->post('reports/update/{id}', 'Api_reports::update');
    $router->get('hazard-categories', 'Api_reports::categories');
    $router->get('stations', 'Api_reports::stations');
});