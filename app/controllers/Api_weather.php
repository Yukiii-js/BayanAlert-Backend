<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_weather extends Controller
{
    private const CALAPAN_QUERY = '13.411,121.18';

    public function __construct()
    {
        parent::__construct();
    }

    public function calapan()
    {
        $apiKey = trim((string) getenv('WEATHERAPI_KEY'));
        if ($apiKey === '') {
            api_error('Weather forecast is not configured. Set WEATHERAPI_KEY in the backend environment.', 503);
            return;
        }

        $url = 'https://api.weatherapi.com/v1/forecast.json?' . http_build_query([
            'key' => $apiKey,
            'q' => self::CALAPAN_QUERY,
            'days' => 3,
            'aqi' => 'no',
            'alerts' => 'no',
        ]);
        $curl = curl_init($url);
        if ($curl === false) {
            error_log('Calapan weather error: unable to initialize cURL.');
            api_error('Weather forecast is temporarily unavailable.', 502);
            return;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'BayanAlert/1.0',
        ]);
        $responseBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        if (!is_string($responseBody) || $statusCode !== 200) {
            error_log('Calapan weather request failed: HTTP ' . $statusCode . ($curlError ? ' ' . $curlError : ''));
            api_error('Weather forecast is temporarily unavailable.', 502);
            return;
        }

        $weather = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log(
                'Calapan weather JSON error: ' . json_last_error_msg()
            );

            api_error('Weather API returned invalid JSON.', 502);
            return;
        }

        if (!is_array($weather)) {
            error_log('Calapan weather error: Response is not a JSON object.');

            api_error('Weather API returned an invalid response.', 502);
            return;
        }

        if (isset($weather['error'])) {
            error_log(
                'Calapan WeatherAPI error: ' .
                json_encode($weather['error'])
            );

            api_error('Weather provider rejected the request.', 502);
            return;
        }

        if (
            !isset(
                $weather['location'],
                $weather['current'],
                $weather['forecast']['forecastday']
            ) ||
            !is_array($weather['forecast']['forecastday'])
        ) {
            error_log(
                'Calapan weather error: Missing required forecast fields. ' .
                'Received keys: ' . implode(', ', array_keys($weather))
            );

            api_error('Weather forecast returned an invalid response.', 502);
            return;
        }

        $currentEpoch = (int) ($weather['current']['last_updated_epoch'] ?? time());
        $hourlyForecast = [];
        foreach ($weather['forecast']['forecastday'] as $forecastDay) {
            foreach (($forecastDay['hour'] ?? []) as $hour) {
                if ((int) ($hour['time_epoch'] ?? 0) < $currentEpoch) {
                    continue;
                }
                $hourlyForecast[] = [
                    'time' => $hour['time'] ?? null,
                    'time_epoch' => $hour['time_epoch'] ?? null,
                    'temp_c' => $hour['temp_c'] ?? null,
                    'chance_of_rain' => $hour['chance_of_rain'] ?? null,
                    'condition' => $hour['condition'] ?? null,
                ];
                if (count($hourlyForecast) >= 8) {
                    break 2;
                }
            }
        }

        header('Cache-Control: public, max-age=600');
        api_response([
            'location' => [
                'name' => 'Calapan City',
                'region' => 'Oriental Mindoro',
                'country' => 'Philippines',
                'localtime' => $weather['location']['localtime'] ?? null,
            ],
            'current' => [
                'temp_c' => $weather['current']['temp_c'] ?? null,
                'feelslike_c' => $weather['current']['feelslike_c'] ?? null,
                'condition' => $weather['current']['condition'] ?? null,
                'wind_kph' => $weather['current']['wind_kph'] ?? null,
                'wind_dir' => $weather['current']['wind_dir'] ?? null,
                'humidity' => $weather['current']['humidity'] ?? null,
                'precip_mm' => $weather['current']['precip_mm'] ?? null,
                'is_day' => $weather['current']['is_day'] ?? null,
                'last_updated' => $weather['current']['last_updated'] ?? null,
                'last_updated_epoch' => $currentEpoch,
            ],
            'hourly' => $hourlyForecast,
            'forecast' => array_map(static function ($day) {
                return [
                    'date' => $day['date'] ?? null,
                    'day' => [
                        'maxtemp_c' => $day['day']['maxtemp_c'] ?? null,
                        'mintemp_c' => $day['day']['mintemp_c'] ?? null,
                        'daily_chance_of_rain' => $day['day']['daily_chance_of_rain'] ?? null,
                        'daily_chance_of_snow' => $day['day']['daily_chance_of_snow'] ?? null,
                        'condition' => $day['day']['condition'] ?? null,
                    ],
                ];
            }, $weather['forecast']['forecastday']),
        ]);
    }
}
