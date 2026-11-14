<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'ExternalHazardService.php';

class ExternalApiController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function residentFeed()
    {
        try {
            $service = new ExternalHazardService();
            api_response($service->getResidentFeed());
        } catch (Throwable $exception) {
            error_log('External resident feed failed: ' . $exception->getMessage());
            api_error('Unable to load the external hazard feed.', 500);
        }
    }

    public function adminApis()
    {
        $token = require_api_auth();
        if (strtolower((string) ($token['role_name'] ?? '')) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }

        try {
            $service = new ExternalHazardService();
            api_response($service->getAdminSummary());
        } catch (Throwable $exception) {
            error_log('External admin APIs failed: ' . $exception->getMessage());
            api_error('Unable to load external API status.', 500);
        }
    }

    public function syncApi(string $key)
    {
        $token = require_api_auth();
        if (strtolower((string) ($token['role_name'] ?? '')) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }

        try {
            $service = new ExternalHazardService();
            api_response($service->sync($key), 200, 'External API sync completed');
        } catch (InvalidArgumentException $exception) {
            api_error($exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('External API sync failed: ' . $exception->getMessage());
            api_error('Unable to sync this external API.', 500);
        }
    }

    public function updateConfig(string $key)
    {
        $token = require_api_auth();
        if (strtolower((string) ($token['role_name'] ?? '')) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }

        try {
            $service = new ExternalHazardService();
            $payload = get_request_input();
            api_response($service->updateConfig($key, is_array($payload) ? $payload : []), 200, 'External API configuration updated');
        } catch (Throwable $exception) {
            error_log('External API config update failed: ' . $exception->getMessage());
            api_error('Unable to update external API configuration.', 500);
        }
    }

    public function toggleVisibility(int $id)
    {
        $token = require_api_auth();
        if (strtolower((string) ($token['role_name'] ?? '')) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }

        $input = get_request_input();
        $visible = (bool) ($input['is_published_to_residents'] ?? true);

        try {
            $service = new ExternalHazardService();
            api_response($service->toggleEventVisibility($id, $visible), 200, 'Event visibility updated');
        } catch (Throwable $exception) {
            error_log('External event visibility update failed: ' . $exception->getMessage());
            api_error('Unable to update event visibility.', 500);
        }
    }
}
