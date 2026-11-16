<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'SystemApiConfig.php';
require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'ExternalHazardService.php';

class SystemApiController extends Controller
{
    private $currentUser;

    public function __construct()
    {
        parent::__construct();
        $this->currentUser = require_api_auth();
        if (strtolower((string) ($this->currentUser['role_name'] ?? '')) !== 'admin') {
            api_error('Administrator access required.', 403);
        }
    }

    public function index()
    {
        try {
            $summary = (new ExternalHazardService())->getAdminSummary();
            api_response(['apis' => array_values($summary['health'])]);
        } catch (Throwable $exception) {
            error_log('System API list failed: ' . $exception->getMessage());
            api_error('Unable to load system API configurations. Apply migration 009 first.', 503);
        }
    }

    public function create()
    {
        $input = get_request_input();
        try {
            api_validate_input(is_array($input) ? $input : [], [
                'api_name' => 'required|max_length[120]',
                'category' => 'required|in_list[Weather,Hazard,Mapping,Notifications]',
                'endpoint_url' => 'required|max_length[500]',
                'auth_header_type' => 'required|in_list[bearer,query_param,custom_header,none]',
                'sync_interval_minutes' => 'required|numeric',
            ]);
            $data = $this->validateInput(is_array($input) ? $input : [], false);
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $data['api_name']), '-'));
            $key = substr($slug ?: 'custom-api', 0, 52) . '-' . bin2hex(random_bytes(5));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('system_apis')->insert([
                'api_key' => $key,
                'api_name' => $data['api_name'],
                'category' => $data['category'],
                'endpoint_url' => $data['endpoint_url'],
                'credential_encrypted' => $data['credential_encrypted'],
                'auth_header_type' => $data['auth_header_type'],
                'auth_header_name' => $data['auth_header_name'],
                'sync_interval_minutes' => $data['sync_interval_minutes'],
                'custom_parameters_json' => $data['custom_parameters_json'],
                'is_enabled' => $data['is_enabled'],
                'is_custom' => 1,
                'last_status' => 'idle',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $record = $this->findByKey($key);
            api_response(['api' => $this->publicRecord($record)], 201, 'System API registered.');
        } catch (InvalidArgumentException $exception) {
            api_error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('System API create failed: ' . $exception->getMessage());
            api_error('Unable to register this API.', 500);
        }
    }

    public function update(string $id)
    {
        $record = $this->findById($id);
        $input = get_request_input();
        try {
            $validationFields = [
                'api_name' => 'required|max_length[120]',
                'category' => 'required|in_list[Weather,Hazard,Mapping,Notifications]',
                'endpoint_url' => 'required|max_length[500]',
                'auth_header_type' => 'required|in_list[bearer,query_param,custom_header,none]',
                'sync_interval_minutes' => 'required|numeric',
            ];
            $providedFields = [];
            foreach ($validationFields as $field => $rules) {
                if (is_array($input) && array_key_exists($field, $input)) {
                    $providedFields[$field] = $rules;
                }
            }
            if ($providedFields) {
                api_validate_input($input, $providedFields);
            }
            $data = $this->validateInput(is_array($input) ? $input : [], true, $record);
            $this->db->table('system_apis')
                ->where('id', (int) $record['id'])
                ->update([
                    'api_name' => $data['api_name'],
                    'category' => $data['category'],
                    'endpoint_url' => $data['endpoint_url'],
                    'credential_encrypted' => $data['credential_encrypted'],
                    'auth_header_type' => $data['auth_header_type'],
                    'auth_header_name' => $data['auth_header_name'],
                    'sync_interval_minutes' => $data['sync_interval_minutes'],
                    'custom_parameters_json' => $data['custom_parameters_json'],
                    'is_enabled' => $data['is_enabled'],
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            $this->syncLegacyEnabledState($record['api_key'], $data['is_enabled']);
            api_response(['api' => $this->publicRecord($this->findById((string) $record['id']))], 200, 'System API configuration updated.');
        } catch (InvalidArgumentException $exception) {
            api_error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('System API update failed: ' . $exception->getMessage());
            api_error('Unable to update this API.', 500);
        }
    }

    public function test(string $id)
    {
        $record = $this->findById($id);
        try {
            $headers = ['Accept: application/json'];
            $url = $this->appendParameters(
                (string) $record['endpoint_url'],
                json_decode((string) ($record['custom_parameters_json'] ?: '{}'), true) ?: []
            );
            $credential = SystemApiConfig::decryptSecret($record['credential_encrypted'] ?? null);
            $authType = strtolower((string) $record['auth_header_type']);
            $authName = trim((string) ($record['auth_header_name'] ?? ''));
            if ($credential !== '' && $authType === 'bearer') {
                $headers[] = 'Authorization: Bearer ' . $credential;
            } elseif ($credential !== '' && $authType === 'query_param') {
                $url = $this->appendParameters($url, [$authName ?: 'api_key' => $credential]);
            } elseif ($credential !== '' && $authType === 'custom_header') {
                $headers[] = $authName . ': ' . $credential;
            }

            $response = SystemApiConfig::request($url, $headers);
            $success = $response['error'] === null;
            $this->db->table('system_apis')
                ->where('id', (int) $record['id'])
                ->update([
                    'last_status' => $success ? 'online' : 'error',
                    'last_http_status' => $response['status'],
                    'last_latency_ms' => $response['latency_ms'],
                    'last_synced_at' => gmdate('Y-m-d H:i:s'),
                    'last_error_message' => $response['error'],
                    'rate_limit_until' => $response['retry_after_at'],
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            if (!$success) {
                api_error($response['error'] ?: 'Connection test failed.', $response['status'] === 429 ? 429 : 502);
            }

            api_response([
                'status' => 'online',
                'http_status' => $response['status'],
                'latency_ms' => $response['latency_ms'],
                'last_synced_at' => gmdate('c'),
            ], 200, 'Connection test succeeded.');
        } catch (InvalidArgumentException $exception) {
            api_error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('System API connection test failed for ID ' . (int) $record['id'] . ': ' . $exception->getMessage());
            api_error('Unable to test this API connection.', 502);
        }
    }

    public function resetCache(string $id)
    {
        $record = $this->findById($id);
        try {
            $this->db->table('system_apis')
                ->where('id', (int) $record['id'])
                ->update([
                    'last_status' => 'idle',
                    'last_http_status' => null,
                    'last_latency_ms' => null,
                    'last_error_message' => null,
                    'last_synced_at' => null,
                    'rate_limit_until' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            $this->db->table('external_api_configs')
                ->where('api_key', $record['api_key'])
                ->update([
                    'last_status' => 'idle',
                    'last_error_message' => null,
                    'rate_limit_until' => null,
                    'last_synced_at' => null,
                    'records_cached' => 0,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            ExternalApiConfig::forget((string) $record['api_key']);
            api_response(['id' => (int) $record['id']], 200, 'Rate-limit status and cached sync state cleared.');
        } catch (Throwable $exception) {
            error_log('System API cache reset failed: ' . $exception->getMessage());
            api_error('Unable to reset this API status.', 500);
        }
    }

    public function delete(string $id)
    {
        $record = $this->findById($id);
        if ((int) $record['is_custom'] !== 1) {
            api_error('Built-in API integrations cannot be deleted.', 403);
        }

        try {
            $this->db->table('system_apis')
                ->where('id', (int) $record['id'])
                ->where('is_custom', 1)
                ->delete();
            api_response(['id' => (int) $record['id']], 200, 'Custom API deleted.');
        } catch (Throwable $exception) {
            error_log('System API delete failed: ' . $exception->getMessage());
            api_error('Unable to delete this custom API.', 500);
        }
    }

    private function validateInput(array $input, bool $isUpdate, array $existing = []): array
    {
        $name = isset($input['api_name']) && is_string($input['api_name'])
            ? trim($input['api_name'])
            : (string) ($existing['api_name'] ?? '');
        $category = isset($input['category']) && is_string($input['category'])
            ? trim($input['category'])
            : (string) ($existing['category'] ?? '');
        $endpoint = isset($input['endpoint_url']) && is_string($input['endpoint_url'])
            ? trim($input['endpoint_url'])
            : (string) ($existing['endpoint_url'] ?? '');
        $authType = isset($input['auth_header_type']) && is_string($input['auth_header_type'])
            ? strtolower(trim($input['auth_header_type']))
            : (string) ($existing['auth_header_type'] ?? 'none');
        $authName = isset($input['auth_header_name']) && is_string($input['auth_header_name'])
            ? trim($input['auth_header_name'])
            : (string) ($existing['auth_header_name'] ?? '');
        $interval = isset($input['sync_interval_minutes'])
            ? filter_var($input['sync_interval_minutes'], FILTER_VALIDATE_INT)
            : (int) ($existing['sync_interval_minutes'] ?? 60);
        $enabled = (int) ($existing['is_enabled'] ?? 1);
        if (array_key_exists('is_enabled', $input)) {
            if (!in_array($input['is_enabled'], [true, false, 1, 0, '1', '0'], true)) {
                throw new InvalidArgumentException('Active status must be true or false.');
            }
            $enabled = in_array($input['is_enabled'], [true, 1, '1'], true) ? 1 : 0;
        }
        $parameters = isset($input['custom_parameters_json']) && is_string($input['custom_parameters_json'])
            ? trim($input['custom_parameters_json'])
            : (string) ($existing['custom_parameters_json'] ?? '{}');

        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('API Name is required and must be 120 characters or fewer.');
        }
        if (!in_array($category, ['Weather', 'Hazard', 'Mapping', 'Notifications'], true)) {
            throw new InvalidArgumentException('Choose a supported API category.');
        }
        if (strlen($endpoint) > 500) {
            throw new InvalidArgumentException('The API endpoint must be 500 characters or fewer.');
        }
        SystemApiConfig::resolvePublicHttpsEndpoint($endpoint);
        if (!in_array($authType, ['bearer', 'query_param', 'custom_header', 'none'], true)) {
            throw new InvalidArgumentException('Choose a supported authentication type.');
        }
        if ($authType === 'custom_header' && !preg_match('/^[A-Za-z0-9-]{1,80}$/', $authName)) {
            throw new InvalidArgumentException('Enter a valid custom authentication header name.');
        }
        if ($authType === 'query_param' && $authName !== ''
            && !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $authName)) {
            throw new InvalidArgumentException('Enter a valid authentication query parameter name.');
        }
        if ($interval === false || $interval < 1 || $interval > 10080) {
            throw new InvalidArgumentException('The refresh interval must be between 1 and 10,080 minutes.');
        }

        $decodedParameters = json_decode($parameters, true);
        if (!is_array($decodedParameters) || (trim($parameters) !== '' && $parameters[0] !== '{')) {
            throw new InvalidArgumentException('Custom parameters must be a JSON object.');
        }
        foreach ($decodedParameters as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key)
                || !is_scalar($value)) {
                throw new InvalidArgumentException('Custom parameter names and values must be simple text fields.');
            }
        }

        $encryptedCredential = $existing['credential_encrypted'] ?? null;
        if (in_array($input['clear_api_key'] ?? false, [true, 1, '1'], true)) {
            $encryptedCredential = null;
        }
        if (isset($input['api_key']) && is_string($input['api_key']) && $input['api_key'] !== '') {
            $credential = trim($input['api_key']);
            if (strlen($credential) > 4096 || preg_match('/[\r\n\x00]/', $credential)) {
                throw new InvalidArgumentException('API credentials must be at most 4,096 characters and contain no line breaks.');
            }
            $encryptedCredential = SystemApiConfig::encryptSecret($credential);
        }

        return [
            'api_name' => $name,
            'category' => $category,
            'endpoint_url' => $endpoint,
            'auth_header_type' => $authType,
            'auth_header_name' => $authName !== '' ? $authName : null,
            'sync_interval_minutes' => $interval,
            'custom_parameters_json' => json_encode(
                $decodedParameters,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'credential_encrypted' => $encryptedCredential,
            'is_enabled' => $enabled,
        ];
    }

    private function findById(string $id): array
    {
        if (!ctype_digit($id) || (int) $id < 1) {
            api_error('Invalid system API ID.', 400);
        }
        $record = $this->db->table('system_apis')
            ->where('id', (int) $id)
            ->limit(1)
            ->get();
        if (!$record) {
            api_error('System API not found.', 404);
        }
        return $record;
    }

    private function findByKey(string $key): array
    {
        $record = $this->db->table('system_apis')
            ->where('api_key', $key)
            ->limit(1)
            ->get();
        if (!$record) {
            throw new RuntimeException('The new API record could not be loaded.');
        }
        return $record;
    }

    private function publicRecord(array $record): array
    {
        $record['has_api_key'] = !empty($record['credential_encrypted']);
        unset($record['credential_encrypted']);
        $record['id'] = (int) $record['id'];
        $record['is_enabled'] = (int) $record['is_enabled'] === 1;
        $record['is_custom'] = (int) $record['is_custom'] === 1;
        $record['has_saved_api_key'] = $record['has_api_key'];
        return $record;
    }

    private function syncLegacyEnabledState(string $key, int $enabled): void
    {
        $this->db->table('external_api_configs')
            ->where('api_key', $key)
            ->update([
                'is_enabled' => $enabled,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        ExternalApiConfig::forget($key);
    }

    private function appendParameters(string $url, array $parameters): string
    {
        if (!$parameters) return $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($parameters);
    }
}
