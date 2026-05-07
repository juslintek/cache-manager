<?php

declare(strict_types=1);

namespace VLT\CacheManager\Redis;

final class RedisDetector
{
    /** @return array{connected:bool, method:string, socket:string, host:string, port:int, version:string, panel:string, litespeed:bool, instructions:string} */
    public static function detect(): array
    {
        $result = [
            'connected'    => false,
            'method'       => '',
            'socket'       => '',
            'host'         => '127.0.0.1',
            'port'         => 6379,
            'version'      => '',
            'panel'        => self::detectPanel(),
            'litespeed'    => self::detectLiteSpeed(),
            'instructions' => '',
        ];

        // 1. Try known socket paths
        foreach (self::socketPaths() as $sock) {
            if (@file_exists($sock) && self::probeSocket($sock)) {
                $result['connected'] = true;
                $result['method']    = 'socket';
                $result['socket']    = $sock;
                $result['version']   = self::redisVersion('socket', $sock);
                return $result;
            }
        }

        // 2. Try TCP ports
        foreach ([6379, 6380, 6381] as $port) {
            if (self::probePort('127.0.0.1', $port)) {
                $result['connected'] = true;
                $result['method']    = 'tcp';
                $result['host']      = '127.0.0.1';
                $result['port']      = $port;
                $result['version']   = self::redisVersion('tcp', '127.0.0.1', $port);
                return $result;
            }
        }

        // 3. Parse redis.conf for clues
        $conf = self::parseRedisConf();
        if ($conf) {
            if (!empty($conf['unixsocket']) && self::probeSocket($conf['unixsocket'])) {
                $result['connected'] = true;
                $result['method']    = 'socket';
                $result['socket']    = $conf['unixsocket'];
                $result['version']   = self::redisVersion('socket', $conf['unixsocket']);
                return $result;
            }
            if (!empty($conf['port']) && (int) $conf['port'] > 0) {
                $host = $conf['bind'] ?? '127.0.0.1';
                $port = (int) $conf['port'];
                if (self::probePort($host, $port)) {
                    $result['connected'] = true;
                    $result['method']    = 'tcp';
                    $result['host']      = $host;
                    $result['port']      = $port;
                    $result['version']   = self::redisVersion('tcp', $host, $port);
                    return $result;
                }
            }
        }

        // Not found — build install instructions
        $result['instructions'] = self::installInstructions($result['panel']);
        return $result;
    }

    private static function socketPaths(): array
    {
        return [
            '/var/run/redis/redis.sock',
            '/var/run/redis/redis-server.sock',
            '/var/run/redis.sock',
            '/tmp/redis.sock',
            '/run/redis/redis.sock',
            '/usr/local/redis/var/redis.sock',
        ];
    }

    private static function probeSocket(string $path): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }
        try {
            $r = new \Redis();
            $ok = @$r->connect($path);
            if ($ok) {
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
            $ok = @$r->connect($host, $port, 0.5);
            if ($ok) {
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

    private static function parseRedisConf(): array
    {
        $paths = [
            '/etc/redis/redis.conf',
            '/etc/redis.conf',
            '/usr/local/etc/redis.conf',
            '/usr/local/redis/etc/redis.conf',
        ];
        foreach ($paths as $path) {
            if (!@is_readable($path)) {
                continue;
            }
            $conf = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
        return [];
    }

    public static function detectPanel(): string
    {
        if (@file_exists('/usr/local/cpanel/cpanel')) {
            return 'cpanel';
        }
        if (@file_exists('/usr/local/psa/version') || file_exists('/opt/psa/version')) {
            return 'plesk';
        }
        if (@file_exists('/usr/local/directadmin/directadmin')) {
            return 'directadmin';
        }
        if (@file_exists('/usr/local/mgr5/sbin/mgrctl')) {
            return 'ispmanager';
        }
        if (@file_exists('/usr/local/hestia/bin/v-list-users')) {
            return 'hestia';
        }
        if (@file_exists('/usr/local/vesta/bin/v-list-users')) {
            return 'vesta';
        }
        if (@is_dir('/etc/cyberpanel')) {
            return 'cyberpanel';
        }
        return 'linux';
    }

    public static function detectLiteSpeed(): bool
    {
        // Check server software header
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (stripos($server, 'litespeed') !== false || stripos($server, 'openlitespeed') !== false) {
            return true;
        }
        // Check for LiteSpeed binaries
        foreach (['/usr/local/lsws/bin/lswsctrl', '/usr/local/lsws/fcgi-bin/lsphp'] as $bin) {
            if (@file_exists($bin)) {
                return true;
            }
        }
        // Check if LiteSpeed Cache plugin is active
        if (defined('LSCWP_V') || class_exists('LiteSpeed_Cache') || class_exists('LiteSpeed\Core')) {
            return true;
        }
        return false;
    }

    private static function installInstructions(string $panel): string
    {
        return match ($panel) {
            'cpanel'      => "Redis nerastas.\n\ncPanel/WHM: WHM → Redis Manager → Enable Redis.\nArba: WHM → Terminal → sudo yum install redis && sudo systemctl enable --now redis",
            'plesk'       => "Redis nerastas.\n\nPlesk: Tools & Settings → Updates → install Redis extension.\nArba: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'directadmin' => "Redis nerastas.\n\nDirectAdmin: CustomBuild → select Redis → build.\nArba: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'hestia'      => "Redis nerastas.\n\nHestiaCP: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            'cyberpanel'  => "Redis nerastas.\n\nCyberPanel: Packages → Install Redis.\nArba: SSH → sudo apt install redis-server && sudo systemctl enable --now redis",
            default       => "Redis nerastas.\n\nUbuntu/Debian:\n  sudo apt install redis-server\n  sudo systemctl enable --now redis\n\nCentOS/RHEL:\n  sudo yum install redis\n  sudo systemctl enable --now redis\n\nAlternatyva — Dragonfly (Redis-compatible, greičiau):\n  curl -fsSL https://packages.dragonflydb.io/install.sh | sudo bash\n  sudo systemctl enable --now dragonfly",
        };
    }
}
