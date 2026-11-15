<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExternalApiConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SystemApiConfig.php';

class ExternalHazardService
{
    private const DEFAULT_CENTER_LAT = 13.4117;
    private const DEFAULT_CENTER_LON = 121.1803;
    private $providerStatus = [];
    private $db;
    private $providerEnabledCache = [];
    private $lastRetryAfterAt;
    private $lastHttpStatus;
    private $lastLatencyMs;

    public function __construct()
    {
        $lava = lava_instance();
        $this->db = $lava->db;
    }

    private function providerCatalog(): array
    {
        return [
            'weatherapi' => [
                'display_name' => 'WeatherAPI',
                'endpoint_url' => 'https://api.weatherapi.com/v1/forecast.json',
                'category' => 'Weather',
                'auth_header_type' => 'query_param',
                'auth_header_name' => 'key',
            ],
            'usgs' => [
                'display_name' => 'USGS Earthquake API',
                'endpoint_url' => 'https://earthquake.usgs.gov/fdsnws/event/1/query',
                'category' => 'Hazard',
                'auth_header_type' => 'none',
                'auth_header_name' => null,
            ],
            'gdacs' => [
                'display_name' => 'GDACS Multi-Hazard API',
                'endpoint_url' => 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH',
                'category' => 'Hazard',
                'auth_header_type' => 'none',
                'auth_header_name' => null,
            ],
            'typhoon' => [
                'display_name' => 'Typhoon / Tropical Cyclone Feed',
                'endpoint_url' => 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH',
                'category' => 'Hazard',
                'auth_header_type' => 'none',
                'auth_header_name' => null,
            ],
            'hdx' => [
                'display_name' => 'UN OCHA HDX API',
                'endpoint_url' => 'https://data.humdata.org/api/3/action/package_show',
                'category' => 'Mapping',
                'auth_header_type' => 'none',
                'auth_header_name' => null,
            ],
            'nasa_eonet' => [
                'display_name' => 'NASA EONET / Open-Meteo',
                'endpoint_url' => 'https://eonet.gsfc.nasa.gov/api/v3/events',
                'category' => 'Hazard',
                'auth_header_type' => 'none',
                'auth_header_name' => null,
            ],
            'brevo' => [
                'display_name' => 'Brevo Email API',
                'endpoint_url' => 'https://api.brevo.com/v3/smtp/email',
                'category' => 'Notifications',
                'auth_header_type' => 'custom_header',
                'auth_header_name' => 'api-key',
            ],
        ];
    }

    public function getResidentFeed(): array
    {
        $usgsEvents = $this->fetchUsgsEvents();
        $gdacsEnabled = $this->isProviderEnabled('gdacs');
        $typhoonEnabled = $this->isProviderEnabled('typhoon');
        $gdacsEvents = array_values(array_filter(
            $this->fetchGdacsEvents(),
            static function ($event) use ($gdacsEnabled, $typhoonEnabled) {
                $isTyphoon = strtoupper((string) ($event['hazard_type'] ?? '')) === 'TC';
                return $isTyphoon ? $typhoonEnabled : $gdacsEnabled;
            }
        ));
        $typhoonEvents = array_values(array_filter($gdacsEvents, static function ($event) {
            return strtoupper((string) ($event['hazard_type'] ?? '')) === 'TC';
        }));
        $gdacsStatus = $this->providerStatus['gdacs'] ?? [
            'status' => 'unknown',
            'message' => 'GDACS feed status is unavailable.',
        ];
        $this->providerStatus['gdacs'] = $gdacsEnabled
            ? $gdacsStatus
            : ['status' => 'disabled', 'message' => null];
        $this->providerStatus['typhoon'] = $typhoonEnabled
            ? $gdacsStatus
            : ['status' => 'disabled', 'message' => null];
        $nasaStatus = $this->db->raw(
            'SELECT last_status, rate_limit_until FROM system_apis WHERE api_key = ? LIMIT 1',
            ['nasa_eonet']
        )->fetch(PDO::FETCH_ASSOC);
        if ($nasaStatus) {
            $this->providerStatus['nasa_eonet'] = [
                'status' => (string) $nasaStatus['last_status'],
                'message' => 'error' === $nasaStatus['last_status'] && !empty($nasaStatus['rate_limit_until'])
                    ? 'Limit reached'
                    : null,
                'rate_limit_until' => $nasaStatus['rate_limit_until'],
            ];
        }
        $allEvents = array_merge($usgsEvents, $gdacsEvents);

        usort($allEvents, static function ($left, $right) {
            $leftTime = strtotime((string) ($left['event_timestamp'] ?? '1970-01-01T00:00:00Z'));
            $rightTime = strtotime((string) ($right['event_timestamp'] ?? '1970-01-01T00:00:00Z'));
            return $rightTime <=> $leftTime;
        });

        return [
            'generated_at' => gmdate('c'),
            'providers' => $this->providerStatus,
            'summary' => [
                'total' => count($allEvents),
                'usgs' => count($usgsEvents),
                'gdacs' => count($gdacsEvents),
                'typhoon' => count($typhoonEvents),
            ],
            'layers' => [
                'usgs' => [
                    'enabled' => $this->isProviderEnabled('usgs'),
                    'label' => 'USGS Seismic Epicenters',
                    'count' => count($usgsEvents),
                ],
                'gdacs' => [
                    'enabled' => $gdacsEnabled,
                    'label' => 'GDACS Regional Alerts',
                    'count' => count($gdacsEvents),
                ],
                'hdx' => [
                    'enabled' => true,
                    'label' => 'HDX Barangay Boundaries & Exposure',
                    'count' => null,
                    'message' => 'The Calapan-only boundary GeoJSON is bundled with frontend assets.',
                ],
            ],
            'events' => $allEvents,
        ];
    }

