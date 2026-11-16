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

/**
* ------------------------------------------------------
*  Class Error
* ------------------------------------------------------
 */
class Errors
{
	/**
	 * Show 404 Page Not Found
	 *
	 * @param string $heading
	 * @param string $message
	 * @param string $template
	 * @return void
	 */
	public function show_404($heading, $message, $template)
	{
		$template = ! empty($template) ? $template : 'error_404';
		$heading =  ! empty($heading) ? $heading : '404 Page Not Found';
		$message =  ! empty($message) ? $message : 'The page you requested was not found.';
		$this->show_error($heading, $message, $template, 404);
	}

	/**
	 * Show error for debugging
	 *
	 * @param string $heading
	 * @param string $message
	 * @param string $template
	 * @param integer $code
	 * @return void
	 */
	public function show_error($heading, $message, $template, $code = 500)
	{
		$template_path = config_item('error_view_path');
		if (empty($template_path))
		{
			$template_path = APP_DIR . 'views/errors/';
		}

		http_response_code($code);
		require_once($template_path.$template.'.php');
		exit();
	}

	/**
	 * Show Exception error for debugging
	 *
	 * @param object $exception
	 * @return void
	 */
	public function show_exception($exception)
	{
		$template_path = config_item('error_view_path');
		if (empty($template_path))
		{
			$template_path = APP_DIR.'views/errors/';
		}

		$message = $exception->getMessage();
		if (empty($message))
		{
			$message = '(null)';
		}

		require_once($template_path.'error_exception.php');
		exit();
	}

	/**
	 * Show PHP error for debugging
	 *
	 * @param string $severity
	 * @param string $message
	 * @param string $filepath
	 * @param string $line
	 * @return void
	 */
	public function show_php_error($severity, $message, $filepath, $line)
	{
		$template_path = config_item('error_view_path');
		if (empty($template_path))
		{
			$template_path = APP_DIR.'views/errors/';
		}
		require_once($template_path.'error_php.php');
		exit();
	}

	/**
	 * Show Database Error
	 *
	 * @param string $message
	 * @param string $sql
	 * @param array $bindings
	 * @param object $exception
	 * @param string $template
	 * @return void
	 */
	public function show_database_error($message, $sql = '', $bindings = [], $exception = null, $template = 'error_db')
	{
		error_log('Database operation failed: ' . $message);
		http_response_code(500);
		
		if (config_item('environment') !== 'development') {
			$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
			$is_api_request = $request_path === '/api' || strpos((string) $request_path, '/api/') === 0;
			if ($is_api_request) {
				if (!headers_sent()) {
					header('Content-Type: application/json; charset=utf-8');
				}
				$client_message = $this->database_error_hint($message, $exception);
				echo json_encode([
					'success' => false,
					'error' => $client_message,
					'message' => $client_message,
				]);
			} else {
				if (!headers_sent()) {
					header('Content-Type: text/plain; charset=utf-8');
				}
				echo 'Database service is unavailable.';
			}
			exit();
		}

		$template_path = config_item('error_view_path');
		if (empty($template_path)) {
			$template_path = APP_DIR . 'views/errors/';
		}

		$bindings_json = !empty($bindings) ? json_encode($bindings, JSON_PRETTY_PRINT) : 'None';
		$error_file = $exception ? $exception->getFile() : 'Unknown';
		$error_line = $exception ? $exception->getLine() : 'Unknown';
		$error_trace = $exception ? $exception->getTraceAsString() : 'No trace available';

		$exception_message = $message;
		$query = $sql;
		$bindings_data = $bindings_json;
		$file = $error_file;
		$line = $error_line;
		$trace = $error_trace;

		require_once($template_path . $template . '.php');
		exit();
	}

	private function database_error_hint($message, $exception)
	{
		$driver_code = 0;
		$sql_state = '';
		if ($exception instanceof PDOException) {
			$error_info = $exception->errorInfo ?? [];
			$driver_code = (int) ($error_info[1] ?? 0);
			$sql_state = (string) ($error_info[0] ?? $exception->getCode());
		}

		if ($driver_code === 2026 || stripos((string) $message, 'ssl') !== false
			|| stripos((string) $message, 'tls') !== false
			|| stripos((string) $message, 'certificate') !== false) {
			return 'Database TLS verification failed. Check that the Aiven CA Secret File is mounted and DB_SSL_CA points to it.';
		}
		if ($driver_code === 1045 || $driver_code === 1044 || $sql_state === '28000') {
			return 'Database authentication failed. Verify the Render DB_USERNAME (or DB_USER) and DB_PASSWORD values.';
		}
		if (in_array($driver_code, [2002, 2003, 2005, 2006, 2013], true)) {
			return 'Could not reach Aiven MySQL. Check Render DB_HOST, DB_PORT, Aiven service status, and network access.';
		}
		if ($driver_code === 1049) {
			return 'The configured database name was not found. Verify Render DB_NAME.';
		}
		if (in_array($driver_code, [1054, 1146], true)
			|| $sql_state === '42S02' || $sql_state === '42S22') {
			return 'The Aiven database schema does not match the application. Check the required migrations.';
		}

		return 'Database service is unavailable. Check the Render backend logs for the database failure category.';
	}
}