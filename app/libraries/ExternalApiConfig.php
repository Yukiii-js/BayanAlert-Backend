<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ExternalApiConfig
{
    private static $cache = [];
    private static $configurationUnavailable = false;

    public static function isEnabled($database, string $key): bool
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        if (self::$configurationUnavailable) {
            return true;
        }

        try {
            $row = $database->raw(
                'SELECT is_enabled FROM external_api_configs WHERE api_key = ? LIMIT 1',
                [$key]
            )->fetch(PDO::FETCH_ASSOC);
            self::$cache[$key] = !$row || (int) $row['is_enabled'] === 1;
            return self::$cache[$key];
        } catch (Throwable $exception) {
            error_log('External API configuration is unavailable: ' . $exception->getMessage());
            self::$configurationUnavailable = true;
            return true;
        }
    }

    public static function forget(string $key): void
    {
        unset(self::$cache[$key]);
    }
}