    public function publishHighSeverityNotifications(array $events): int
    {
        $createdNotifications = 0;
        $this->db->raw('START TRANSACTION');

        try {
            foreach ($events as $event) {
                if (strtoupper((string) ($event['source'] ?? '')) !== 'GDACS') {
                    continue;
                }

                $hazardType = strtoupper((string) ($event['hazard_type'] ?? ''));
                $alertLevel = strtoupper((string) ($event['alert_level'] ?? ''));
                $isTyphoon = $hazardType === 'TC';
                $isHighSeverity = in_array($alertLevel, ['ORANGE', 'RED'], true);
                if ((!$isTyphoon && !$isHighSeverity) || empty($event['id'])) {
                    continue;
                }

                $timestamp = strtotime((string) ($event['event_timestamp'] ?? ''));
                $eventDate = $timestamp === false ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $timestamp);
                $insert = $this->db->raw(
                    'INSERT IGNORE INTO external_hazard_events ' .
                    '(source, external_event_id, hazard_type, title, severity_level, magnitude_or_score, latitude, longitude, ' .
                    'distance_to_municipality_km, affected_population, event_timestamp, raw_payload_json, is_published_to_residents, created_at, updated_at) ' .
                    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                    [
                        'GDACS',
                        substr((string) $event['id'], 0, 128),
                        substr($hazardType ?: 'ALERT', 0, 8),
                        substr((string) ($event['title'] ?? 'Regional hazard alert'), 0, 255),
                        strtolower((string) ($event['severity_level'] ?? 'green')),
                        isset($event['magnitude_or_score']) ? (float) $event['magnitude_or_score'] : null,
                        isset($event['latitude']) ? (float) $event['latitude'] : null,
                        isset($event['longitude']) ? (float) $event['longitude'] : null,
                        isset($event['distance_to_municipality_km']) ? (float) $event['distance_to_municipality_km'] : null,
                        isset($event['affected_population']) ? (int) $event['affected_population'] : null,
                        $eventDate,
                        json_encode($event['raw_payload'] ?? $event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]
                );

                if ((int) $insert->rowCount() !== 1) {
                    continue;
                }

                $title = $isTyphoon
                    ? 'Tropical cyclone advisory: ' . (string) ($event['title'] ?? 'Regional alert')
                    : strtoupper($alertLevel) . ' GDACS alert: ' . (string) ($event['title'] ?? 'Regional hazard');
                $area = trim((string) ($event['affected_area'] ?? ''));
                $distance = isset($event['distance_to_municipality_km'])
                    ? ' About ' . number_format((float) $event['distance_to_municipality_km'], 1) . ' km from Calapan.'
                    : '';
                $message = 'GDACS reports an ' . ($isTyphoon ? 'active tropical cyclone' : strtolower($alertLevel) . '-level hazard')
                    . ($area !== '' ? ' affecting ' . $area . '.' : ' in the Philippines or nearby region.')
                    . $distance
                    . ' Open Regional Advisories for details.';

                $notificationInsert = $this->db->raw(
                    "INSERT INTO notifications (user_id, report_id, advisory_id, target_type, title, message, notification_type, is_read, created_at) " .
                    "SELECT u.user_id, NULL, ?, 'advisory', ?, ?, 'external_hazard', 0, NOW() " .
                    "FROM users u INNER JOIN roles r ON r.role_id = u.role_id " .
                    "WHERE r.role_name IN ('resident', 'user') AND u.account_status = 'active'",
                    [
                        substr((string) $event['id'], 0, 128),
                        mb_substr($title, 0, 200),
                        mb_substr($message, 0, 5000),
                    ]
                );
                $createdNotifications += (int) $notificationInsert->rowCount();
            }

            $this->db->raw('COMMIT');
            return $createdNotifications;
        } catch (Throwable $exception) {
            $this->db->raw('ROLLBACK');
            throw $exception;
        }
    }

