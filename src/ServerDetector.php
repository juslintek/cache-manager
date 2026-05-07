<?php

declare(strict_types=1);

namespace VLT\CacheManager;

/**
 * Detects the running web server and its configuration.
 * Uses multiple signals: SERVER_SOFTWARE, process list via /proc, config files, ports.
 * Results are cached in a transient to avoid repeated detection on every request.
 */
final class ServerDetector
{
    public const NGINX      = 'nginx';
    public const LITESPEED  = 'litespeed';
    public const OLS        = 'openlitespeed';
    public const APACHE     = 'apache';
    public const UNKNOWN    = 'unknown';

    private static ?array $cache = null;

    /** @return array{server:string, version:string, config:array, cache_dir:string, recommendations:string[]} */
    public static function detect(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $cached = get_transient('vlt_server_detect');
        if ($cached !== false) {
            return self::$cache = $cached;
        }

        $result = self::run();
        set_transient('vlt_server_detect', $result, HOUR_IN_SECONDS);
        return self::$cache = $result;
    }

    public static function flush(): void
    {
        self::$cache = null;
        delete_transient('vlt_server_detect');
    }

    private static function run(): array
    {
        $sw      = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
        $server  = self::UNKNOWN;
        $version = '';

        // 1. SERVER_SOFTWARE header — most reliable
        if (str_contains($sw, 'litespeed')) {
            $server = self::LITESPEED;
            preg_match('/litespeed\/([\d.]+)/i', $sw, $m);
            $version = $m[1] ?? '';
        } elseif (str_contains($sw, 'openlitespeed')) {
            $server = self::OLS;
            preg_match('/openlitespeed\/([\d.]+)/i', $sw, $m);
            $version = $m[1] ?? '';
        } elseif (str_contains($sw, 'nginx')) {
            $server = self::NGINX;
            preg_match('/nginx\/([\d.]+)/i', $sw, $m);
            $version = $m[1] ?? '';
        } elseif (str_contains($sw, 'apache')) {
            $server = self::APACHE;
            preg_match('/apache\/([\d.]+)/i', $sw, $m);
            $version = $m[1] ?? '';
        }

        // 2. Filesystem signals (may be blocked by open_basedir)
        if ($server === self::UNKNOWN) {
            if (@is_dir('/usr/local/lsws') || @file_exists('/usr/local/lsws/bin/lswsctrl')) {
                // Distinguish LiteSpeed Enterprise vs OpenLiteSpeed
                $server = @file_exists('/usr/local/lsws/conf/httpd_config.conf') ? self::OLS : self::LITESPEED;
            } elseif (@is_dir('/etc/nginx') || @file_exists('/usr/sbin/nginx')) {
                $server = self::NGINX;
            } elseif (@is_dir('/etc/apache2') || @is_dir('/etc/httpd') || @file_exists('/usr/sbin/apache2')) {
                $server = self::APACHE;
            }
        }

        // 3. Port signals — LiteSpeed admin: 7080 (OLS) or 8090 (LS Enterprise)
        if ($server === self::UNKNOWN) {
            foreach ([7080 => self::OLS, 8090 => self::LITESPEED] as $port => $srv) {
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
                if (is_resource($conn)) {
                    fclose($conn);
                    $server = $srv;
                    break;
                }
            }
        }

        $config    = self::parseConfig($server);
        $cacheDir  = self::cacheDir($server, $config);
        $recs      = self::recommendations($server, $config);

        return compact('server', 'version', 'config', 'cacheDir', 'recommendations') + ['recommendations' => $recs];
    }

    // ── Config parsing ────────────────────────────────────────────────────────

    private static function parseConfig(string $server): array
    {
        return match ($server) {
            self::NGINX     => self::parseNginxConfig(),
            self::LITESPEED => self::parseLsConfig(),
            self::OLS       => self::parseOlsConfig(),
            self::APACHE    => self::parseApacheConfig(),
            default         => [],
        };
    }

    private static function parseNginxConfig(): array
    {
        $paths = ['/etc/nginx/nginx.conf', '/usr/local/nginx/conf/nginx.conf'];
        foreach ($paths as $p) {
            if (!@is_readable($p)) {
                continue;
            }
            $content = @file_get_contents($p) ?: '';
            return [
                'fastcgi_cache'      => (bool) preg_match('/fastcgi_cache_path/i', $content),
                'fastcgi_cache_path' => self::extractValue($content, 'fastcgi_cache_path'),
                'worker_processes'   => self::extractValue($content, 'worker_processes'),
                'gzip'               => str_contains($content, 'gzip on'),
                'config_file'        => $p,
            ];
        }
        return [];
    }

