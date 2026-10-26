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

$router->get('/', 'Auth::login');
$router->get('auth/login', 'Auth::login');
$router->post('auth/authenticate', 'Auth::authenticate');
$router->post('auth/logout', 'Auth::logout');

$router->get('admin', 'Admin::index');
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

$router->post('api/auth/login', 'Api_auth::login');
$router->post('api/auth/logout', 'Api_auth::logout');
$router->get('api/auth/me', 'Api_auth::me');

$router->get('api/admin/dashboard', 'Api_admin::dashboard');
$router->get('api/admin/tables', 'Api_admin::tables');
$router->get('api/admin/tables/{table}', 'Api_admin::table');
$router->get('api/admin/tables/{table}/options', 'Api_admin::options');
$router->get('api/admin/tables/{table}/{id}', 'Api_admin::show');
$router->post('api/admin/tables/{table}', 'Api_admin::store');
$router->put('api/admin/tables/{table}/{id}', 'Api_admin::update');
$router->post('api/admin/tables/{table}/update/{id}', 'Api_admin::update');
$router->delete('api/admin/tables/{table}/{id}', 'Api_admin::delete');
$router->post('api/admin/tables/{table}/delete/{id}', 'Api_admin::delete');

$router->get('api/categories', 'Api_category::index');
$router->get('api/categories/{id}', 'Api_category::show');
$router->post('api/categories', 'Api_category::store');
$router->put('api/categories/{id}', 'Api_category::update');
$router->post('api/categories/update/{id}', 'Api_category::update');
$router->delete('api/categories/{id}', 'Api_category::delete');
$router->post('api/categories/delete/{id}', 'Api_category::delete');





// Resident Authentication API
$router->post('api/auth/register', 'Api_auth::register');

// Barangay dropdown API
$router->get('api/barangays', 'Api_barangays::index');


// Resident Reports API
$router->get('api/reports/community', 'Api_reports::community');
$router->get('api/map/reports', 'Api_map::reports');
$router->get('api/reports', 'Api_reports::index');
$router->post('api/reports', 'Api_reports::store');
$router->get('api/reports/{id}', 'Api_reports::show');
$router->put('api/reports/{id}', 'Api_reports::update');
$router->post('api/reports/update/{id}', 'Api_reports::update');

// Hazard categories and stations dropdowns
$router->get('api/hazard-categories', 'Api_reports::categories');
$router->get('api/stations', 'Api_reports::stations');