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

    private const OPTION = 'vlt_server_info';

    /** @return array{server:string, version:string, config:array, cache_dir:string, recommendations:string[]} */
    public static function detect(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $stored = get_option(self::OPTION);
        if ($stored) {
            return self::$cache = $stored;
        }
        // First run (plugin just activated or option missing)
        return self::runAndStore();
    }

    /** Run detection and persist result. Called on activation and manual refresh. */
    public static function runAndStore(): array
    {
        $result = self::run();
        update_option(self::OPTION, $result, false); // autoload=false — no overhead on every request
        return self::$cache = $result;
    }

    public static function flush(): void
    {
        self::$cache = null;
        delete_option(self::OPTION);
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
        // 1. PHP function check — highest reliability (LSCache PHP API available)
        $lscachePhpApi = function_exists('litespeed_finish_request')
            || function_exists('litespeed_purge_all')
            || defined('LSCWP_V')
            || class_exists('LiteSpeed\Core');

        // 2. Response headers — X-LiteSpeed-Cache or X-LiteSpeed-Cache-Control present
        $lscacheHeaders = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'X-LiteSpeed-Cache') !== false) {
                $lscacheHeaders = true;
                break;
            }
        }
        // Also check incoming request headers (set by OLS when serving cached response)
        foreach ($_SERVER as $k => $v) {
            if (stripos($k, 'HTTP_X_LITESPEED') !== false) {
                $lscacheHeaders = true;
                break;
            }
        }

        // 3. Module .so file
        $lscacheSo = @file_exists('/usr/local/lsws/modules/mod_lscache.so')
            || @file_exists('/usr/local/lsws/modules/lscache.so');

        // 4. Cache storage directory — DA+OLS: /home/$user/lscache, OLS default: /usr/local/lsws/sitecache
        $user = '';
        if (defined('ABSPATH') && preg_match('#^/home/([^/]+)/#', ABSPATH, $m)) {
            $user = $m[1];
        }
        $cacheStoragePath   = '';
        $cacheStorageExists = false;
        foreach (array_filter([
            $user ? "/home/{$user}/lscache" : '',
            '/usr/local/lsws/sitecache',
            '/tmp/lscache',
        ]) as $path) {
            if (@is_dir($path)) {
                $cacheStoragePath   = $path;
                $cacheStorageExists = true;
                break;
            }
        }

        // 5. Config file check — DA OLS uses /etc/openlitespeed/httpd-vhosts.conf
        $mainPaths = [
            '/etc/openlitespeed/httpd_config.conf',
            '/etc/openlitespeed/httpd-vhosts.conf',
            '/usr/local/lsws/conf/httpd_config.conf',
        ];
        $content = '';
        $configFile = '';
        foreach ($mainPaths as $p) {
            if (@is_readable($p)) {
                $content    = @file_get_contents($p) ?: '';
                $configFile = $p;
                break;
            }
        }
        $lscacheConf = str_contains($content, 'lscache') || str_contains($content, 'LSCache');

        // DA OLS template files — CacheRoot directive enables per-user lscache
        if (!$lscacheConf) {
            foreach ([
                '/usr/local/directadmin/data/templates/openlitespeed_vhost.conf',
                '/usr/local/directadmin/data/templates/custom/openlitespeed_vhost.conf',
            ] as $tp) {
                $tc = @file_get_contents($tp) ?: '';
                if (str_contains($tc, 'lscache') || str_contains($tc, 'CacheRoot') || str_contains($tc, 'LSCache')) {
                    $lscacheConf = true;
                    break;
                }
            }
        }

        // Check vhost configs
        if (!$lscacheConf) {
            foreach (['/usr/local/lsws/conf/vhosts/', '/etc/openlitespeed/vhosts/'] as $dir) {
                foreach (@glob($dir . '*/vhconf.conf') ?: [] as $vhconf) {
                    $vc = @file_get_contents($vhconf) ?: '';
                    if (str_contains($vc, 'lscache') || str_contains($vc, 'LSCache')) {
                        $lscacheConf = true;
                        break 2;
                    }
                }
            }
        }

        $lscacheActive = $lscachePhpApi || $lscacheHeaders || $lscacheSo || $lscacheConf || $cacheStorageExists;

        return [
            'lscache'              => $lscacheActive,
            'lscache_php_api'      => $lscachePhpApi,
            'lscache_headers'      => $lscacheHeaders,
            'lscache_so'           => $lscacheSo,
            'lscache_conf'         => $lscacheConf,
            'lscache_storage'      => $cacheStorageExists,
            'lscache_storage_path' => $cacheStoragePath,
            'config_file'          => $configFile,
            'workers'              => self::extractValue($content, 'maxConnections'),
        ];
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
