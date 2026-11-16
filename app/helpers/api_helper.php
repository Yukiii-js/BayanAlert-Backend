<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

if (!function_exists('api_response')) {
    function api_response($data = null, $status = 200, $message = 'Success') {
        $payload = [
            'success' => $status >= 200 && $status < 300,
            'message' => $message
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        $response = load_class('response', 'kernel');
        $response->send_json($payload, $status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_error')) {
    function api_error($message = 'An error occurred', $status = 400, $extra = []) {
        $payload = array_merge([
            'success' => false,
            'error'   => $message,
            'message' => $message
        ], $extra);

        $response = load_class('response', 'kernel');
        $response->send_json($payload, $status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_validate_input')) {
    function api_validate_input(array $input, array $fields) {
        $validator = load_class('form_validation', 'libraries');
        $validator->set_data($input);

        if (!$validator->validate($fields)) {
            api_error('Validation failed.', 422, [
                'errors' => $validator->get_errors(),
            ]);
        }
    }
}

if (!function_exists('get_request_input')) {
    function get_request_input() {
        $request = load_class('request', 'kernel');
        $json = $request->json();
        if (is_array($json)) {
            return $json;
        }

        return $request->post();
    }
}

if (!function_exists('get_auth_bearer_token')) {
    function get_auth_bearer_token() {
        $request = load_class('request', 'kernel');
        $header = $request->header('Authorization')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION');

        if (preg_match('/Bearer\s(\S+)/i', (string) $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

if (!function_exists('get_jwt_secret_key')) {
    function get_jwt_secret_key() {
        $secret = trim((string) (getenv('JWT_SECRET') ?: config_item('jwt_secret')));
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be configured with at least 32 characters.');
        }

        return $secret;
    }
}

if (!function_exists('jwt_base64_url_encode')) {
    function jwt_base64_url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('jwt_base64_url_decode')) {
    function jwt_base64_url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}

if (!function_exists('generate_jwt_token')) {
    function generate_jwt_token($payload, $ttl = 86400) {
        $secret = get_jwt_secret_key();
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();

        $tokenPayload = array_merge([
            'iat' => $now,
            'exp' => $now + $ttl
        ], $payload);

        $headerEnc = jwt_base64_url_encode(json_encode($header));
        $payloadEnc = jwt_base64_url_encode(json_encode($tokenPayload));

        $signature = hash_hmac('sha256', "{$headerEnc}.{$payloadEnc}", $secret, true);
        $sigEnc = jwt_base64_url_encode($signature);

        return "{$headerEnc}.{$payloadEnc}.{$sigEnc}";
    }
}

if (!function_exists('verify_jwt_token')) {
    function verify_jwt_token($token) {
        if (empty($token) || !is_string($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($headerEnc, $payloadEnc, $sigEnc) = $parts;
        $secret = get_jwt_secret_key();

        $expectedSig = hash_hmac('sha256', "{$headerEnc}.{$payloadEnc}", $secret, true);
        if (!hash_equals(jwt_base64_url_encode($expectedSig), $sigEnc)) {
            return null;
        }

        $payload = json_decode(jwt_base64_url_decode($payloadEnc), true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null; // Expired
        }

        return $payload;
    }
}

if (!function_exists('require_api_auth')) {
    function require_api_auth() {
        $token = get_auth_bearer_token();
        if (!$token) {
            api_error('Authentication token required.', 401);
        }

        try {
            $payload = verify_jwt_token($token);
        } catch (RuntimeException $e) {
            error_log('JWT authentication is unavailable: ' . $e->getMessage());
            api_error('Authentication service is unavailable.', 503);
        }

        if (!$payload) {
            api_error('Invalid or expired authentication token.', 401);
        }

        return $payload;
    }
}
