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

        // DirectAdmin: socket is always /home/<user>/.redis/redis.sock
        // https://docs.directadmin.com/other-hosting-services/redis/
        // This path is inside the user's home dir — within open_basedir
        if ($panel === 'directadmin') {
            $sock = self::daSocket();
            if ($sock && self::probeSocket($sock)) {
                return self::foundSocket($result, $sock);
            }
            // DA Redis not enabled for this user — return instructions
            $result['instructions'] = "DirectAdmin: Extra Features → Redis → Enable.\nSocket path: " . ($sock ?: '/home/<user>/.redis/redis.sock');
            return $result;
        }

        // Standard TCP — most other panels use 127.0.0.1:6379
        if (self::probePort('127.0.0.1', 6379)) {
            return self::foundTcp($result, '127.0.0.1', 6379);
        }

        // Standard socket paths for other panels
        foreach (self::standardSockets($panel) as $sock) {
            if (self::probeSocket($sock)) {
                return self::foundSocket($result, $sock);
            }
        }

        $result['instructions'] = self::installInstructions($panel);
        return $result;
    }

    // ── DirectAdmin ───────────────────────────────────────────────────────────

    private static function daSocket(): string
    {
        // Extract username from ABSPATH: /home/<user>/domains/...
        if (defined('ABSPATH') && preg_match('#^/home/([^/]+)/#', ABSPATH, $m)) {
            return '/home/' . $m[1] . '/.redis/redis.sock';
        }
        // Fallback: try get_current_user() which returns the file owner
        $user = function_exists('get_current_user') ? get_current_user() : '';
        if ($user && $user !== 'root') {
            return '/home/' . $user . '/.redis/redis.sock';
        }
        return '';
    }

    private static function standardSockets(string $panel): array
    {
        return match ($panel) {
            'cpanel'  => ['/var/run/redis/redis.sock'],
            'plesk'   => ['/var/run/redis/redis.sock'],
            'hestia'  => ['/var/run/redis/redis.sock'],
            'vesta'   => ['/var/run/redis/redis.sock'],
            default   => ['/var/run/redis/redis.sock', '/tmp/redis.sock'],
        };
    }

    // ── Probe ─────────────────────────────────────────────────────────────────

    private static function probeSocket(string $path): bool
    {
        if (!extension_loaded('redis') || !$path) {
            return false;
        }
        try {
            $r = new \Redis();
            if (@$r->connect($path, 0, 0.5)) {
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

    private static function redisVersion(string $method, string $hostOrSocket, int $port = 0): string
    {
        try {
            $r = new \Redis();
            if ($method === 'socket') {
                @$r->connect($hostOrSocket, 0, 0.5);
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

    // ── Result builders ───────────────────────────────────────────────────────

    private static function foundSocket(array $result, string $socket): array
    {
        $result['connected'] = true;
        $result['method']    = 'socket';
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

    // ── Panel / server detection ──────────────────────────────────────────────

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
            'directadmin' => "Redis nerastas.\n\nDirectAdmin: Extra Features → Redis → Enable.",
            'hestia'      => "Redis nerastas.\n\nHestiaCP: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'cyberpanel'  => "Redis nerastas.\n\nCyberPanel: Packages → Install Redis.",
            default       => "Redis nerastas.\n\nUbuntu/Debian:\n  sudo apt install redis-server\n  sudo systemctl enable --now redis\n\nCentOS/RHEL:\n  sudo yum install redis\n  sudo systemctl enable --now redis\n\nAlternatyva — Dragonfly:\n  curl -fsSL https://packages.dragonflydb.io/install.sh | sudo bash",
        };
    }
}