    public function getAdminSummary(): array
    {
        $this->ensureApiConfigs();
        $this->ensureSystemApiRows();
        $rows = $this->db->raw(
            'SELECT api_key, display_name, endpoint_url, is_enabled, last_synced_at, last_status, ' .
            'last_error_message, rate_limit_until, records_cached FROM external_api_configs'
        )->fetchAll(PDO::FETCH_ASSOC);
        $saved = [];
        foreach ($rows as $row) {
            $saved[$row['api_key']] = $row;
        }

        $systemRows = $this->db->raw(
            'SELECT id, api_key, api_name, category, endpoint_url, credential_encrypted, auth_header_type, ' .
            'auth_header_name, sync_interval_minutes, custom_parameters_json, is_enabled, is_custom, last_status, ' .
            'last_http_status, last_latency_ms, rate_limit_until, last_synced_at, last_error_message ' .
            'FROM system_apis'
        )->fetchAll(PDO::FETCH_ASSOC);
        $systemByKey = [];
        foreach ($systemRows as $row) {
            $systemByKey[$row['api_key']] = $row;
        }

        $health = [];
        foreach ($this->providerCatalog() as $key => $defaults) {
            $row = $saved[$key] ?? [];
            $system = $systemByKey[$key] ?? [];
            $enabled = array_key_exists('is_enabled', $system)
                ? (int) $system['is_enabled'] === 1
                : (!array_key_exists('is_enabled', $row) || (int) $row['is_enabled'] === 1);
            $hasEnvironmentCredential = in_array($key, ['weatherapi', 'brevo'], true)
                && trim((string) getenv($key === 'weatherapi' ? 'WEATHERAPI_KEY' : 'BREVO_API_KEY')) !== '';
            $health[$key] = [
                'id' => isset($system['id']) ? (int) $system['id'] : null,
                'api_key' => $key,
                'display_name' => $system['api_name'] ?? ($row['display_name'] ?? $defaults['display_name']),
                'category' => $system['category'] ?? $defaults['category'],
                'endpoint_url' => $system['endpoint_url'] ?? (($row['endpoint_url'] ?? null) ?: $defaults['endpoint_url']),
                'auth_header_type' => $system['auth_header_type'] ?? $defaults['auth_header_type'],
                'auth_header_name' => $system['auth_header_name'] ?? $defaults['auth_header_name'],
                'sync_interval_minutes' => isset($system['sync_interval_minutes']) ? (int) $system['sync_interval_minutes'] : 60,
                'custom_parameters_json' => $system['custom_parameters_json'] ?? '{}',
                'has_api_key' => !empty($system['credential_encrypted']) || $hasEnvironmentCredential,
                'has_saved_api_key' => !empty($system['credential_encrypted']),
                'is_custom' => false,
                'status' => !$enabled
                    ? 'disabled'
                    : (string) ($system['last_status'] ?? ($row['last_status'] ?? 'idle')),
                'last_status' => (string) ($system['last_status'] ?? ($row['last_status'] ?? 'idle')),
                'last_http_status' => isset($system['last_http_status']) ? (int) $system['last_http_status'] : null,
                'last_latency_ms' => isset($system['last_latency_ms']) ? (int) $system['last_latency_ms'] : null,
                'last_synced_at' => $system['last_synced_at'] ?? ($row['last_synced_at'] ?? null),
                'last_error_message' => $system['last_error_message'] ?? ($row['last_error_message'] ?? null),
                'rate_limit_until' => $system['rate_limit_until'] ?? ($row['rate_limit_until'] ?? null),
                'records_cached' => (int) ($row['records_cached'] ?? 0),
                'is_enabled' => $enabled,
            ];
        }

        foreach ($systemRows as $system) {
            if (isset($health[$system['api_key']])) {
                continue;
            }
            $health[$system['api_key']] = [
                'id' => (int) $system['id'],
                'api_key' => $system['api_key'],
                'display_name' => $system['api_name'],
                'category' => $system['category'],
                'endpoint_url' => $system['endpoint_url'],
                'auth_header_type' => $system['auth_header_type'],
                'auth_header_name' => $system['auth_header_name'],
                'sync_interval_minutes' => (int) $system['sync_interval_minutes'],
                'custom_parameters_json' => $system['custom_parameters_json'] ?: '{}',
                'has_api_key' => !empty($system['credential_encrypted']),
                'has_saved_api_key' => !empty($system['credential_encrypted']),
                'is_custom' => (int) $system['is_custom'] === 1,
                'status' => (string) $system['last_status'],
                'last_status' => (string) $system['last_status'],
                'last_http_status' => $system['last_http_status'] === null ? null : (int) $system['last_http_status'],
                'last_latency_ms' => $system['last_latency_ms'] === null ? null : (int) $system['last_latency_ms'],
                'last_synced_at' => $system['last_synced_at'],
                'last_error_message' => $system['last_error_message'],
                'rate_limit_until' => $system['rate_limit_until'],
                'records_cached' => 0,
                'is_enabled' => (int) $system['is_enabled'] === 1,
            ];
        }

        return [
            'generated_at' => gmdate('c'),
            'health' => $health,
        ];
    }

