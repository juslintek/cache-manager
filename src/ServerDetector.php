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

        return compact('server', 'version', 'config', 'cacheDir') + ['recommendations' => $recs];
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
        $paths = [
            '/usr/local/lsws/conf/httpd_config.xml',
            '/usr/local/lsws/conf/httpd_config.conf',
            // DA+OLS uses /etc/openlitespeed/ even when detected as LiteSpeed Enterprise
            '/etc/openlitespeed/httpd_config.conf',
            '/etc/openlitespeed/httpd-lscache.conf',
        ];

        $content    = '';
        $configFile = '';
        foreach ($paths as $p) {
            if (!@is_readable($p)) {
                continue;
            }
            $content   .= (@file_get_contents($p) ?: '') . "\n";
            if (!$configFile) {
                $configFile = $p;
            }
        }

        // ls_enabled 1 in module cache block = module present
        // Check both main config and dedicated lscache conf
        $lscacheConfContent = @file_get_contents('/etc/openlitespeed/httpd-lscache.conf') ?: '';
        $allContent = $content . "\n" . $lscacheConfContent;
        $moduleEnabled = (bool) preg_match('/ls_enabled\s+1/', $allContent);
        // enableCache 1 = caching active
        $cacheEnabled  = (bool) preg_match('/enableCache\s+1/', $content);
        $lscache       = $moduleEnabled || str_contains($content, 'lscache') || str_contains($content, 'LSCache');

        return [
            'lscache'        => $lscache,
            'lscache_module' => $moduleEnabled,
            'lscache_active' => $cacheEnabled,
            'config_file'    => $configFile,
        ];
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
            '/usr/local/lsws/cachedata',
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

        // Config hierarchy — DA OLS structure
        // Main: /etc/openlitespeed/httpd_config.conf (symlink to /usr/local/lsws/conf/httpd_config.conf)
        // LSCache: /etc/openlitespeed/httpd-lscache.conf (included by main)
        // Vhosts: /etc/openlitespeed/httpd-vhosts.conf + directadmin-vhosts.conf
        // DA template (master): /usr/local/directadmin/data/templates/openlitespeed_vhost.conf
        // DA template (custom): /usr/local/directadmin/data/templates/custom/openlitespeed_vhost.conf
        $configHierarchy = [
            [
                'path'  => '/etc/openlitespeed/httpd_config.conf',
                'role'  => 'main',
                'label' => 'Pagrindinis OLS konfigūracijos failas',
                'note'  => 'Įtraukia visus kitus failus. Generuojamas DirectAdmin.',
            ],
            [
                'path'  => '/etc/openlitespeed/httpd-lscache.conf',
                'role'  => 'lscache',
                'label' => 'LSCache konfigūracija',
                'note'  => 'Valdo LSCache modulio nustatymus. Įtraukiamas iš pagrindinio failo.',
            ],
            [
                'path'  => '/etc/openlitespeed/httpd-vhosts.conf',
                'role'  => 'vhosts',
                'label' => 'Virtualių hostų konfigūracija',
                'note'  => 'Visi domenai. Generuojamas DirectAdmin pagal šablonus.',
            ],
            [
                'path'  => '/etc/openlitespeed/directadmin-vhosts.conf',
                'role'  => 'vhosts-da',
                'label' => 'DirectAdmin virtualių hostų konfigūracija',
                'note'  => 'DA-specifiniai virtualūs hostai.',
            ],
            [
                'path'  => '/usr/local/directadmin/data/templates/openlitespeed_vhost.conf',
                'role'  => 'template-master',
                'label' => 'DA šablonas (pagrindinis)',
                'note'  => 'Šablonas, pagal kurį DA generuoja httpd-vhosts.conf. Nekeiskite tiesiogiai — naudokite custom/ kopiją.',
            ],
            [
                'path'  => '/usr/local/directadmin/data/templates/custom/openlitespeed_vhost.conf',
                'role'  => 'template-custom',
                'label' => 'DA šablonas (custom perrašymas)',
                'note'  => 'Jūsų pakeitimai šiam failui perrašo pagrindinį šabloną. Sukurkite šį failą norėdami keisti vhost konfigūraciją.',
            ],
        ];

        // Enrich with readable/writable flags
        foreach ($configHierarchy as &$entry) {
            $entry['readable'] = @is_readable($entry['path']);
            $entry['writable'] = @is_writable($entry['path']);
            $entry['exists']   = @file_exists($entry['path']);
        }
        unset($entry);

        // Primary config file for backward compat
        $configFile = '/etc/openlitespeed/httpd_config.conf';
        $content    = @file_get_contents($configFile) ?: '';

        // LSCache check from dedicated file
        $lscacheContent = @file_get_contents('/etc/openlitespeed/httpd-lscache.conf') ?: '';
        $lscacheConf    = str_contains($content, 'lscache') || str_contains($content, 'LSCache')
            || str_contains($lscacheContent, 'lscache') || str_contains($lscacheContent, 'LSCache');

        // DA template check
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
            'config_hierarchy'     => $configHierarchy,
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
            self::LITESPEED, self::OLS => self::lsCacheDir(),
            default => '',
        };
    }

    private static function lsCacheDir(): string
    {
        foreach ([
            '/usr/local/lsws/cachedata',
            '/tmp/lscache',
            '/home/' . (defined('ABSPATH') && preg_match('#^/home/([^/]+)/#', ABSPATH, $m) ? $m[1] : '') . '/lscache',
        ] as $dir) {
            if ($dir && @is_dir($dir)) {
                return $dir;
            }
        }
        return '/usr/local/lsws/cachedata';
    }

    // ── Recommendations ───────────────────────────────────────────────────────

    private static function recommendations(string $server, array $config): array
    {
        $recs = [];

        if ($server === self::LITESPEED || $server === self::OLS) {
            if (empty($config['lscache'])) {
                $recs[] = 'Įjunkite LSCache modulį — tai pagrindinis LiteSpeed talpyklos mechanizmas.';
            }
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
