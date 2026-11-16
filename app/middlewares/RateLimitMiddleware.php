<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RateLimitMiddleware
{
    public function handle(Closure $next)
    {
        $lava = lava_instance();
        $lava->call->library('cache');

        $request = $lava->request;
        $client = (string) ($request->server('REMOTE_ADDR') ?: 'unknown');
        $requestUri = (string) ($request->server('REQUEST_URI') ?: '/');
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
        $key = 'api_rate_limit_' . hash('sha256', $client . '|' . $path);
        $limit = max(1, (int) (config_item('rate_limit_requests') ?: 60));
        $window = max(1, (int) (config_item('rate_limit_seconds') ?: 60));
        $now = time();
        $count = $lava->cache->get($key);
        $windowStart = $lava->cache->get($key . '_start');
        $count = is_numeric($count) ? (int) $count : 0;
        $windowStart = is_numeric($windowStart) ? (int) $windowStart : 0;

        if ($windowStart === 0 || ($now - $windowStart) >= $window) {
            $windowStart = $now;
            $count = 1;
            $lava->cache->write($count, $key, $window);
            $lava->cache->write($windowStart, $key . '_start', $window);
        } elseif ($count >= $limit) {
            $retryAfter = max(1, $windowStart + $window - $now);
            $lava->response->add_header('Retry-After', (string) $retryAfter);
            $lava->response->add_header('X-RateLimit-Limit', (string) $limit);
            $lava->response->add_header('X-RateLimit-Remaining', '0');
            $lava->response->add_header('X-RateLimit-Reset', (string) ($windowStart + $window));
            api_error('Too many requests. Please try again later.', 429, [
                'retry_after_seconds' => $retryAfter,
            ]);
        } else {
            $count++;
            $lava->cache->write($count, $key, $window);
        }

        $lava->response->add_header('X-RateLimit-Limit', (string) $limit);
        $lava->response->add_header('X-RateLimit-Remaining', (string) max(0, $limit - $count));
        $lava->response->add_header('X-RateLimit-Reset', (string) ($windowStart + $window));

        return $next();
    }
}
