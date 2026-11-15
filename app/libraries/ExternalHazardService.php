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
    private $lastDiagnostics = [];

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
                'display_name' => 'NASA EONET',
                'endpoint_url' => 'https://eonet.gsfc.nasa.gov/api/v3/events',
                'category' => 'Hazard',
                'auth_header_type' => 'none',
                'auth_header_name' => null,
            ],
            'reliefweb' => [
                'display_name' => 'ReliefWeb Disasters API',
                'endpoint_url' => 'https://api.reliefweb.int/v2/disasters',
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

    private function providerResponseError(string $key, array $response): string
    {
        if ($key === 'reliefweb' && (int) ($response['status'] ?? 0) === 403) {
            return 'ReliefWeb requires a pre-approved appname. Request one from ReliefWeb, then set it in API Management > Edit > Custom Parameters JSON as {"appname":"your-approved-appname"}.';
        }
        if ($key === 'reliefweb' && (int) ($response['status'] ?? 0) === 410) {
            return 'ReliefWeb v1 is retired. Use the v2/disasters endpoint; the saved legacy endpoint will be updated automatically.';
        }
        return (string) ($response['error'] ?? '') ?: 'Provider connection failed.';
    }

    public function getResidentFeed(): array
    {
        $this->ensureSystemApiRows();
        foreach (['usgs', 'gdacs', 'typhoon', 'reliefweb'] as $key) {
            if (!$this->isProviderEnabled($key) || !$this->isSyncDue($key)) {
                continue;
            }
            try {
                $this->sync($key);
            } catch (Throwable $exception) {
                error_log('Scheduled external hazard sync failed for ' . $key . ': ' . $exception->getMessage());
            }
        }

        $allEvents = array_values(array_filter($this->getCachedHazardEvents(), function ($event) {
            $source = strtoupper((string) ($event['source'] ?? ''));
            if ($source === 'USGS') return $this->isProviderEnabled('usgs');
            if ($source === 'RELIEFWEB') return $this->isProviderEnabled('reliefweb');
            if ($source === 'GDACS') {
                return strtoupper((string) ($event['hazard_type'] ?? '')) === 'TC'
                    ? $this->isProviderEnabled('typhoon')
                    : $this->isProviderEnabled('gdacs');
            }
            return false;
        }));
        $providerStatus = $this->getResidentProviderStatuses($allEvents);

        usort($allEvents, static function ($left, $right) {
            $leftTime = strtotime((string) ($left['event_timestamp'] ?? '1970-01-01T00:00:00Z'));
            $rightTime = strtotime((string) ($right['event_timestamp'] ?? '1970-01-01T00:00:00Z'));
            return $rightTime <=> $leftTime;
        });

        return [
            'generated_at' => gmdate('c'),
            'providers' => $providerStatus,
            'summary' => [
                'total' => count($allEvents),
                'usgs' => count(array_filter($allEvents, static function ($event) {
                    return strtoupper((string) ($event['source'] ?? '')) === 'USGS';
                })),
                'gdacs' => count(array_filter($allEvents, static function ($event) {
                    return strtoupper((string) ($event['source'] ?? '')) === 'GDACS'
                        && strtoupper((string) ($event['hazard_type'] ?? '')) !== 'TC';
                })),
                'typhoon' => count(array_filter($allEvents, static function ($event) {
                    return strtoupper((string) ($event['source'] ?? '')) === 'GDACS'
                        && strtoupper((string) ($event['hazard_type'] ?? '')) === 'TC';
                })),
                'reliefweb' => count(array_filter($allEvents, static function ($event) {
                    return strtoupper((string) ($event['source'] ?? '')) === 'RELIEFWEB';
                })),
            ],
            'layers' => [
                'usgs' => [
                    'enabled' => $this->isProviderEnabled('usgs'),
                    'label' => 'USGS Seismic Epicenters',
                    'count' => count(array_filter($allEvents, static function ($event) {
                        return strtoupper((string) ($event['source'] ?? '')) === 'USGS';
                    })),
                ],
                'gdacs' => [
                    'enabled' => $this->isProviderEnabled('gdacs'),
                    'label' => 'GDACS Regional Alerts',
                    'count' => count(array_filter($allEvents, static function ($event) {
                        return strtoupper((string) ($event['source'] ?? '')) === 'GDACS';
                    })),
                ],
                'reliefweb' => [
                    'enabled' => $this->isProviderEnabled('reliefweb'),
                    'label' => 'ReliefWeb Philippines Disasters',
                    'count' => count(array_filter($allEvents, static function ($event) {
                        return strtoupper((string) ($event['source'] ?? '')) === 'RELIEFWEB';
                    })),
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

    private function isSyncDue(string $key): bool
    {
        $row = $this->db->raw(
            'SELECT sync_interval_minutes, last_synced_at, rate_limit_until FROM system_apis WHERE api_key = ? LIMIT 1',
            [$key]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }

        $rateLimitUntil = !empty($row['rate_limit_until']) ? strtotime((string) $row['rate_limit_until']) : false;
        if ($rateLimitUntil !== false && $rateLimitUntil > time()) {
            return false;
        }
        if (empty($row['last_synced_at'])) {
            return true;
        }

        $lastSynced = strtotime((string) $row['last_synced_at']);
        $interval = max(1, (int) ($row['sync_interval_minutes'] ?? 60));
        return $lastSynced === false || $lastSynced + $interval * 60 <= time();
    }

    private function getCachedHazardEvents(): array
    {
        $rows = $this->db->raw(
            'SELECT id, source, external_event_id, hazard_type, title, severity_level, magnitude_or_score, depth_km, ' .
            'latitude, longitude, distance_to_municipality_km, affected_population, event_timestamp, raw_payload_json, ' .
            'is_published_to_residents FROM external_hazard_events ' .
            'WHERE event_timestamp >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)'
        )->fetchAll(PDO::FETCH_ASSOC);

        $events = [];
        foreach ($rows as $row) {
            $rawPayload = json_decode((string) ($row['raw_payload_json'] ?? ''), true);
            $rawPayload = is_array($rawPayload) ? $rawPayload : [];
            $properties = is_array($rawPayload['properties'] ?? null)
                ? $rawPayload['properties']
                : (is_array($rawPayload['fields'] ?? null) ? $rawPayload['fields'] : []);
            $eventTimestamp = strtotime((string) $row['event_timestamp']);
            $source = strtoupper((string) $row['source']);
            $hazardType = strtoupper((string) $row['hazard_type']);
            if (!$this->isStoredEventRecent($source, $hazardType, $eventTimestamp, $properties)) {
                continue;
            }
            $latitude = $row['latitude'] === null ? null : (float) $row['latitude'];
            $longitude = $row['longitude'] === null ? null : (float) $row['longitude'];
            $distance = $row['distance_to_municipality_km'] === null
                ? (($latitude !== null && $longitude !== null)
                    ? self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON)
                    : null)
                : (float) $row['distance_to_municipality_km'];
            $alert = strtoupper((string) ($properties['alertlevel'] ?? $properties['alert'] ?? ''));
            if ($alert === '') {
                $alert = $source === 'RELIEFWEB'
                    ? 'INFORMATION'
                    : strtoupper((string) ($row['severity_level'] ?? 'GREEN'));
            }
            $area = $this->affectedArea($properties) ?? $this->cachedArea($properties);
            $event = [
                'id' => (string) $row['external_event_id'],
                'advisory_id' => (string) $row['external_event_id'],
                'source' => (string) $row['source'],
                'hazard_type' => (string) $row['hazard_type'],
                'title' => (string) $row['title'],
                'severity_level' => (string) ($row['severity_level'] ?? 'green'),
                'magnitude_or_score' => $row['magnitude_or_score'] === null ? null : (float) $row['magnitude_or_score'],
                'depth_km' => $row['depth_km'] === null ? null : (float) $row['depth_km'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_municipality_km' => $distance === null ? null : round($distance, 2),
                'affected_population' => $row['affected_population'] === null ? null : (int) $row['affected_population'],
                'event_timestamp' => gmdate('c', $eventTimestamp),
                'alert_level' => $alert,
                'is_philippines_event' => $this->isPhilippinesEvent($properties, $latitude, $longitude),
                'affected_area' => $area,
                'event_url' => $this->cachedEventUrl($properties),
                'is_published_to_residents' => (int) $row['is_published_to_residents'],
                'raw_payload' => $rawPayload,
            ];
            $events[] = $event;
        }

        return $events;
    }

    private function getResidentProviderStatuses(array $events): array
    {
        $rows = $this->db->raw(
            'SELECT api_key, last_status, last_http_status, last_latency_ms, rate_limit_until, last_synced_at, last_error_message ' .
            'FROM system_apis'
        )->fetchAll(PDO::FETCH_ASSOC);
        $statusByKey = [];
        foreach ($rows as $row) {
            $key = (string) $row['api_key'];
            if (!in_array($key, ['usgs', 'gdacs', 'typhoon', 'reliefweb'], true)) {
                continue;
            }
            $enabled = $this->isProviderEnabled($key);
            $status = !$enabled ? 'disabled' : (string) ($row['last_status'] ?? 'idle');
            $message = null;
            if ($enabled && $status === 'error') {
                $rateLimit = !empty($row['rate_limit_until']) ? strtotime((string) $row['rate_limit_until']) : false;
                $message = $rateLimit !== false && $rateLimit > time()
                    ? 'Limit reached'
                    : 'This source is temporarily unavailable.';
            }
            $sourceEvents = array_values(array_filter($events, static function ($event) use ($key) {
                $source = strtoupper((string) ($event['source'] ?? ''));
                if ($key === 'usgs') return $source === 'USGS';
                if ($key === 'reliefweb') return $source === 'RELIEFWEB';
                if ($key === 'gdacs' || $key === 'typhoon') return $source === 'GDACS';
                return false;
            }));
            if ($key === 'typhoon') {
                $sourceEvents = array_values(array_filter($sourceEvents, static function ($event) {
                    return strtoupper((string) ($event['hazard_type'] ?? '')) === 'TC';
                }));
            } elseif ($key === 'gdacs') {
                $sourceEvents = array_values(array_filter($sourceEvents, static function ($event) {
                    return strtoupper((string) ($event['hazard_type'] ?? '')) !== 'TC';
                }));
            }
            $statusByKey[$key] = [
                'status' => $status,
                'message' => $message,
                'last_synced_at' => $row['last_synced_at'],
                'last_http_status' => $row['last_http_status'] === null ? null : (int) $row['last_http_status'],
                'last_latency_ms' => $row['last_latency_ms'] === null ? null : (int) $row['last_latency_ms'],
                'rate_limit_until' => $row['rate_limit_until'],
                'records_fetched' => count($sourceEvents),
                'records_matched_region' => $this->countRegionallyRelevant($sourceEvents),
            ];
        }

        foreach (['usgs', 'gdacs', 'typhoon', 'reliefweb'] as $key) {
            if (isset($statusByKey[$key])) {
                continue;
            }
            $statusByKey[$key] = [
                'status' => $this->isProviderEnabled($key) ? 'idle' : 'disabled',
                'message' => null,
                'last_synced_at' => null,
                'last_http_status' => null,
                'last_latency_ms' => null,
                'rate_limit_until' => null,
                'records_fetched' => 0,
                'records_matched_region' => 0,
            ];
        }

        return $statusByKey;
    }

    private function getCachedProviderCounts(): array
    {
        $rows = $this->db->raw(
            'SELECT source, hazard_type, distance_to_municipality_km, event_timestamp, raw_payload_json ' .
            'FROM external_hazard_events WHERE event_timestamp >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)'
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) {
            $source = strtoupper((string) $row['source']);
            $hazardType = strtoupper((string) $row['hazard_type']);
            $rawPayload = json_decode((string) ($row['raw_payload_json'] ?? ''), true);
            $properties = is_array($rawPayload['properties'] ?? null)
                ? $rawPayload['properties']
                : (is_array($rawPayload['fields'] ?? null) ? $rawPayload['fields'] : []);
            $timestamp = strtotime((string) $row['event_timestamp']);
            if (!$this->isStoredEventRecent($source, $hazardType, $timestamp, $properties)) {
                continue;
            }
            if ($source === 'USGS') {
                $key = 'usgs';
            } elseif ($source === 'RELIEFWEB') {
                $key = 'reliefweb';
            } elseif ($source === 'GDACS') {
                $key = $hazardType === 'TC' ? 'typhoon' : 'gdacs';
            } else {
                continue;
            }
            if (!isset($counts[$key])) {
                $counts[$key] = ['total' => 0, 'regional' => 0];
            }
            $counts[$key]['total']++;
            $distance = $row['distance_to_municipality_km'] === null
                ? null
                : (float) $row['distance_to_municipality_km'];
            $area = $this->affectedArea($properties) ?? $this->cachedArea($properties);
            if ($this->isRegionallyRelevant([
                'distance_to_municipality_km' => $distance,
                'affected_area' => $area,
            ])) {
                $counts[$key]['regional']++;
            }
        }

        return $counts;
    }

    private function isStoredEventRecent(string $source, string $hazardType, $timestamp, array $properties): bool
    {
        if (!is_int($timestamp) || $timestamp === false) {
            return false;
        }
        $days = $source === 'USGS'
            ? 7
            : ($source === 'GDACS' && $hazardType === 'TC' ? 14 : ($source === 'GDACS' ? 30 : 60));
        if ($timestamp < time() - $days * 86400) {
            return false;
        }
        if ($source === 'GDACS') {
            $endDate = strtotime((string) ($properties['todate'] ?? ''));
            if ($endDate !== false && $endDate < strtotime('-2 days')) {
                return false;
            }
        }
        return true;
    }

    private function saveCachedHazardEvents(array $events): void
    {
        if (!$events) {
            return;
        }

        $this->db->raw('START TRANSACTION');
        try {
            foreach ($events as $event) {
                $timestamp = strtotime((string) ($event['event_timestamp'] ?? ''));
                $eventDate = $timestamp === false ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $timestamp);
                $externalId = $this->safeExternalId((string) ($event['id'] ?? uniqid('hazard', true)));
                $rawJson = json_encode(
                    $event['raw_payload'] ?? $event,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                $this->db->raw(
                    'INSERT INTO external_hazard_events ' .
                    '(source, external_event_id, hazard_type, title, severity_level, magnitude_or_score, depth_km, latitude, longitude, ' .
                    'distance_to_municipality_km, affected_population, event_timestamp, raw_payload_json, is_published_to_residents, created_at, updated_at) ' .
                    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ' .
                    'ON DUPLICATE KEY UPDATE source = VALUES(source), hazard_type = VALUES(hazard_type), title = VALUES(title), ' .
                    'severity_level = VALUES(severity_level), magnitude_or_score = VALUES(magnitude_or_score), depth_km = VALUES(depth_km), ' .
                    'latitude = VALUES(latitude), longitude = VALUES(longitude), distance_to_municipality_km = VALUES(distance_to_municipality_km), ' .
                    'affected_population = VALUES(affected_population), event_timestamp = VALUES(event_timestamp), ' .
                    'raw_payload_json = VALUES(raw_payload_json), updated_at = NOW()',
                    [
                        substr(strtoupper((string) ($event['source'] ?? 'UNKNOWN')), 0, 16),
                        substr($externalId, 0, 128),
                        substr(strtoupper((string) ($event['hazard_type'] ?? 'ALERT')), 0, 8),
                        substr((string) ($event['title'] ?? 'Hazard event'), 0, 255),
                        substr(strtolower((string) ($event['severity_level'] ?? 'green')), 0, 16),
                        isset($event['magnitude_or_score']) && is_numeric($event['magnitude_or_score'])
                            ? (float) $event['magnitude_or_score']
                            : null,
                        isset($event['depth_km']) && is_numeric($event['depth_km']) ? (float) $event['depth_km'] : null,
                        isset($event['latitude']) && is_numeric($event['latitude']) ? (float) $event['latitude'] : null,
                        isset($event['longitude']) && is_numeric($event['longitude']) ? (float) $event['longitude'] : null,
                        isset($event['distance_to_municipality_km']) && is_numeric($event['distance_to_municipality_km'])
                            ? (float) $event['distance_to_municipality_km']
                            : null,
                        isset($event['affected_population']) && is_numeric($event['affected_population'])
                            ? (int) $event['affected_population']
                            : null,
                        $eventDate,
                        is_string($rawJson) ? $rawJson : '{}',
                        !empty($event['is_published_to_residents']) ? 1 : 0,
                    ]
                );
            }
            $this->db->raw('COMMIT');
        } catch (Throwable $exception) {
            $this->db->raw('ROLLBACK');
            throw $exception;
        }
    }

    private function isPhilippinesCoordinate(?float $latitude, ?float $longitude): bool
    {
        return $latitude !== null && $longitude !== null
            && $latitude >= 4.0 && $latitude <= 22.0
            && $longitude >= 116.0 && $longitude <= 127.0;
    }

    private function isPhilippinesEvent(array $properties, ?float $latitude, ?float $longitude): bool
    {
        if ($this->isPhilippinesCoordinate($latitude, $longitude)) {
            return true;
        }
        foreach (['iso3', 'country_iso3'] as $key) {
            if (strtoupper((string) ($properties[$key] ?? '')) === 'PHL') {
                return true;
            }
        }
        foreach (['country', 'country_name', 'countryname'] as $key) {
            $value = $properties[$key] ?? null;
            if (is_string($value) && in_array(strtolower(trim($value)), ['philippines', 'phl'], true)) {
                return true;
            }
            if (is_array($value)) {
                foreach ($value as $country) {
                    if (!is_array($country)) {
                        if (strtolower(trim((string) $country)) === 'philippines') return true;
                        continue;
                    }
                    if (strtoupper((string) ($country['iso3'] ?? $country['code'] ?? '')) === 'PHL'
                        || strtolower(trim((string) ($country['name'] ?? $country['country'] ?? ''))) === 'philippines') {
                        return true;
                    }
                }
            }
        }
        $affectedCountries = $properties['affectedcountries'] ?? [];
        if (is_array($affectedCountries)) {
            foreach ($affectedCountries as $country) {
                if (!is_array($country)) {
                    if (strtolower(trim((string) $country)) === 'philippines') return true;
                    continue;
                }
                if (strtoupper((string) ($country['iso3'] ?? '')) === 'PHL'
                    || strtolower(trim((string) ($country['name'] ?? $country['country'] ?? ''))) === 'philippines') {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRegionallyRelevant(array $event): bool
    {
        $distance = $event['distance_to_municipality_km'] ?? null;
        if (is_numeric($distance) && (float) $distance <= 150) {
            return true;
        }
        $area = strtolower((string) ($event['affected_area'] ?? ''));
        return strpos($area, 'oriental mindoro') !== false || strpos($area, 'calapan') !== false;
    }

    private function countRegionallyRelevant(array $events): int
    {
        $count = 0;
        foreach ($events as $event) {
            if ($this->isRegionallyRelevant($event)) {
                $count++;
            }
        }
        return $count;
    }

    private function extractReliefWebCoordinates(array $fields): array
    {
        $candidates = [];
        $countries = is_array($fields['country'] ?? null) ? $fields['country'] : [];
        if (isset($countries['location'])) {
            $countries = [$countries];
        }
        foreach ($countries as $country) {
            if (is_array($country)) {
                if (isset($country['lat'], $country['lon'])) {
                    $candidates[] = $country;
                }
                if (is_array($country['location'] ?? null)) {
                    $candidates[] = $country['location'];
                }
            }
        }
        foreach (['location', 'primary_country'] as $key) {
            if (is_array($fields[$key] ?? null)) {
                $candidates[] = $fields[$key];
            }
        }
        foreach ($candidates as $candidate) {
            $latitude = $candidate['lat'] ?? $candidate['latitude'] ?? null;
            $longitude = $candidate['lon'] ?? $candidate['longitude'] ?? null;
            if (is_numeric($latitude) && is_numeric($longitude)) {
                return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
            }
            if (is_array($candidate['location'] ?? null)) {
                $nested = $candidate['location'];
                $latitude = $nested['lat'] ?? $nested['latitude'] ?? null;
                $longitude = $nested['lon'] ?? $nested['longitude'] ?? null;
                if (is_numeric($latitude) && is_numeric($longitude)) {
                    return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
                }
            }
        }

        return ['latitude' => null, 'longitude' => null];
    }

    private function reliefWebCountryNames(array $fields): array
    {
        $names = [];
        $countries = is_array($fields['country'] ?? null) ? $fields['country'] : [];
        if (isset($countries['name'])) {
            $countries = [$countries];
        }
        foreach ($countries as $country) {
            if (is_array($country)) {
                $name = trim((string) ($country['name'] ?? ''));
                if ($name !== '') $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    private function safeExternalId(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
        $safe = trim((string) $safe, '-_');
        return substr($safe !== '' ? $safe : uniqid('event', true), 0, 110);
    }

    private function cachedArea(array $properties): ?string
    {
        $countries = $properties['country'] ?? $properties['affectedcountries'] ?? [];
        $names = [];
        if (is_array($countries)) {
            foreach ($countries as $country) {
                if (is_array($country)) {
                    $name = trim((string) ($country['name'] ?? $country['country'] ?? ''));
                    if ($name !== '') $names[] = $name;
                } elseif (is_string($country) && trim($country) !== '') {
                    $names[] = trim($country);
                }
            }
        }
        $location = $properties['location'] ?? null;
        if (is_array($location)) {
            $locationName = $location['name'] ?? $location['name_en'] ?? null;
            foreach (['admin1', 'admin2', 'country'] as $key) {
                if ($locationName !== null) break;
                $nested = $location[$key] ?? null;
                $locationName = is_array($nested) ? ($nested['name'] ?? null) : $nested;
            }
            $location = $locationName;
        }
        if (is_string($location) && trim($location) !== '') {
            $names[] = trim($location);
        }
        if (!$names) {
            $name = trim((string) ($properties['name'] ?? ''));
            if ($name !== '') $names[] = $name;
        }
        return $names ? implode(', ', array_unique($names)) : null;
    }

    private function cachedEventUrl(array $properties): ?string
    {
        $url = $properties['url'] ?? $properties['urlreport'] ?? null;
        if (is_array($url)) {
            $url = $url['report'] ?? $url['details'] ?? null;
        }
        return is_string($url) && preg_match('/^https?:\/\//i', $url) ? $url : null;
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
        $cachedProviderCounts = $this->getCachedProviderCounts();
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
                'records_fetched' => (int) ($row['records_cached'] ?? 0),
                'records_matched_region' => (int) ($cachedProviderCounts[$key]['regional'] ?? 0),
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
                'records_fetched' => 0,
                'records_matched_region' => 0,
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
        $this->lastDiagnostics = [];
        $lockName = 'bayanalert_external_sync_' . $normalized;
        $lock = $this->db->raw('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])
            ->fetch(PDO::FETCH_ASSOC);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('An external API sync is already in progress.');
        }

        try {
            if (in_array($normalized, ['usgs', 'gdacs', 'typhoon', 'reliefweb'], true)) {
                $events = $this->fetchProviderEvents($normalized);
                if ($normalized === 'gdacs' || $normalized === 'typhoon') {
                    try {
                        $this->publishHighSeverityNotifications($events);
                    } catch (Throwable $notificationError) {
                        error_log('External advisory notifications could not be saved: ' . $notificationError->getMessage());
                    }
                }
                $this->saveCachedHazardEvents($events);
                $records = count($events);
                $this->lastDiagnostics[$normalized] = $this->lastDiagnostics[$normalized] ?? [
                    'records_fetched' => $records,
                    'records_matched_region' => $this->countRegionallyRelevant($events),
                ];
            } else {
                $records = $this->testProvider($normalized);
                $this->lastDiagnostics[$normalized] = [
                    'records_fetched' => $records,
                    'records_matched_region' => null,
                ];
            }
            $diagnostics = $this->lastDiagnostics[$normalized];
            $this->saveSyncStatus($normalized, 'online', null, $diagnostics['records_fetched']);
            return [
                'api_key' => $normalized,
                'status' => 'success',
                'records_cached' => $records,
                'records_fetched' => $diagnostics['records_fetched'],
                'records_matched_region' => $diagnostics['records_matched_region'],
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
        } finally {
            $this->db->raw('SELECT RELEASE_LOCK(?)', [$lockName]);
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

    private function fetchProviderEvents(string $key): array
    {
        if ($key === 'usgs') {
            return $this->fetchUsgsEvents();
        }
        if ($key === 'gdacs' || $key === 'typhoon') {
            return $this->fetchGdacsEvents($key);
        }
        if ($key === 'reliefweb') {
            return $this->fetchReliefWebEvents();
        }

        throw new InvalidArgumentException('Unsupported hazard feed: ' . $key);
    }

    private function fetchUsgsEvents(): array
    {
        $settings = $this->systemApiSettings('usgs');
        $parameters = $settings['custom_parameters'] + [
            'format' => 'geojson',
            'starttime' => gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400),
            'minmagnitude' => 2.5,
            'minlatitude' => 4.5,
            'maxlatitude' => 21.5,
            'minlongitude' => 116.0,
            'maxlongitude' => 127.0,
        ];
        $response = $this->requestConfiguredProvider('usgs', $parameters);
        if (!is_string($response['body'])) {
            throw new RuntimeException($response['error'] ?: 'USGS could not be reached.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !is_array($decoded['features'] ?? null)) {
            throw new RuntimeException('USGS returned an invalid GeoJSON response.');
        }

        $features = $decoded['features'];
        $events = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $coordinates = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];
            if (!isset($coordinates[0], $coordinates[1]) || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])) {
                continue;
            }
            $longitude = (float) $coordinates[0];
            $latitude = (float) $coordinates[1];
            if (!$this->isPhilippinesCoordinate($latitude, $longitude)) {
                continue;
            }
            $depthKm = (float) ($coordinates[2] ?? 0);
            $magnitude = (float) ($properties['mag'] ?? 0);
            $distance = self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON);
            $alert = strtoupper((string) ($properties['alert'] ?? 'GREEN'));
            $timestamp = (int) (($properties['time'] ?? time() * 1000) / 1000);
            $externalId = (string) ($feature['id'] ?? $properties['ids'] ?? uniqid('usgs', true));

            $events[] = [
                'id' => 'usgs-' . $this->safeExternalId($externalId),
                'source' => 'USGS',
                'hazard_type' => 'EQ',
                'title' => (string) ($properties['title'] ?? $properties['place'] ?? 'Seismic event'),
                'severity_level' => $this->severityFromMagnitude($magnitude, $alert),
                'magnitude_or_score' => $magnitude,
                'depth_km' => $depthKm,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_municipality_km' => round($distance, 2),
                'affected_population' => null,
                'event_timestamp' => gmdate('c', $timestamp),
                'alert_level' => $alert,
                'is_philippines_event' => true,
                'event_url' => $properties['url'] ?? null,
                'is_published_to_residents' => 1,
                'raw_payload' => $feature,
            ];
        }

        $this->lastDiagnostics['usgs'] = [
            'records_fetched' => count($features),
            'records_matched_region' => $this->countRegionallyRelevant($events),
        ];
        return $events;
    }

    private function fetchGdacsEvents(string $providerKey): array
    {
        $settings = $this->systemApiSettings($providerKey);
        $isTyphoonFeed = $providerKey === 'typhoon';
        $parameters = $settings['custom_parameters'] + [
            'eventlist' => $isTyphoonFeed ? 'TC' : 'EQ;TC;FL;VO',
            'alertlevel' => 'Green;Orange;Red',
            'fromdate' => gmdate('Y-m-d', time() - ($isTyphoonFeed ? 14 : 30) * 86400),
            'todate' => gmdate('Y-m-d'),
        ];
        $response = $this->requestConfiguredProvider($providerKey, $parameters);
        if (!is_string($response['body'])) {
            throw new RuntimeException($response['error'] ?: 'GDACS could not be reached.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GDACS returned invalid JSON.');
        }
        $features = $decoded['features'] ?? $decoded['events'] ?? null;
        if (!is_array($features)) {
            throw new RuntimeException('GDACS returned an unrecognized event list.');
        }

        $events = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $coordinates = $this->extractCoordinates($geometry, $properties);
            $latitude = isset($coordinates['latitude']) ? (float) $coordinates['latitude'] : null;
            $longitude = isset($coordinates['longitude']) ? (float) $coordinates['longitude'] : null;
            $typeValue = $properties['eventtype'] ?? $properties['type'] ?? 'ALERT';
            if (is_array($typeValue)) {
                $typeValue = $typeValue['code'] ?? $typeValue['name'] ?? 'ALERT';
            }
            $eventType = strtoupper((string) $typeValue);
            if ($isTyphoonFeed && $eventType !== 'TC') {
                continue;
            }
            $isPhilippinesEvent = $this->isPhilippinesEvent($properties, $latitude, $longitude);
            $isWithinPar = $eventType === 'TC' && $latitude !== null && $longitude !== null
                && $latitude >= 5 && $latitude <= 25 && $longitude >= 115 && $longitude <= 135;
            if (!$isPhilippinesEvent && !($isTyphoonFeed && $isWithinPar)) {
                continue;
            }
            $hasCoordinates = $latitude !== null && $longitude !== null
                && ($latitude !== 0.0 || $longitude !== 0.0);
            $distance = $hasCoordinates
                ? self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON)
                : null;
            $alert = strtoupper((string) ($properties['alertlevel'] ?? $properties['alertLevel'] ?? 'GREEN'));
            $endDate = strtotime((string) ($properties['todate'] ?? ''));
            if ($endDate !== false && $endDate < strtotime('-2 days')) {
                continue;
            }
            $severityData = is_array($properties['severitydata'] ?? null) ? $properties['severitydata'] : [];
            $score = (float) ($severityData['severity'] ?? $properties['severity'] ?? $properties['alertscore'] ?? $properties['score'] ?? 0);
            $providerUrl = $properties['url'] ?? null;
            $eventUrl = is_array($providerUrl)
                ? ($providerUrl['report'] ?? $providerUrl['details'] ?? null)
                : (is_string($providerUrl) ? $providerUrl : null);
            $startTimestamp = $properties['fromdate'] ?? $properties['datemodified'] ?? gmdate('c');
            $startEpoch = strtotime((string) $startTimestamp);
            $externalId = (string) ($properties['eventid'] ?? $properties['id'] ?? $feature['id'] ?? $properties['name'] ?? uniqid('gdacs', true));

            $events[] = [
                'id' => 'gdacs-' . $this->safeExternalId($externalId),
                'source' => 'GDACS',
                'hazard_type' => $eventType,
                'title' => (string) ($properties['name'] ?? $properties['title'] ?? 'Regional hazard alert'),
                'severity_level' => strtolower($alert === 'RED' ? 'red' : ($alert === 'ORANGE' ? 'orange' : 'green')),
                'magnitude_or_score' => $score,
                'depth_km' => null,
                'latitude' => $hasCoordinates ? $latitude : null,
                'longitude' => $hasCoordinates ? $longitude : null,
                'distance_to_municipality_km' => $distance === null ? null : round($distance, 2),
                'affected_population' => (int) ($properties['population'] ?? 0),
                'event_timestamp' => gmdate('c', $startEpoch === false ? time() : $startEpoch),
                'alert_level' => $alert,
                'is_philippines_event' => $isPhilippinesEvent,
                'affected_area' => $this->affectedArea($properties),
                'event_url' => $eventUrl ?? $properties['urlreport'] ?? null,
                'is_published_to_residents' => 1,
                'raw_payload' => $feature,
            ];
        }

        $this->lastDiagnostics[$providerKey] = [
            'records_fetched' => count($features),
            'records_matched_region' => $this->countRegionallyRelevant($events),
        ];
        return $events;
    }

    private function fetchReliefWebEvents(): array
    {
        $settings = $this->systemApiSettings('reliefweb');
        $parameters = $settings['custom_parameters'] + [
            'appname' => 'bayanalert',
            'profile' => 'full',
            'preset' => 'latest',
            'filter[field]' => 'country.iso3',
            'filter[value]' => 'PHL',
        ];
        $response = $this->requestConfiguredProvider('reliefweb', $parameters);
        if (!is_string($response['body'])) {
            throw new RuntimeException($this->providerResponseError('reliefweb', $response));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new RuntimeException('ReliefWeb returned an invalid response.');
        }

        $events = [];
        foreach ($decoded['data'] as $record) {
            if (!is_array($record) || !is_array($record['fields'] ?? null)) {
                continue;
            }
            $fields = $record['fields'];
            $coordinates = $this->extractReliefWebCoordinates($fields);
            $latitude = $coordinates['latitude'];
            $longitude = $coordinates['longitude'];
            if (!$this->isPhilippinesEvent($fields, $latitude, $longitude)) {
                continue;
            }
            $hasCoordinates = $latitude !== null && $longitude !== null
                && ($latitude !== 0.0 || $longitude !== 0.0);
            $distance = $hasCoordinates
                ? self::haversineKm($latitude, $longitude, self::DEFAULT_CENTER_LAT, self::DEFAULT_CENTER_LON)
                : null;
            $types = is_array($fields['type'] ?? null) ? $fields['type'] : [];
            $typeName = isset($types[0]['name'])
                ? (string) $types[0]['name']
                : (string) ($types['name'] ?? 'Disaster');
            $dateFields = is_array($fields['date'] ?? null) ? $fields['date'] : [];
            $date = $dateFields['event'] ?? $dateFields['created'] ?? $dateFields['changed'] ?? null;
            $timestamp = is_string($date) ? strtotime($date) : false;
            $externalId = (string) ($record['id'] ?? $fields['id'] ?? uniqid('reliefweb', true));
            $title = (string) ($fields['name'] ?? $fields['title'] ?? 'Philippines disaster report');
            $countryNames = $this->reliefWebCountryNames($fields);

            $events[] = [
                'id' => 'reliefweb-' . $this->safeExternalId($externalId),
                'source' => 'RELIEFWEB',
                'hazard_type' => strtoupper(substr($typeName, 0, 8)),
                'title' => $title,
                'severity_level' => 'green',
                'magnitude_or_score' => null,
                'depth_km' => null,
                'latitude' => $hasCoordinates ? $latitude : null,
                'longitude' => $hasCoordinates ? $longitude : null,
                'distance_to_municipality_km' => $distance === null ? null : round($distance, 2),
                'affected_population' => null,
                'event_timestamp' => gmdate('c', $timestamp === false ? time() : $timestamp),
                'alert_level' => 'INFORMATION',
                'is_philippines_event' => true,
                'affected_area' => $countryNames ? implode(', ', $countryNames) : 'Philippines',
                'event_url' => $fields['url'] ?? null,
                'is_published_to_residents' => 1,
                'raw_payload' => $record,
            ];
        }

        $this->lastDiagnostics['reliefweb'] = [
            'records_fetched' => count($decoded['data']),
            'records_matched_region' => $this->countRegionallyRelevant($events),
        ];
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

        $retiredReliefWebEndpoint = 'https://api.reliefweb.int/v1/disasters';
        $currentReliefWebEndpoint = $this->providerCatalog()['reliefweb']['endpoint_url'];
        $this->db->raw(
            'UPDATE external_api_configs SET endpoint_url = ?, updated_at = NOW() ' .
            'WHERE api_key = ? AND endpoint_url = ?',
            [$currentReliefWebEndpoint, 'reliefweb', $retiredReliefWebEndpoint]
        );
        $this->db->raw(
            'UPDATE system_apis SET endpoint_url = ?, updated_at = NOW() ' .
            'WHERE api_key = ? AND endpoint_url = ?',
            [$currentReliefWebEndpoint, 'reliefweb', $retiredReliefWebEndpoint]
        );
    }

    private function systemApiSettings(string $key): array
    {
        $defaults = $this->providerCatalog()[$key];
        try {
            $row = $this->db->raw(
                'SELECT endpoint_url, credential_encrypted, auth_header_type, auth_header_name, custom_parameters_json ' .
                'FROM system_apis WHERE api_key = ? LIMIT 1',
                [$key]
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $errorInfo = $exception instanceof PDOException ? ($exception->errorInfo ?? []) : [];
            $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
            $driverCode = (int) ($errorInfo[1] ?? 0);
            if ($sqlState !== '42S02' && $driverCode !== 1146) {
                throw $exception;
            }

            error_log('System API registry table is missing; using environment-backed provider settings.');
            $row = null;
        }
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
                'starttime' => gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400),
                'minmagnitude' => 2.5,
                'minlatitude' => 4.5,
                'maxlatitude' => 21.5,
                'minlongitude' => 116.0,
                'maxlongitude' => 127.0,
            ];
        } elseif ($key === 'gdacs' || $key === 'typhoon') {
            $parameters += [
                'eventlist' => $key === 'typhoon' ? 'TC' : 'EQ;TC;FL;VO',
                'alertlevel' => 'Green;Orange;Red',
                'fromdate' => gmdate('Y-m-d', time() - ($key === 'typhoon' ? 14 : 30) * 86400),
                'todate' => gmdate('Y-m-d'),
            ];
        } elseif ($key === 'reliefweb') {
            $parameters += [
                'appname' => 'bayanalert',
                'profile' => 'full',
                'preset' => 'latest',
                'filter[field]' => 'country.iso3',
                'filter[value]' => 'PHL',
                'limit' => 100,
                'limit' => 100,
                'limit' => 100,
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
                $key === 'reliefweb'
                    ? $this->providerResponseError($key, $response)
                    : $providerName . ' connection failed: ' .
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
            return count(is_array($decoded['events'] ?? null) ? $decoded['events'] : []);
        }
        if ($key === 'reliefweb') {
            return count(is_array($decoded['data'] ?? null) ? $decoded['data'] : []);
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
            "records_cached = IF(VALUES(last_status) = 'online', VALUES(records_cached), records_cached), updated_at = NOW()",
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