    private static function parseLsConfig(): array
    {
        // LiteSpeed Enterprise config
        $paths = ['/usr/local/lsws/conf/httpd_config.xml', '/usr/local/lsws/conf/httpd_config.conf'];
        foreach ($paths as $p) {
            if (!@is_readable($p)) {
                continue;
            }
            $content = @file_get_contents($p) ?: '';
            return [
                'lscache'    => str_contains($content, 'lscache') || str_contains($content, 'LSCache'),
                'config_file' => $p,
                'version'    => self::extractValue($content, 'version'),
            ];
        }
        return ['config_file' => ''];
    }

    private static function parseOlsConfig(): array
    {
        $paths = ['/usr/local/lsws/conf/httpd_config.conf', '/etc/openlitespeed/httpd_config.conf'];
        foreach ($paths as $p) {
            if (!@is_readable($p)) {
                continue;
            }
            $content = @file_get_contents($p) ?: '';
            return [
                'lscache'     => str_contains($content, 'lscache') || str_contains($content, 'LSCache'),
                'config_file' => $p,
                'workers'     => self::extractValue($content, 'maxConnections'),
            ];
        }
        return ['config_file' => ''];
    }

    private static function parseApacheConfig(): array
    {
        $paths = ['/etc/apache2/apache2.conf', '/etc/httpd/conf/httpd.conf', '/usr/local/apache/conf/httpd.conf'];
        foreach ($paths as $p) {
            if (!@is_readable($p)) {
                continue;
            }
            $content = @file_get_contents($p) ?: '';
            return [
                'mod_cache'   => str_contains($content, 'mod_cache'),
                'mod_deflate' => str_contains($content, 'mod_deflate'),
                'mod_expires' => str_contains($content, 'mod_expires'),
                'config_file' => $p,
            ];
        }
        return ['config_file' => ''];
    }

    private static function extractValue(string $content, string $key): string
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s+([^\s;{]+)/m', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    // ── Cache directory ───────────────────────────────────────────────────────

    private static function cacheDir(string $server, array $config): string
    {
        return match ($server) {
            self::NGINX => $config['fastcgi_cache_path'] ?: (defined('VLT_CM_NGINX_CACHE') ? VLT_CM_NGINX_CACHE : '/var/cache/nginx/wordpress'),
            self::LITESPEED, self::OLS => '/tmp/lscache',
            default => '',
        };
    }

    // ── Recommendations ───────────────────────────────────────────────────────

    private static function recommendations(string $server, array $config): array
    {
        $recs = [];

        if ($server === self::LITESPEED || $server === self::OLS) {
            if (empty($config['lscache'])) {
                $recs[] = 'Įjunkite LSCache modulį — tai pagrindinis LiteSpeed talpyklos mechanizmas.';
            }
            if (!defined('LSCWP_V') && !class_exists('LiteSpeed\Core')) {
                $recs[] = 'Įdiekite LiteSpeed Cache WordPress įskiepį — jis automatiškai konfigūruoja LSCache.';
            }
            $recs[] = 'Įjunkite QUIC/HTTP3 palaikymą LiteSpeed konfigūracijoje.';
        }

        if ($server === self::NGINX) {
            if (!($config['fastcgi_cache'] ?? false)) {
                $recs[] = 'Sukonfigūruokite FastCGI talpyklą nginx.conf — reikia fastcgi_cache_path direktyvos.';
            }
            if (!($config['gzip'] ?? false)) {
                $recs[] = 'Įjunkite gzip suspaudimą nginx.conf.';
            }
        }

        if ($server === self::APACHE) {
            if (!($config['mod_cache'] ?? false)) {
                $recs[] = 'Įjunkite mod_cache Apache modulį.';
            }
            if (!($config['mod_deflate'] ?? false)) {
                $recs[] = 'Įjunkite mod_deflate suspaudimui.';
            }
            if (!($config['mod_expires'] ?? false)) {
                $recs[] = 'Įjunkite mod_expires naršyklės talpyklos antraštėms.';
            }
        }

        return $recs;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function isLiteSpeed(): bool
    {
        $s = self::detect()['server'];
        return $s === self::LITESPEED || $s === self::OLS;
    }

    public static function isNginx(): bool
    {
        return self::detect()['server'] === self::NGINX;
    }

    public static function isApache(): bool
    {
        return self::detect()['server'] === self::APACHE;
    }
}