    public function sync(string $key): array
    {
        $normalized = strtolower(trim($key));
        if (!isset($this->providerCatalog()[$normalized])) {
            throw new InvalidArgumentException('Unsupported external API key: ' . $key);
        }

        $this->ensureSystemApiRows();
        $this->lastRetryAfterAt = null;
        $this->lastHttpStatus = null;
        $this->lastLatencyMs = null;
        try {
            $records = $this->testProvider($normalized);
            $this->saveSyncStatus($normalized, 'online', null, $records);
            return [
                'api_key' => $normalized,
                'status' => 'success',
                'records_cached' => $records,
                'last_synced_at' => gmdate('c'),
                'http_status' => $this->lastHttpStatus,
                'latency_ms' => $this->lastLatencyMs,
            ];
        } catch (Throwable $exception) {
            $message = $this->lastHttpStatus === 429
                ? 'Limit reached'
                : $exception->getMessage();
            $this->saveSyncStatus($normalized, 'error', $message, 0);
            throw new RuntimeException($message, 0, $exception);
        }
    }

    public function updateConfig(string $key, array $input): array
    {
        $normalized = strtolower(trim($key));
        $catalog = $this->providerCatalog();
        if (!isset($catalog[$normalized])) {
            throw new InvalidArgumentException('Unsupported external API key: ' . $key);
        }

        if (!array_key_exists('is_enabled', $input)) {
            throw new InvalidArgumentException('An is_enabled value is required.');
        }
        $value = $input['is_enabled'];
        if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            throw new InvalidArgumentException('is_enabled must be true or false.');
        }
        $enabled = in_array($value, [true, 1, '1'], true) ? 1 : 0;
        $defaults = $catalog[$normalized];
        $this->ensureSystemApiRows();
        $this->db->raw(
            'INSERT INTO external_api_configs (api_key, display_name, endpoint_url, is_enabled, last_status, created_at, updated_at) ' .
            'VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), endpoint_url = VALUES(endpoint_url), ' .
            'is_enabled = VALUES(is_enabled), updated_at = NOW()',
            [$normalized, $defaults['display_name'], $defaults['endpoint_url'], $enabled, 'idle']
        );
        $this->db->raw(
            'UPDATE system_apis SET is_enabled = ?, updated_at = NOW() WHERE api_key = ?',
            [$enabled, $normalized]
        );
        ExternalApiConfig::forget($normalized);
        unset($this->providerEnabledCache[$normalized]);

