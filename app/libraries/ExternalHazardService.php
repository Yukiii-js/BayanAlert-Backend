<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExternalApiConfig.php';

class ExternalHazardService
{
    private const DEFAULT_CENTER_LAT = 13.4117;
    private const DEFAULT_CENTER_LON = 121.1803;
    private $providerStatus = [];
    private $db;
    private $providerEnabledCache = [];

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
                'endpoint_url' => 'https://api.weatherapi.com/v1/current.json',
            ],
            'usgs' => [
                'display_name' => 'USGS Earthquake API',
                'endpoint_url' => 'https://earthquake.usgs.gov/fdsnws/event/1/query',
            ],
            'gdacs' => [
                'display_name' => 'GDACS Multi-Hazard API',
                'endpoint_url' => 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH',
            ],
            'typhoon' => [
                'display_name' => 'Typhoon / Tropical Cyclone Feed',
                'endpoint_url' => 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH',
            ],
            'hdx' => [
                'display_name' => 'UN OCHA HDX API',
                'endpoint_url' => 'https://data.humdata.org/api/3/action/package_show',
            ],
            'nasa_eonet' => [
                'display_name' => 'NASA EONET / Open-Meteo',
                'endpoint_url' => 'https://eonet.gsfc.nasa.gov/api/v3/events',
            ],
            'brevo' => [
                'display_name' => 'Brevo Email API',
                'endpoint_url' => 'https://api.brevo.com/v3/account',
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
                    "INSERT INTO notifications (user_id, report_id, title, message, notification_type, is_read, created_at) " .
                    "SELECT u.user_id, NULL, ?, ?, 'external_hazard', 0, NOW() " .
                    "FROM users u INNER JOIN roles r ON r.role_id = u.role_id " .
                    "WHERE r.role_name IN ('resident', 'user') AND u.account_status = 'active'",
                    [mb_substr($title, 0, 200), mb_substr($message, 0, 5000)]
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
        $rows = $this->db->raw(
            'SELECT api_key, display_name, endpoint_url, is_enabled, last_synced_at, last_status, ' .
            'last_error_message, records_cached FROM external_api_configs'
        )->fetchAll(PDO::FETCH_ASSOC);
        $saved = [];
        foreach ($rows as $row) {
            $saved[$row['api_key']] = $row;
        }

        $health = [];
        foreach ($this->providerCatalog() as $key => $defaults) {
            $row = $saved[$key] ?? [];
            $enabled = !array_key_exists('is_enabled', $row) || (int) $row['is_enabled'] === 1;
            $health[$key] = [
                'api_key' => $key,
                'display_name' => $row['display_name'] ?? $defaults['display_name'],
                'endpoint_url' => ($row['endpoint_url'] ?? null) ?: $defaults['endpoint_url'],
                'status' => $enabled ? (string) ($row['last_status'] ?? 'idle') : 'disabled',
                'last_status' => (string) ($row['last_status'] ?? 'idle'),
                'last_synced_at' => $row['last_synced_at'] ?? null,
                'last_error_message' => $row['last_error_message'] ?? null,
                'records_cached' => (int) ($row['records_cached'] ?? 0),
                'is_enabled' => $enabled,
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

        try {
            $records = $this->testProvider($normalized);
            $this->saveSyncStatus($normalized, 'online', null, $records);
            return [
                'api_key' => $normalized,
                'status' => 'success',
                'records_cached' => $records,
                'last_synced_at' => gmdate('c'),
            ];
        } catch (Throwable $exception) {
            $this->saveSyncStatus($normalized, 'error', $exception->getMessage(), 0);
            throw $exception;
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
        $this->db->raw(
            'INSERT INTO external_api_configs (api_key, display_name, endpoint_url, is_enabled, last_status, created_at, updated_at) ' .
            'VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), endpoint_url = VALUES(endpoint_url), ' .
            'is_enabled = VALUES(is_enabled), updated_at = NOW()',
            [$normalized, $defaults['display_name'], $defaults['endpoint_url'], $enabled, 'idle']
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

        $response = $this->httpGet('https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&starttime=' . urlencode(date('Y-m-d', strtotime('-7 days'))) . '&latitude=13.4117&longitude=121.1803&maxradiuskm=300&minmagnitude=2.5');

        if (!$response) {
            $this->providerStatus['usgs'] = ['status' => 'error', 'message' => 'USGS could not be reached.'];
            return [];
        }

        $decoded = json_decode($response, true);
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

        $response = $this->httpGet('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?fromdate=' . urlencode(date('Y-m-d', strtotime('-14 days'))) . '&todate=' . urlencode(date('Y-m-d')));

        if (!$response) {
            $this->providerStatus['gdacs'] = ['status' => 'error', 'message' => 'GDACS could not be reached.'];
            return [];
        }

        $decoded = json_decode($response, true);
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

    private function httpGet(string $url): ?string
    {
        $result = $this->httpRequest($url);
        return $result['body'];
    }

    private function httpRequest(string $url, array $headers = []): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return ['body' => null, 'status' => 0, 'error' => 'Unable to initialize the provider request.'];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'BayanAlertExternalHazards/1.0',
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($body) || $body === '' || $error !== '' || $statusCode < 200 || $statusCode >= 300) {
            return [
                'body' => null,
                'status' => $statusCode,
                'error' => $error !== '' ? $error : 'Provider returned HTTP ' . $statusCode . '.',
            ];
        }

        return ['body' => $body, 'status' => $statusCode, 'error' => null];
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
        $headers = ['Accept: application/json'];
        $url = $catalog[$key]['endpoint_url'];

        if ($key === 'weatherapi') {
            $apiKey = trim((string) getenv('WEATHERAPI_KEY'));
            if ($apiKey === '') {
                throw new RuntimeException('WeatherAPI is not configured. Set WEATHERAPI_KEY on the backend.');
            }
            $url .= '?' . http_build_query(['key' => $apiKey, 'q' => '13.411,121.18']);
        } elseif ($key === 'usgs') {
            $url .= '?' . http_build_query([
                'format' => 'geojson',
                'starttime' => date('Y-m-d', strtotime('-7 days')),
                'latitude' => self::DEFAULT_CENTER_LAT,
                'longitude' => self::DEFAULT_CENTER_LON,
                'maxradiuskm' => 300,
                'minmagnitude' => 2.5,
            ]);
        } elseif ($key === 'gdacs' || $key === 'typhoon') {
            $url .= '?' . http_build_query([
                'fromdate' => date('Y-m-d', strtotime('-14 days')),
                'todate' => date('Y-m-d'),
            ]);
        } elseif ($key === 'hdx') {
            $url .= '?' . http_build_query(['id' => 'cod-ab-phl']);
        } elseif ($key === 'nasa_eonet') {
            $url = 'https://eonet.gsfc.nasa.gov/api/v3/events?limit=1';
        } elseif ($key === 'brevo') {
            $apiKey = trim((string) getenv('BREVO_API_KEY'));
            if ($apiKey === '') {
                throw new RuntimeException('Brevo is not configured. Set BREVO_API_KEY on the backend.');
            }
            $headers[] = 'api-key: ' . $apiKey;
        }

        $response = $this->httpRequest($url, $headers);
        if (!is_string($response['body'])) {
            throw new RuntimeException($response['error'] ?: 'Provider connection failed.');
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
                $headers
            );
            if (!is_string($weather['body']) || !is_array(json_decode($weather['body'], true))) {
                throw new RuntimeException($weather['error'] ?: 'Open-Meteo connection failed.');
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
            '(api_key, display_name, endpoint_url, is_enabled, last_synced_at, last_status, last_error_message, records_cached, created_at, updated_at) ' .
            'VALUES (?, ?, ?, 1, NOW(), ?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE last_synced_at = VALUES(last_synced_at), last_status = VALUES(last_status), ' .
            'last_error_message = VALUES(last_error_message), records_cached = VALUES(records_cached), updated_at = NOW()',
            [$key, $provider['display_name'], $provider['endpoint_url'], $status, $error, $records]
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
