<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CorsMiddleware
{
    public static function allowedOrigins(): array
    {
        $allowedOrigins = [];
        $configuredOrigins = getenv('FRONTEND_ORIGINS');
        if ($configuredOrigins) {
            foreach (explode(',', $configuredOrigins) as $configuredOrigin) {
                $configuredOrigin = trim($configuredOrigin);
                $parts = parse_url($configuredOrigin);
                if (isset($parts['scheme'], $parts['host'])
                    && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                    && !isset($parts['user'])
                    && !isset($parts['pass'])
                    && !isset($parts['query'])
                    && !isset($parts['fragment'])
                    && (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/')) {
                    $allowedOrigins[] = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                        . (isset($parts['port']) ? ':' . $parts['port'] : '');
                }
            }
        }

        $isProduction = strtolower(trim((string) (getenv('APP_ENV') ?: 'development'))) === 'production';
        if (!$allowedOrigins) {
            $allowedOrigins = [
                'https://bayanalert-frontend-2.onrender.com',
            ];
            if (!$isProduction) {
                $allowedOrigins[] = 'http://localhost:5173';
                $allowedOrigins[] = 'http://127.0.0.1:5173';
            }
        }

        $frontendUrl = getenv('FRONTEND_URL');
        if ($frontendUrl) {
            $parts = parse_url($frontendUrl);
            if (isset($parts['scheme'], $parts['host'])
                && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                && !isset($parts['user'])
                && !isset($parts['pass'])) {
                $frontendOrigin = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                    . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $frontendHost = strtolower(trim($parts['host'], '[]'));
                $isLoopback = in_array($frontendHost, ['localhost', '127.0.0.1', '::1'], true);
                if (!$isProduction || !$isLoopback) {
                    $allowedOrigins[] = $frontendOrigin;
                }
            }
        }

        return array_values(array_unique($allowedOrigins));
    }

    public function handle(Closure $next)
    {
        $lava = lava_instance();
        $origin = trim((string) ($lava->request->header('Origin') ?? ''));
        if (in_array($origin, self::allowedOrigins(), true)) {
            $lava->response->add_header('Access-Control-Allow-Origin', $origin);
            $lava->response->add_header('Vary', 'Origin');
            $lava->response->add_header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $lava->response->add_header('Access-Control-Allow-Headers', 'Accept, Content-Type, Authorization, X-Requested-With');
            $lava->response->add_header('Access-Control-Expose-Headers', 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After');
            $lava->response->add_header('Access-Control-Max-Age', '600');
        }

        return $next();
    }
}
