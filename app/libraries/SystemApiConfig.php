<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SystemApiConfig
{
    public static function encryptSecret(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        self::assertEncryptionKey();
        $encryption = load_class('encryption', 'libraries', 'aes-256-gcm');
        return 'enc:v2:' . $encryption->encrypt($secret);
    }

    private static function assertEncryptionKey(): void
    {
        $configuredKey = (string) (getenv('APP_KEY') ?: '');
        if ($configuredKey === '' && function_exists('config_item')) {
            $configuredKey = (string) config_item('encryption_key');
        }
        if (strlen($configuredKey) < 32) {
            throw new RuntimeException('APP_KEY must be configured with at least 32 characters to store API credentials securely.');
        }
    }

    private static function decryptLegacySecret(string $encrypted): string
    {
        self::assertEncryptionKey();
        $configuredKey = (string) (getenv('APP_KEY') ?: config_item('encryption_key'));

        $payload = base64_decode(substr($encrypted, 7), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new RuntimeException('An API credential could not be decoded.');
        }

        $secret = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            hash('sha256', $configuredKey, true),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
            'bayanalert-system-api'
        );
        if (!is_string($secret)) {
            throw new RuntimeException('Unable to decrypt an API credential. Check APP_KEY stability.');
        }

        return $secret;
    }

    public static function decryptSecret(?string $encrypted): string
    {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }
        if (strpos($encrypted, 'enc:v2:') === 0) {
            self::assertEncryptionKey();
            $encoded = substr($encrypted, 7);
            $payload = base64_decode($encoded, true);
            if (!is_string($payload) || strlen($payload) < 29) {
                throw new RuntimeException('An API credential could not be decoded.');
            }

            $encryption = load_class('encryption', 'libraries', 'aes-256-gcm');
            $secret = $encryption->decrypt($encoded);
            if (!is_string($secret)) {
                throw new RuntimeException('Unable to decrypt an API credential. Check APP_KEY stability.');
            }
            return $secret;
        }
        if (strpos($encrypted, 'enc:v1:') === 0) {
            return self::decryptLegacySecret($encrypted);
        }

        throw new RuntimeException('An API credential has an unsupported encrypted format.');
    }

    public static function resolvePublicHttpsEndpoint(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new InvalidArgumentException('API endpoints must be HTTPS URLs on the public internet using port 443.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $addresses = [$host];
        } elseif (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $addresses = gethostbynamel($host) ?: [];
        } else {
            throw new InvalidArgumentException('Enter a valid public HTTPS API endpoint.');
        }

        if (!$addresses) {
            throw new InvalidArgumentException('The API endpoint hostname could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (!filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                throw new InvalidArgumentException('API endpoints cannot resolve to private or reserved network addresses.');
            }
        }

        return [
            'host' => $host,
            'ip' => $addresses[0],
        ];
    }

    public static function request(
        string $url,
        array $headers = [],
        int $timeout = 20,
        string $method = 'GET',
        ?string $body = null
    ): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Only GET and POST provider requests are supported.');
        }

        $resolved = self::resolvePublicHttpsEndpoint($url);
        $curl = curl_init($url);
        if ($curl === false) {
            return ['body' => null, 'status' => 0, 'error' => 'Unable to initialize the provider request.'];
        }

        $responseHeaders = [];
        $resolveEntry = $resolved['host'] . ':443:' . $resolved['ip'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_USERAGENT => 'BayanAlertExternalHazards/1.0',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$responseHeaders): int {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($headerLine);
            },
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        if (filter_var($resolved['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $options[CURLOPT_RESOLVE] = [$resolveEntry];
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($curl, $options);

        $startedAt = microtime(true);
        $body = curl_exec($curl);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $retryAfter = $responseHeaders['retry-after'] ?? null;
        $retryAt = null;
        if (is_string($retryAfter) && $retryAfter !== '') {
            $retryTimestamp = ctype_digit($retryAfter)
                ? time() + min((int) $retryAfter, 86400)
                : strtotime($retryAfter);
            if ($retryTimestamp !== false && $retryTimestamp > time()) {
                $retryAt = gmdate('Y-m-d H:i:s', $retryTimestamp);
            }
        }

        $error = null;
        if ($curlError !== '') {
            $error = $curlError;
        } elseif ($status === 429) {
            $error = 'Limit reached';
            $retryAt = $retryAt ?: gmdate('Y-m-d H:i:s', time() + 900);
        } elseif ($status < 200 || $status >= 300) {
            $error = 'Provider returned HTTP ' . $status . '.';
        } elseif (!is_string($body)) {
            $error = 'Provider returned an invalid response.';
        }

        return [
            'body' => is_string($body) ? $body : null,
            'status' => $status,
            'error' => $error,
            'retry_after_at' => $retryAt,
            'latency_ms' => $latencyMs,
        ];
    }

}
