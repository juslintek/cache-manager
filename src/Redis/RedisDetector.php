<?php

declare(strict_types=1);

namespace VLT\CacheManager\Redis;

final class RedisDetector
{
    /** @return array{connected:bool, method:string, socket:string, host:string, port:int, version:string, panel:string, litespeed:bool, instructions:string} */
    public static function detect(): array
    {
        $panel  = self::detectPanel();
        $result = [
            'connected'    => false,
            'method'       => '',
            'socket'       => '',
            'host'         => '127.0.0.1',
            'port'         => 6379,
            'version'      => '',
            'panel'        => $panel,
            'litespeed'    => self::detectLiteSpeed(),
            'instructions' => '',
        ];

        // Panel-specific socket — most reliable, check first
        $panelSocket = self::panelSocket($panel);
        if ($panelSocket && self::probeSocket($panelSocket)) {
            return self::found($result, 'socket', $panelSocket);
        }

        // Standard TCP (most panels use 127.0.0.1:6379 by default)
        if (self::probePort('127.0.0.1', 6379)) {
            return self::foundTcp($result, '127.0.0.1', 6379);
        }

        // Panel-specific config file
        $conf = self::panelConf($panel);
        if ($conf) {
            if (!empty($conf['unixsocket']) && self::probeSocket($conf['unixsocket'])) {
                return self::found($result, 'socket', $conf['unixsocket']);
            }
            if (!empty($conf['port']) && (int) $conf['port'] > 0) {
                $host = $conf['bind'] ?? '127.0.0.1';
                $port = (int) $conf['port'];
                if (self::probePort($host, $port)) {
                    return self::foundTcp($result, $host, $port);
                }
            }
        }

        $result['instructions'] = self::installInstructions($panel);
        return $result;
    }

    // ── Panel-specific socket paths (from official docs) ─────────────────────

    private static function panelSocket(string $panel): string
    {
        // DirectAdmin: per-user socket at /home/{user}/.redis/redis.sock
        // https://docs.directadmin.com/other-hosting-services/redis/
        if ($panel === 'directadmin') {
            $user = self::currentLinuxUser();
            if ($user) {
                $sock = "/home/{$user}/.redis/redis.sock";
                if (@file_exists($sock)) {
                    return $sock;
                }
            }
            // DA global fallback
            return '/usr/local/redis/var/run/redis.sock';
        }

        // cPanel/WHM: Redis Manager stores socket here
        // https://docs.cpanel.net/whm/plugins/redis-manager/
        if ($panel === 'cpanel') {
            return '/var/run/redis/redis.sock';
        }

        // Plesk: Redis extension uses standard socket
        if ($panel === 'plesk') {
            return '/var/run/redis/redis.sock';
        }

        // HestiaCP / VestaCP: standard Redis install
        if (in_array($panel, ['hestia', 'vesta'], true)) {
            return '/var/run/redis/redis.sock';
        }

        // CyberPanel: uses socket in /var/run
        if ($panel === 'cyberpanel') {
            return '/var/run/redis/redis.sock';
        }

        return '';
    }

    // ── Panel-specific config file paths ─────────────────────────────────────

