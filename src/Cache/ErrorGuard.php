<?php

declare(strict_types=1);

namespace VLT\CacheManager\Cache;

/**
 * Prevents any cache layer from storing error responses.
 *
 * Registers a shutdown function that fires BEFORE cache headers are sent.
 * If PHP is dying with a fatal error or the response status is 4xx/5xx:
 * - Sends no-cache headers for LiteSpeed and Nginx FastCGI
 * - Flushes the Redis object cache group that may have caused the error
 * - Logs the error for diagnostics
 */
final class ErrorGuard
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Register early shutdown to catch fatals before cache headers
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onShutdown(): void
    {
        $error = error_get_last();
        $isFatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        $status = http_response_code();
        $isError = $status >= 400;

        if (!$isFatal && !$isError) {
            return;
        }

        // ── Prevent LiteSpeed from caching this response ──
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache', true);
            // Nginx FastCGI: tell it not to cache
            header('X-Accel-Expires: 0', true);
            // Standard HTTP: ensure proxies don't cache errors
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
        }

        // ── If fatal error is in a specific file, try to identify the culprit ──
        if ($isFatal) {
            $file = $error['file'] ?? '';
            $message = $error['message'] ?? '';

            // If the error is in a mu-plugin or plugin, log it
            if (str_contains($file, 'mu-plugins') || str_contains($file, 'plugins')) {
                self::logError($file, $message, $status);
            }

            // If error involves an object cache miss/corruption, flush related Redis keys
            if (str_contains($message, 'Undefined') || str_contains($message, 'unserialize')) {
                self::flushCorruptedRedisKeys($file);
            }
        }
    }

    private static function flushCorruptedRedisKeys(string $errorFile): void
    {
        if (!class_exists(\VLT\CacheManager\Redis\RedisFactory::class)) {
            return;
        }

        $r = \VLT\CacheManager\Redis\RedisFactory::create(0.5);
        if (!$r) {
            return;
        }

        // If the error is in a theme/plugin, flush the alloptions cache
        // (most common cause of "cached object causes fatal")
        if (str_contains($errorFile, 'themes') || str_contains($errorFile, 'plugins')) {
            $r->del('vlt_options:alloptions');
            $r->del('vlt_options:notoptions');
        }

        $r->close();
    }

    private static function logError(string $file, string $message, int $status): void
    {
        $entry = sprintf(
            "[%s] ErrorGuard: HTTP %d | %s | %s\n",
            gmdate('Y-m-d H:i:s'),
            $status,
            basename($file),
            substr($message, 0, 200)
        );

        $logFile = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/cache-manager-errors.log'
            : '/tmp/cache-manager-errors.log';

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