        return [
            'api_key' => $normalized,
            'display_name' => $defaults['display_name'],
            'is_enabled' => $enabled,
            'status' => $enabled ? 'idle' : 'disabled',
        ];
    }

    public function requestConfiguredProvider(
        string $key,
        array $parameters,
        ?array $payload = null,
        ?string $endpointOverride = null
    ): array
    {
        $normalized = strtolower(trim($key));
        if (!isset($this->providerCatalog()[$normalized])) {
            throw new InvalidArgumentException('Unsupported external API key: ' . $key);
        }

        $settings = $this->systemApiSettings($normalized);
        $requestParameters = $settings['custom_parameters'] + $parameters;
        $url = $this->appendQueryParameters($endpointOverride ?: $settings['endpoint_url'], $requestParameters);
        $headers = ['Accept: application/json'];
        $this->applyApiAuthentication($settings, $url, $headers);
        if ($payload === null) {
            return $this->httpRequest($url, $headers);
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($jsonPayload)) {
            throw new RuntimeException('Provider request payload could not be encoded.');
        }
        $headers[] = 'Content-Type: application/json';
        return $this->httpRequest($url, $headers, 20, 'POST', $jsonPayload);
    }

    public function hasProviderCredential(string $key): bool
    {
        $normalized = strtolower(trim($key));
        if (!isset($this->providerCatalog()[$normalized])) {
            throw new InvalidArgumentException('Unsupported external API key: ' . $key);
        }

        return $this->systemApiSettings($normalized)['credential'] !== '';
    }

    public function toggleEventVisibility(int $id, bool $visible): array
    {
        return [
            'id' => $id,
            'is_published_to_residents' => $visible,
            'updated_at' => gmdate('c'),
        ];
    }

    private function fetchUsgsEvents(): array
    {
        if (!$this->isProviderEnabled('usgs')) {
            $this->providerStatus['usgs'] = ['status' => 'disabled', 'message' => null];
            return [];
        }

        $settings = $this->systemApiSettings('usgs');
        $parameters = $settings['custom_parameters'] + [
            'format' => 'geojson',
            'starttime' => date('Y-m-d', strtotime('-7 days')),
            'latitude' => self::DEFAULT_CENTER_LAT,
            'longitude' => self::DEFAULT_CENTER_LON,
            'maxradiuskm' => 300,
            'minmagnitude' => 2.5,
        ];
        $response = $this->requestConfiguredProvider('usgs', $parameters);

        if (!is_string($response['body'])) {
            $this->providerStatus['usgs'] = [
                'status' => 'error',
                'message' => $response['error'] === 'Limit reached' ? 'Limit reached' : 'USGS could not be reached.',
                'rate_limit_until' => $response['retry_after_at'],
            ];
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['features']) || !is_array($decoded['features'])) {
            $this->providerStatus['usgs'] = ['status' => 'error', 'message' => 'USGS returned an invalid response.'];
            return [];
        }

        $events = [];
        foreach ($decoded['features'] as $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $coordinates = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [0, 0, 0];
            $latitude = (float) ($coordinates[1] ?? 0);
            $longitude = (float) ($coordinates[0] ?? 0);
            $depthKm = (float) ($coordinates[2] ?? 0);
            $magnitude = (float) ($properties['mag'] ?? 0);

            if ($latitude === 0.0 && $longitude === 0.0) {
                continue;
            }

            $distance = self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON);
            $published = $distance <= 150 || $magnitude >= 4.5;
            $alert = strtolower((string) ($properties['alert'] ?? ''));
            $severity = $this->severityFromMagnitude($magnitude, $alert);

            $events[] = [
                'id' => 'usgs-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($properties['ids'] ?? uniqid('event', true))),
                'source' => 'USGS',
                'hazard_type' => 'EQ',
                'title' => (string) ($properties['place'] ?? 'Seismic event'),
                'severity_level' => $severity,
                'magnitude_or_score' => $magnitude,
                'depth_km' => $depthKm,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_municipality_km' => round($distance, 2),
                'affected_population' => null,
                'event_timestamp' => gmdate('c', (int) (($properties['time'] ?? time() * 1000) / 1000)),
                'alert_level' => $alert !== '' ? strtoupper($alert) : 'GREEN',
                'tsunami' => !empty($properties['tsunami']),
                'event_url' => $properties['url'] ?? null,
                'is_published_to_residents' => $published ? 1 : 0,
                'raw_payload' => $feature,
            ];
        }

        $this->providerStatus['usgs'] = ['status' => 'online', 'message' => null];
        return $events;
    }

    private function fetchGdacsEvents(): array
    {
        if (!$this->isProviderEnabled('gdacs') && !$this->isProviderEnabled('typhoon')) {
            $this->providerStatus['gdacs'] = ['status' => 'disabled', 'message' => null];
            $this->providerStatus['typhoon'] = ['status' => 'disabled', 'message' => null];
            return [];
        }

        $providerKey = $this->isProviderEnabled('gdacs') ? 'gdacs' : 'typhoon';
        $settings = $this->systemApiSettings($providerKey);
        $parameters = $settings['custom_parameters'] + [
            'fromdate' => date('Y-m-d', strtotime('-14 days')),
            'todate' => date('Y-m-d'),
        ];
        $response = $this->requestConfiguredProvider($providerKey, $parameters);

        if (!is_string($response['body'])) {
            $this->providerStatus['gdacs'] = [
                'status' => 'error',
                'message' => $response['error'] === 'Limit reached' ? 'Limit reached' : 'GDACS could not be reached.',
                'rate_limit_until' => $response['retry_after_at'],
            ];
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            $this->providerStatus['gdacs'] = ['status' => 'error', 'message' => 'GDACS returned invalid JSON.'];
            return [];
        }
        $features = $decoded['features'] ?? $decoded['events'] ?? null;
        if (!is_array($features)) {
            $this->providerStatus['gdacs'] = ['status' => 'error', 'message' => 'GDACS returned an unrecognized response.'];
            return [];
        }

        $events = [];
        foreach ($features as $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $coordinates = $this->extractCoordinates($geometry, $properties);
            $latitude = (float) ($coordinates['latitude'] ?? 0);
            $longitude = (float) ($coordinates['longitude'] ?? 0);
            $eventType = strtoupper((string) ($properties['eventtype'] ?? $properties['type'] ?? 'ALERT'));
            $alert = strtoupper((string) ($properties['alertlevel'] ?? 'GREEN'));
            $score = (float) ($properties['severitydata']['severity'] ?? $properties['severity'] ?? $properties['alertscore'] ?? $properties['score'] ?? 0);
            $hasCoordinates = $latitude >= -90 && $latitude <= 90
                && $longitude >= -180 && $longitude <= 180
                && ($latitude !== 0.0 || $longitude !== 0.0);
            $distance = $hasCoordinates
                ? self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON)
                : null;
            $affectedCountries = $properties['affectedcountries'] ?? [];
            $isPhilippinesEvent = strtoupper((string) ($properties['iso3'] ?? '')) === 'PHL';
            if (!$isPhilippinesEvent && is_array($affectedCountries)) {
                foreach ($affectedCountries as $country) {
                    if (strtoupper((string) ($country['iso3'] ?? '')) === 'PHL') {
                        $isPhilippinesEvent = true;
                        break;
                    }
                }
            }
            if (!$isPhilippinesEvent && ($distance === null || $distance > 500)) {
                continue;
            }
            $endDate = strtotime((string) ($properties['todate'] ?? ''));
            if ($endDate !== false && $endDate < strtotime('-2 days')) {
                continue;
            }
            $providerUrl = $properties['url'] ?? null;
            $eventUrl = is_array($providerUrl)
                ? ($providerUrl['report'] ?? $providerUrl['details'] ?? null)
                : (is_string($providerUrl) ? $providerUrl : null);

            $startTimestamp = $properties['fromdate'] ?? $properties['datemodified'] ?? gmdate('c');
            $startEpoch = strtotime((string) $startTimestamp);
            $events[] = [
                'id' => 'gdacs-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($properties['eventid'] ?? $properties['name'] ?? uniqid('gdacs', true))),
                'source' => 'GDACS',
                'hazard_type' => $eventType,
                'title' => (string) ($properties['name'] ?? 'Regional hazard alert'),
                'severity_level' => strtolower((string) ($alert === 'RED' ? 'red' : ($alert === 'ORANGE' ? 'orange' : 'green'))),
                'magnitude_or_score' => $score,
                'depth_km' => null,
                'latitude' => $hasCoordinates ? $latitude : null,
                'longitude' => $hasCoordinates ? $longitude : null,
                'distance_to_municipality_km' => $distance === null ? null : round($distance, 2),
                'affected_population' => (int) ($properties['population'] ?? 0),
                'event_timestamp' => gmdate('c', $startEpoch === false ? time() : $startEpoch),
                'alert_level' => $alert,
                'affected_area' => $this->affectedArea($properties),
                'event_url' => $eventUrl ?? $properties['urlreport'] ?? null,
                'is_published_to_residents' => (($distance !== null && $distance <= 500) || $isPhilippinesEvent) ? 1 : 0,
                'raw_payload' => $feature,
            ];
        }

        $this->providerStatus['gdacs'] = ['status' => 'online', 'message' => null];
        return $events;
    }

    private function httpRequest(
        string $url,
        array $headers = [],
        int $timeout = 20,
        string $method = 'GET',
        ?string $body = null
    ): array
    {
        $response = SystemApiConfig::request($url, $headers, $timeout, $method, $body);
        $this->lastHttpStatus = (int) ($response['status'] ?? 0);
        $this->lastLatencyMs = (int) ($response['latency_ms'] ?? 0);
        $this->lastRetryAfterAt = $response['retry_after_at'] ?? null;
        return [
            'body' => $response['error'] === null && $response['body'] !== ''
                ? $response['body']
                : null,
            'status' => $this->lastHttpStatus,
            'error' => $response['error'],
        ];
    }

    private function ensureApiConfigs(): void
    {
        foreach ($this->providerCatalog() as $key => $provider) {
            $this->db->raw(
                'INSERT IGNORE INTO external_api_configs ' .
                '(api_key, display_name, endpoint_url, is_enabled, last_status, created_at, updated_at) ' .
                'VALUES (?, ?, ?, 1, ?, NOW(), NOW())',
                [$key, $provider['display_name'], $provider['endpoint_url'], 'idle']
            );
        }
    }

    private function ensureSystemApiRows(): void
    {
        foreach ($this->providerCatalog() as $key => $provider) {
            $legacyConfig = $this->db->raw(
                'SELECT is_enabled, endpoint_url FROM external_api_configs WHERE api_key = ? LIMIT 1',
                [$key]
            )->fetch(PDO::FETCH_ASSOC);
            $enabled = (!$legacyConfig || (int) $legacyConfig['is_enabled'] === 1) ? 1 : 0;
            $endpoint = !empty($legacyConfig['endpoint_url'])
                ? $legacyConfig['endpoint_url']
                : $provider['endpoint_url'];
            if ($key === 'weatherapi' && $endpoint === 'https://api.weatherapi.com/v1/current.json') {
                $endpoint = $provider['endpoint_url'];
            }
            if ($key === 'brevo' && $endpoint === 'https://api.brevo.com/v3/account') {
                $endpoint = $provider['endpoint_url'];
            }
            $this->db->raw(
                'INSERT IGNORE INTO system_apis ' .
                '(api_key, api_name, category, endpoint_url, auth_header_type, auth_header_name, sync_interval_minutes, ' .
                'is_enabled, is_custom, last_status, created_at, updated_at) ' .
                'VALUES (?, ?, ?, ?, ?, ?, 60, ?, 0, ?, NOW(), NOW())',
                [
                    $key,
                    $provider['display_name'],
                    $provider['category'],
                    $endpoint,
                    $provider['auth_header_type'],
                    $provider['auth_header_name'],
                    $enabled,
                    'idle',
                ]
            );
        }
    }

    private function systemApiSettings(string $key): array
    {
        $defaults = $this->providerCatalog()[$key];
        $row = $this->db->raw(
            'SELECT endpoint_url, credential_encrypted, auth_header_type, auth_header_name, custom_parameters_json ' .
            'FROM system_apis WHERE api_key = ? LIMIT 1',
            [$key]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $environmentCredential = $key === 'weatherapi'
                ? trim((string) getenv('WEATHERAPI_KEY'))
                : ($key === 'brevo' ? trim((string) getenv('BREVO_API_KEY')) : '');
            return $defaults + [
                'credential' => $environmentCredential,
                'custom_parameters' => [],
            ];
        }

        $credential = SystemApiConfig::decryptSecret($row['credential_encrypted'] ?? null);
        if ($credential === '' && $key === 'weatherapi') {
            $credential = trim((string) getenv('WEATHERAPI_KEY'));
        } elseif ($credential === '' && $key === 'brevo') {
            $credential = trim((string) getenv('BREVO_API_KEY'));
        }

        $parameters = json_decode((string) ($row['custom_parameters_json'] ?? '{}'), true);
        return [
            'display_name' => $defaults['display_name'],
            'endpoint_url' => $row['endpoint_url'] ?: $defaults['endpoint_url'],
            'category' => $defaults['category'],
            'auth_header_type' => $row['auth_header_type'] ?: $defaults['auth_header_type'],
            'auth_header_name' => $row['auth_header_name'] ?: $defaults['auth_header_name'],
            'credential' => $credential,
            'custom_parameters' => is_array($parameters) ? $parameters : [],
        ];
    }

    private function appendQueryParameters(string $url, array $parameters): string
    {
        if (!$parameters) {
            return $url;
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($parameters);
    }

    private function applyApiAuthentication(array $settings, string &$url, array &$headers): void
    {
        $type = strtolower((string) ($settings['auth_header_type'] ?? 'none'));
        $name = trim((string) ($settings['auth_header_name'] ?? ''));
        $credential = (string) ($settings['credential'] ?? '');
        if ($credential === '' || $type === 'none') {
            return;
        }
        if ($type === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $credential;
        } elseif ($type === 'query_param') {
            $url = $this->appendQueryParameters($url, [$name !== '' ? $name : 'api_key' => $credential]);
        } elseif ($type === 'custom_header' && $name !== '') {
            $headers[] = $name . ': ' . $credential;
        }
    }

    private function isProviderEnabled(string $key): bool
    {
        if (array_key_exists($key, $this->providerEnabledCache)) {
            return $this->providerEnabledCache[$key];
        }

        $this->providerEnabledCache[$key] = ExternalApiConfig::isEnabled($this->db, $key);
        return $this->providerEnabledCache[$key];
    }

    private function testProvider(string $key): int
    {
        $catalog = $this->providerCatalog();
        $settings = $this->systemApiSettings($key);
        $parameters = $settings['custom_parameters'];

        if ($key === 'weatherapi') {
            if ($settings['credential'] === '') {
                throw new RuntimeException('WeatherAPI is not configured. Set WEATHERAPI_KEY on the backend.');
            }
            $parameters['q'] = $parameters['q'] ?? '13.411,121.18';
        } elseif ($key === 'usgs') {
            $parameters += [
                'format' => 'geojson',
                'starttime' => date('Y-m-d', strtotime('-7 days')),
                'latitude' => self::DEFAULT_CENTER_LAT,
                'longitude' => self::DEFAULT_CENTER_LON,
                'maxradiuskm' => 300,
                'minmagnitude' => 2.5,
            ];
        } elseif ($key === 'gdacs' || $key === 'typhoon') {
            $parameters += [
                'fromdate' => date('Y-m-d', strtotime('-14 days')),
                'todate' => date('Y-m-d'),
            ];
        } elseif ($key === 'hdx') {
            $parameters += ['id' => 'cod-ab-phl'];
        } elseif ($key === 'nasa_eonet') {
            $parameters['limit'] = $parameters['limit'] ?? 1;
        } elseif ($key === 'brevo') {
            if ($settings['credential'] === '') {
                throw new RuntimeException('Brevo is not configured. Set BREVO_API_KEY on the backend.');
            }
        }

        $providerName = $key === 'nasa_eonet' ? 'NASA EONET' : $catalog[$key]['display_name'];
        if ($key === 'weatherapi') {
            $parameters += [
                'q' => '13.411,121.18',
                'days' => 3,
                'aqi' => 'no',
                'alerts' => 'no',
            ];
        }
        $response = $key === 'brevo'
            ? $this->requestConfiguredProvider($key, $parameters, null, 'https://api.brevo.com/v3/account')
            : $this->requestConfiguredProvider($key, $parameters);
        if (!is_string($response['body'])) {
            throw new RuntimeException(
                $providerName . ' connection failed: ' .
                ($response['error'] ?: 'Provider connection failed.')
            );
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Provider returned an invalid JSON response.');
        }

        if ($key === 'usgs') {
            return count(is_array($decoded['features'] ?? null) ? $decoded['features'] : []);
        }
        if ($key === 'gdacs' || $key === 'typhoon') {
            $features = $decoded['features'] ?? $decoded['events'] ?? [];
            if (!is_array($features)) {
                throw new RuntimeException('GDACS returned an unrecognized event list.');
            }
            if ($key === 'typhoon') {
                $features = array_filter($features, static function ($feature) {
                    $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                    return strtoupper((string) ($properties['eventtype'] ?? $properties['type'] ?? '')) === 'TC';
                });
            }
            return count($features);
        }
        if ($key === 'hdx') {
            if (($decoded['success'] ?? false) !== true) {
                throw new RuntimeException('HDX did not return the requested dataset metadata.');
            }
            return 1;
        }
        if ($key === 'nasa_eonet') {
            $events = is_array($decoded['events'] ?? null) ? $decoded['events'] : [];
            $weather = $this->httpRequest(
                'https://api.open-meteo.com/v1/forecast?' . http_build_query([
                    'latitude' => self::DEFAULT_CENTER_LAT,
                    'longitude' => self::DEFAULT_CENTER_LON,
                    'current' => 'temperature_2m',
                ]),
                ['Accept: application/json']
            );
            if (!is_string($weather['body']) || !is_array(json_decode($weather['body'], true))) {
                throw new RuntimeException(
                    'Open-Meteo connection failed: ' .
                    ($weather['error'] ?: 'Provider returned an invalid response.')
                );
            }
            return count($events);
        }
        if ($key === 'weatherapi') {
            if (isset($decoded['error'])) {
                throw new RuntimeException('WeatherAPI rejected the connection test.');
            }
            return 1;
        }
        if ($key === 'brevo') {
            if (isset($decoded['code'])) {
                throw new RuntimeException('Brevo rejected the connection test.');
            }
            return 1;
        }

        return 0;
    }

    private function saveSyncStatus(string $key, string $status, ?string $error, int $records): void
    {
        $provider = $this->providerCatalog()[$key];
        $this->db->raw(
            'INSERT INTO external_api_configs ' .
            '(api_key, display_name, endpoint_url, is_enabled, last_synced_at, last_status, last_error_message, rate_limit_until, records_cached, created_at, updated_at) ' .
            'VALUES (?, ?, ?, 1, NOW(), ?, ?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE last_synced_at = VALUES(last_synced_at), last_status = VALUES(last_status), ' .
            'last_error_message = VALUES(last_error_message), rate_limit_until = VALUES(rate_limit_until), ' .
            'records_cached = VALUES(records_cached), updated_at = NOW()',
            [$key, $provider['display_name'], $provider['endpoint_url'], $status, $error, $this->lastRetryAfterAt, $records]
        );
        $this->db->raw(
            'UPDATE system_apis SET last_status = ?, last_http_status = ?, last_latency_ms = ?, ' .
            'last_synced_at = NOW(), last_error_message = ?, rate_limit_until = ?, updated_at = NOW() WHERE api_key = ?',
            [
                $status,
                $this->lastHttpStatus,
                $this->lastLatencyMs,
                $error,
                $this->lastRetryAfterAt,
                $key,
            ]
        );
        $this->providerEnabledCache = [];
    }

    private function affectedArea(array $properties): ?string
    {
        $countries = $properties['affectedcountries'] ?? [];
        if (!is_array($countries)) {
            return null;
        }

        $names = [];
        foreach ($countries as $country) {
            if (!is_array($country)) {
                continue;
            }
            $name = trim((string) ($country['name'] ?? $country['country'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names ? implode(', ', array_unique($names)) : null;
    }

    private function extractCoordinates(array $geometry, array $properties): array
    {
        $coordinates = [];
        if (isset($geometry['coordinates']) && is_array($geometry['coordinates'])) {
            $coords = $geometry['coordinates'];
            while (is_array($coords) && count($coords) > 0) {
                if (isset($coords[0], $coords[1]) && is_numeric($coords[0]) && is_numeric($coords[1])) {
                    $coordinates['latitude'] = (float) $coords[1];
                    $coordinates['longitude'] = (float) $coords[0];
                    break;
                }
                $coords = reset($coords);
            }
        }

        if (empty($coordinates) && isset($properties['latitude'], $properties['longitude'])) {
            $coordinates['latitude'] = $properties['latitude'];
            $coordinates['longitude'] = $properties['longitude'];
        }

        return $coordinates;
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radiusKm = 6371;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $radiusKm * $c;
    }

    private function severityFromMagnitude(float $magnitude, string $alert): string
    {
        $normalized = strtolower($alert);
        if ($normalized === 'red') {
            return 'red';
        }
        if ($normalized === 'orange' || $normalized === 'yellow') {
            return 'orange';
        }
        if ($magnitude >= 5.5) {
            return 'red';
        }
        if ($magnitude >= 4.0) {
            return 'orange';
        }
        return 'green';
    }

}
