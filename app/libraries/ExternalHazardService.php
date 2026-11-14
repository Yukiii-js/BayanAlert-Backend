<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ExternalHazardService
{
    private const DEFAULT_CENTER_LAT = 13.4117;
    private const DEFAULT_CENTER_LON = 121.1803;
    private $providerStatus = [];

    public function getResidentFeed(): array
    {
        $usgsEvents = $this->fetchUsgsEvents();
        $gdacsEvents = $this->fetchGdacsEvents();
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
            ],
            'layers' => [
                'usgs' => [
                    'enabled' => true,
                    'label' => 'USGS Seismic Epicenters',
                    'count' => count($usgsEvents),
                ],
                'gdacs' => [
                    'enabled' => true,
                    'label' => 'GDACS Regional Alerts',
                    'count' => count($gdacsEvents),
                ],
                'hdx' => [
                    'enabled' => true,
                    'label' => 'HDX Barangay Boundaries & Exposure',
                    'count' => 3,
                ],
            ],
            'events' => $allEvents,
        ];
    }

    public function getAdminSummary(): array
    {
        $feed = $this->getResidentFeed();
        $configs = [
            'usgs' => [
                'api_key' => 'usgs',
                'display_name' => 'USGS Earthquake API',
                'status' => 'online',
                'last_synced_at' => gmdate('c'),
                'records_cached' => $feed['summary']['usgs'],
                'is_enabled' => true,
                'min_magnitude' => 2.5,
                'bounding_box_json' => ['latitude' => 13.4117, 'longitude' => 121.1803, 'radius_km' => 300],
            ],
            'gdacs' => [
                'api_key' => 'gdacs',
                'display_name' => 'GDACS Multi-Hazard API',
                'status' => 'online',
                'last_synced_at' => gmdate('c'),
                'records_cached' => $feed['summary']['gdacs'],
                'is_enabled' => true,
                'alert_levels' => ['Green', 'Orange', 'Red'],
                'bounding_box_json' => ['latitude' => 13.4117, 'longitude' => 121.1803, 'radius_km' => 500],
            ],
            'hdx' => [
                'api_key' => 'hdx',
                'display_name' => 'UN OCHA HDX API',
                'status' => 'online',
                'last_synced_at' => gmdate('c'),
                'records_cached' => 3,
                'is_enabled' => true,
                'datasets' => ['admin_boundaries', 'population_exposure', 'hazard_zones'],
            ],
        ];

        return [
            'generated_at' => gmdate('c'),
            'health' => $configs,
            'counts' => $feed['summary'],
        ];
    }

    public function sync(string $key): array
    {
        $normalized = strtolower(trim($key));
        $feed = $this->getResidentFeed();

        if ($normalized === 'usgs') {
            return ['api_key' => 'usgs', 'status' => 'success', 'records_cached' => $feed['summary']['usgs']];
        }

        if ($normalized === 'gdacs') {
            return ['api_key' => 'gdacs', 'status' => 'success', 'records_cached' => $feed['summary']['gdacs']];
        }

        if ($normalized === 'hdx') {
            return ['api_key' => 'hdx', 'status' => 'success', 'records_cached' => 3];
        }

        throw new InvalidArgumentException('Unsupported external API key: ' . $key);
    }

    public function updateConfig(string $key, array $input): array
    {
        $normalized = strtolower(trim($key));
        $payload = [
            'api_key' => $normalized,
            'display_name' => $this->humanizeKey($normalized),
            'updated_at' => gmdate('c'),
        ];

        foreach ($input as $field => $value) {
            $payload[$field] = $value;
        }

        return $payload;
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
        $response = $this->httpGet('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?fromdate=' . urlencode(date('Y-m-d', strtotime('-7 days'))) . '&todate=' . urlencode(date('Y-m-d')) . '&alertlevel=Green&iso3=PHL');

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
            $eventType = strtoupper((string) ($properties['eventtype'] ?? $properties['type'] ?? 'TC'));
            $alert = strtoupper((string) ($properties['alertlevel'] ?? 'GREEN'));
            $score = (float) ($properties['severitydata']['severity'] ?? $properties['severity'] ?? $properties['alertscore'] ?? $properties['score'] ?? 0);
            $distance = self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON);
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
            if (!$isPhilippinesEvent && $distance > 500) {
                continue;
            }
            $providerUrl = $properties['url'] ?? null;
            $eventUrl = is_array($providerUrl)
                ? ($providerUrl['report'] ?? $providerUrl['details'] ?? null)
                : (is_string($providerUrl) ? $providerUrl : null);

            $events[] = [
                'id' => 'gdacs-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($properties['eventid'] ?? $properties['name'] ?? uniqid('gdacs', true))),
                'source' => 'GDACS',
                'hazard_type' => $eventType,
                'title' => (string) ($properties['name'] ?? 'Regional hazard alert'),
                'severity_level' => strtolower((string) ($alert === 'RED' ? 'red' : ($alert === 'ORANGE' ? 'orange' : 'green'))),
                'magnitude_or_score' => $score,
                'depth_km' => null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_municipality_km' => round($distance, 2),
                'affected_population' => (int) ($properties['population'] ?? 0),
                'event_timestamp' => gmdate('c', strtotime((string) ($properties['fromdate'] ?? gmdate('c')))),
                'alert_level' => $alert,
                'event_url' => $eventUrl ?? $properties['urlreport'] ?? null,
                'is_published_to_residents' => ($distance <= 500 || $isPhilippinesEvent) ? 1 : 0,
                'raw_payload' => $feature,
            ];
        }

        $this->providerStatus['gdacs'] = ['status' => 'online', 'message' => null];
        return $events;
    }

    private function httpGet(string $url): ?string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'BayanAlertExternalHazards/1.0',
            CURLOPT_HEADER => false,
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (!is_string($body) || $body === '' || $error !== '' || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        return $body;
    }

    private function extractCoordinates(array $geometry, array $properties): array
    {
        $coordinates = [];
        if (isset($geometry['coordinates']) && is_array($geometry['coordinates'])) {
            $coords = $geometry['coordinates'];
            if (isset($coords[1]) && isset($coords[0])) {
                $coordinates['latitude'] = $coords[1];
                $coordinates['longitude'] = $coords[0];
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

    private function humanizeKey(string $key): string
    {
        $map = [
            'usgs' => 'USGS Earthquake API',
            'gdacs' => 'GDACS Multi-Hazard API',
            'hdx' => 'UN OCHA HDX API',
        ];

        return $map[$key] ?? ucfirst($key);
    }
}
