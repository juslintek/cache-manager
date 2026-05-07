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
            'cpanel', 'plesk', 'hestia', 'vesta', 'interworx' => ['/var/run/redis/redis.sock'],
            'cyberpanel'  => ['/var/run/redis/redis.sock', '/tmp/redis.sock'],
            default       => ['/var/run/redis/redis.sock', '/tmp/redis.sock'],
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
        // 1. Environment variables (most reliable, no open_basedir issues)
        $serverSoftware = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (isset($_SERVER['DIRECTADMIN']) || str_contains($serverSoftware, 'directadmin')) {
            return 'directadmin';
        }

        // 2. Port check (no filesystem, works within open_basedir)
        // DirectAdmin: 2222, cPanel: 2083/2087, Plesk: 8443/8880
        $ports = [
            'directadmin' => [2222],
            'cpanel'      => [2083, 2087],
            'plesk'       => [8443, 8880],
            'hestia'      => [8083],
            'cyberpanel'  => [8090],
            'ispmanager'  => [1500],
        ];
        foreach ($ports as $panel => $panelPorts) {
            foreach ($panelPorts as $port) {
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
                if (is_resource($conn)) {
                    fclose($conn);
                    return $panel;
                }
            }
        }

        // 3. Filesystem checks (may be blocked by open_basedir, but try)
        $fsPanels = [
            'cpanel'      => ['/usr/local/cpanel', '/etc/cpanel'],
            'directadmin' => ['/usr/local/directadmin', '/etc/directadmin'],
            'plesk'       => ['/usr/local/psa', '/opt/psa'],
            'hestia'      => ['/usr/local/hestia'],
            'vesta'       => ['/usr/local/vesta'],
            'ispmanager'  => ['/usr/local/mgr5'],
            'cyberpanel'  => ['/etc/cyberpanel', '/usr/local/CyberCP'],
            'interworx'   => ['/usr/local/interworx'],
            'froxlor'     => ['/var/www/froxlor'],
        ];
        foreach ($fsPanels as $panel => $paths) {
            foreach ($paths as $path) {
                if (@is_dir($path) || @file_exists($path)) {
                    return $panel;
                }
            }
        }

        // 4. ABSPATH-relative check (always within open_basedir)
        // DA: /home/user/domains/... — path structure is DA-specific
        if (defined('ABSPATH') && preg_match('#^/home/[^/]+/domains/#', ABSPATH)) {
            // Confirm with port as secondary signal
            $conn = @fsockopen('127.0.0.1', 2222, $errno, $errstr, 0.3);
            if (is_resource($conn)) {
                fclose($conn);
                return 'directadmin';
            }
        }

        return 'linux';
    }

    public static function detectLiteSpeed(): bool
    {
        return \VLT\CacheManager\ServerDetector::isLiteSpeed();
    }

    private static function installInstructions(string $panel): string
    {
        return match ($panel) {
            'cpanel'      => "Redis nerastas.\n\ncPanel/WHM: WHM → Redis Manager → Enable Redis.",
            'plesk'       => "Redis nerastas.\n\nPlesk: Tools & Settings → Updates → install Redis extension.",
            'directadmin' => "Redis nerastas.\n\nDirectAdmin: Extra Features → Redis → Enable.",
            'hestia'      => "Redis nerastas.\n\nHestiaCP: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'vesta'       => "Redis nerastas.\n\nVestaCP: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'cyberpanel'  => "Redis nerastas.\n\nCyberPanel: Packages → Install Redis.",
            'ispmanager'  => "Redis nerastas.\n\nISPmanager: Software → Redis → Install.",
            'interworx'   => "Redis nerastas.\n\nInterWorx: SSH → sudo yum install redis && sudo systemctl enable --now redis",
            default       => "Redis nerastas.\n\nUbuntu/Debian:\n  sudo apt install redis-server\n  sudo systemctl enable --now redis\n\nCentOS/RHEL:\n  sudo yum install redis\n  sudo systemctl enable --now redis\n\nAlternatyva — Dragonfly:\n  curl -fsSL https://packages.dragonflydb.io/install.sh | sudo bash",
        };
    }
}
