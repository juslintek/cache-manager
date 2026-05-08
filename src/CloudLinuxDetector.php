<?php

declare(strict_types=1);

namespace VLT\CacheManager;

/**
 * CloudLinux detection and optimization status.
 * Reads LVE limits, AccelerateWP, PHP Selector, MySQL Governor status.
 */
final class CloudLinuxDetector
{
    public static function isCloudLinux(): bool
    {
        return @file_exists('/etc/cloudlinux-release') || @file_exists('/etc/cl-release');
    }

    public static function version(): string
    {
        $f = @file_get_contents('/etc/cloudlinux-release') ?: @file_get_contents('/etc/cl-release') ?: '';
        preg_match('/release\s+([\d.]+)/i', $f, $m);
        return $m[1] ?? '';
    }

    /** @return array{cpu:string, pmem:string, ep:string, nproc:string, io:string} */
    public static function lveInfo(): array
    {
        $uid = posix_getuid();
        $raw = @shell_exec('lvectl list --json 2>/dev/null') ?: '';
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true)['data'] ?? [];
        foreach ($data as $entry) {
            if ((string)($entry['ID'] ?? '') === (string)$uid) {
                return [
                    'cpu'   => ($entry['CPU'] ?? '0') . '%',
                    'pmem'  => $entry['PMEM'] ?? '0',
                    'ep'    => $entry['EP'] ?? '0',
                    'nproc' => $entry['NPROC'] ?? '0',
                    'io'    => $entry['IO'] ?? '0',
                ];
            }
        }
        return [];
    }

    public static function accelerateWpInstalled(): bool
    {
        return @is_dir('/usr/share/cloudlinux/wpos');
    }

    public static function redisEnabled(): bool
    {
        $user = self::currentUser();
        return $user && @file_exists("/home/{$user}/.redis/redis.sock");
    }

    public static function mysqlGovernorInstalled(): bool
    {
        return @file_exists('/usr/sbin/db_governor');
    }

    public static function phpSelectorEnabled(): bool
    {
        return @file_exists('/usr/bin/cloudlinux-selector') || @file_exists('/usr/sbin/cloudlinux-selector');
    }

    public static function cageFsEnabled(): bool
    {
        return @file_exists('/usr/sbin/cagefsctl');
    }

    /** @return array[] Optimization recommendations */
    public static function recommendations(): array
    {
        $recs = [];

        if (!self::redisEnabled()) {
            $recs[] = [
                'title'   => 'Redis Object Cache',
                'status'  => 'disabled',
                'benefit' => 'Iki 10× greitesnis WordPress — objektų talpykla Redis atmintyje',
                'fix_da'  => 'DirectAdmin → Extra Features → Redis → Enable',
                'fix_cmd' => null,
            ];
        } else {
            $recs[] = [
                'title'   => 'Redis Object Cache',
                'status'  => 'enabled',
                'benefit' => 'Objektų talpykla veikia',
                'fix_da'  => null,
                'fix_cmd' => null,
            ];
        }

        if (!self::phpSelectorEnabled()) {
            $recs[] = [
                'title'   => 'PHP Selector',
                'status'  => 'unknown',
                'benefit' => 'Pasirinkite optimalią PHP versiją kiekvienam domenui',
                'fix_da'  => 'DirectAdmin → PHP Settings → PHP Selector',
                'fix_cmd' => null,
            ];
        } else {
            $recs[] = [
                'title'   => 'PHP Selector',
                'status'  => 'enabled',
                'benefit' => 'PHP versijų pasirinkimas aktyvus',
                'fix_da'  => null,
                'fix_cmd' => null,
            ];
        }

        if (self::accelerateWpInstalled()) {
            $recs[] = [
                'title'   => 'AccelerateWP',
                'status'  => 'available',
                'benefit' => 'CloudLinux AccelerateWP: automatinis WordPress optimizavimas (object cache, CDN, kritiniai CSS)',
                'fix_da'  => 'DirectAdmin → AccelerateWP (jei matomas) arba kreipkitės į hostingo administratorių',
                'fix_cmd' => null,
            ];
        }

        if (self::mysqlGovernorInstalled()) {
            $recs[] = [
                'title'   => 'MySQL Governor',
                'status'  => 'installed',
                'benefit' => 'MySQL Governor riboja lėtas užklausas — apsaugo nuo DB perkrovos',
                'fix_da'  => null,
                'fix_cmd' => null,
            ];
        }

        // OPcache check
        if (!function_exists('opcache_get_status') || !opcache_get_status(false)) {
            $recs[] = [
                'title'   => 'OPcache',
                'status'  => 'disabled',
                'benefit' => 'OPcache iki 3× pagreitina PHP — kompiliuotų skriptų talpykla',
                'fix_da'  => 'DirectAdmin → PHP Settings → Extensions → opcache',
                'fix_cmd' => 'cloudlinux-selector set --interpreter php --user ' . (self::currentUser() ?: 'USER') . ' --extensions \'{"opcache":"enabled"}\'',
            ];
        }

        return $recs;
    }

    private static function currentUser(): string
    {
        if (defined('ABSPATH') && preg_match('#^/home/([^/]+)/#', ABSPATH, $m)) {
            return $m[1];
        }
        return function_exists('get_current_user') ? get_current_user() : '';
    }
}