    private static function panelConf(string $panel): array
    {
        $paths = match ($panel) {
            'directadmin' => [
                '/usr/local/redis/etc/redis.conf',
                '/etc/redis/redis.conf',
            ],
            'cpanel'      => ['/etc/redis/redis.conf'],
            'plesk'       => ['/etc/redis/redis.conf', '/etc/redis.conf'],
            default       => ['/etc/redis/redis.conf', '/etc/redis.conf'],
        };

        foreach ($paths as $path) {
            $conf = self::parseConf($path);
            if ($conf) {
                return $conf;
            }
        }
        return [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function currentLinuxUser(): string
    {
        // WordPress runs as the hosting user — get from DOCUMENT_ROOT or __FILE__
        if (defined('ABSPATH')) {
            // Extract user from path like /home/username/...
            if (preg_match('#^/home/([^/]+)/#', ABSPATH, $m)) {
                return $m[1];
            }
        }
        return (string) (function_exists('get_current_user') ? get_current_user() : '');
    }

    private static function found(array $result, string $method, string $socket): array
    {
        $result['connected'] = true;
        $result['method']    = $method;
        $result['socket']    = $socket;
        $result['version']   = self::redisVersion('socket', $socket);
        return $result;
    }

    private static function foundTcp(array $result, string $host, int $port): array
    {
        $result['connected'] = true;
        $result['method']    = 'tcp';
        $result['host']      = $host;
        $result['port']      = $port;
        $result['version']   = self::redisVersion('tcp', $host, $port);
        return $result;
    }

    private static function probeSocket(string $path): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }
        try {
            $r = new \Redis();
            if (@$r->connect($path)) {
                $r->close();
                return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private static function probePort(string $host, int $port): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }
        try {
            $r = new \Redis();
            if (@$r->connect($host, $port, 0.5)) {
                $r->close();
                return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private static function redisVersion(string $method, string $hostOrSocket, int $port = 6379): string
    {
        try {
            $r = new \Redis();
            if ($method === 'socket') {
                @$r->connect($hostOrSocket);
            } else {
                @$r->connect($hostOrSocket, $port, 0.5);
            }
            $info = $r->info('server');
            $r->close();
            return $info['redis_version'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function parseConf(string $path): array
    {
        if (!@is_readable($path)) {
            return [];
        }
        $conf = [];
        foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) === 2) {
                $conf[strtolower($parts[0])] = $parts[1];
            }
        }
        return $conf;
    }

    public static function detectPanel(): string
    {
        $checks = [
            'cpanel'      => '/usr/local/cpanel/cpanel',
            'plesk'       => '/usr/local/psa/version',
            'directadmin' => '/usr/local/directadmin/directadmin',
            'ispmanager'  => '/usr/local/mgr5/sbin/mgrctl',
            'hestia'      => '/usr/local/hestia/bin/v-list-users',
            'vesta'       => '/usr/local/vesta/bin/v-list-users',
        ];
        foreach ($checks as $panel => $file) {
            if (@file_exists($file)) {
                return $panel;
            }
        }
        if (@is_dir('/etc/cyberpanel')) {
            return 'cyberpanel';
        }
        return 'linux';
    }

    public static function detectLiteSpeed(): bool
    {
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (stripos($server, 'litespeed') !== false || stripos($server, 'openlitespeed') !== false) {
            return true;
        }
        foreach (['/usr/local/lsws/bin/lswsctrl', '/usr/local/lsws/fcgi-bin/lsphp'] as $bin) {
            if (@file_exists($bin)) {
                return true;
            }
        }
        return defined('LSCWP_V') || class_exists('LiteSpeed\Core') || class_exists('LiteSpeed_Cache');
    }

    private static function installInstructions(string $panel): string
    {
        return match ($panel) {
            'cpanel'      => "Redis nerastas.\n\ncPanel/WHM: WHM → Redis Manager → Enable Redis.",
            'plesk'       => "Redis nerastas.\n\nPlesk: Tools & Settings → Updates → install Redis extension.",
            'directadmin' => "Redis nerastas.\n\nDirectAdmin: Extra Features → Redis → Enable.\nArba: CustomBuild → redis → build.",
            'hestia'      => "Redis nerastas.\n\nHestiaCP: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'cyberpanel'  => "Redis nerastas.\n\nCyberPanel: Packages → Install Redis.",
            default       => "Redis nerastas.\n\nUbuntu/Debian:\n  sudo apt install redis-server\n  sudo systemctl enable --now redis\n\nCentOS/RHEL:\n  sudo yum install redis\n  sudo systemctl enable --now redis\n\nAlternatyva — Dragonfly:\n  curl -fsSL https://packages.dragonflydb.io/install.sh | sudo bash",
        };
    }
}
