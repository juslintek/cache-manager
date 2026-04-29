<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin;

use VLT\CacheManager\Plugin;
use VLT\CacheManager\Redis\RedisFactory;
use VLT\CacheManager\Tracer\TracerConfig;

final class AdminAjax
{
    public static function logs(): void
    {
        check_ajax_referer('vlt_cache_logs');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $logger = Plugin::instance()->logger();
        $date   = sanitize_text_field($_GET['date'] ?? gmdate('Y-m-d'));
        $type   = sanitize_key($_GET['type'] ?? '');
        $ip     = sanitize_text_field($_GET['ip'] ?? '');
        $uri    = sanitize_text_field($_GET['uri'] ?? '');
        $user   = sanitize_text_field($_GET['user'] ?? '');
        $since  = sanitize_text_field($_GET['since'] ?? '');
        $groups = array_filter(explode(',', sanitize_text_field($_GET['group'] ?? '')));

        $entries = $logger->readLog($date);

        foreach ($entries as &$e) {
            $e['hits'] = $e['misses'] = 0;
            if (($e['type'] ?? '') === 'stats' && is_array($e['details'] ?? null)) {
                $e['hits']   = (int) ($e['details']['hits'] ?? 0);
                $e['misses'] = (int) ($e['details']['misses'] ?? 0);
            }
            $e['details_str'] = is_array($e['details'] ?? null)
                ? implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($e['details']), $e['details']))
                : (string) ($e['details'] ?? '');
        }
        unset($e);

        if ($since) {
            $entries = array_values(array_filter($entries, fn($e) => ($e['timestamp'] ?? '') > $since));
        }
        if ($type)  $entries = array_values(array_filter($entries, fn($e) => ($e['type'] ?? '') === $type));
        if ($ip)    $entries = array_values(array_filter($entries, fn($e) => str_contains($e['ip'] ?? '', $ip)));
        if ($uri)   $entries = array_values(array_filter($entries, fn($e) => str_contains($e['uri'] ?? '', $uri)));
        if ($user)  $entries = array_values(array_filter($entries, fn($e) =>
            str_contains(strtolower($e['user_name'] ?? ''), strtolower($user)) || (string) ($e['user_id'] ?? '') === $user
        ));

        $totalHits   = array_sum(array_column($entries, 'hits'));
        $totalMisses = array_sum(array_column($entries, 'misses'));
        $ratio = ($totalHits + $totalMisses) > 0 ? round($totalHits / ($totalHits + $totalMisses) * 100, 1) : 0;
        $meta  = ['total' => count($entries), 'totalHits' => $totalHits, 'totalMisses' => $totalMisses, 'ratio' => $ratio];

        if ($groups) {
            wp_send_json_success(['rows' => self::buildGroups($entries, $groups), 'meta' => $meta]);
        }

        // Enrich with user data
        $uc = [];
        foreach ($entries as &$e) {
            $uid = (int) ($e['user_id'] ?? 0);
            if ($uid > 0) {
                if (!isset($uc[$uid])) {
                    $u = get_userdata($uid);
                    $uc[$uid] = $u ? ['name' => $u->display_name, 'email' => $u->user_email, 'url' => get_edit_user_link($uid)] : null;
                }
                if ($uc[$uid]) {
                    $e['user_name']   = $uc[$uid]['name'];
                    $e['user_email']  = $uc[$uid]['email'];
                    $e['profile_url'] = $uc[$uid]['url'];
                }
            }
        }
        unset($e);

        $entries = array_reverse($entries);
        wp_send_json_success(['rows' => array_values($entries), 'meta' => $meta]);
    }

    public static function uris(): void
    {
        check_ajax_referer('vlt_cache_logs');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $date    = sanitize_text_field($_GET['date'] ?? gmdate('Y-m-d'));
        $entries = Plugin::instance()->logger()->readLog($date);
        $uris    = array_values(array_unique(array_filter(array_column($entries, 'uri'))));
        sort($uris);
        wp_send_json_success($uris);
    }

    public static function redis(): void
    {
        check_ajax_referer('vlt_redis');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $r = RedisFactory::create(2.0);
        if (!$r) {
            wp_send_json_error('Redis nepasiekiamas');
            return;
        }

        $sub = sanitize_key($_GET['sub'] ?? '');

        match ($sub) {
            'stats'        => self::redisStats($r),
            'keys'         => self::redisKeys($r),
            'preview'      => self::redisPreview($r),
            'delete'       => self::redisDelete($r),
            'delete_group' => self::redisDeleteGroup($r),
            default        => wp_send_json_error('Unknown action'),
        };
    }

    public static function cloudflare(): void
    {
        check_ajax_referer('vlt_cache_cf');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $date = sanitize_text_field($_GET['date'] ?? gmdate('Y-m-d'));

        if ($date === gmdate('Y-m-d')) {
            try {
                $r = RedisFactory::create(0.5);
                if ($r) {
                    $raw = $r->lRange('vlt_cf_live', 0, 499);
                    $r->close();
                    if ($raw) {
                        $rows = array_map(fn($j) => json_decode($j, true), $raw);
                        wp_send_json_success($rows);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $entries = Plugin::instance()->logger()->readLog($date);
        $cf   = array_filter($entries, fn($e) => ($e['type'] ?? '') === 'cloudflare');
        $rows = [];
        $uc   = [];
        foreach ($cf as $e) {
            $d   = is_array($e['details'] ?? null) ? $e['details'] : [];
            $uid = (int) ($d['user_id'] ?? $e['user_id'] ?? 0);
            $row = [
                'ts'         => $d['ts'] ?? $e['timestamp'] ?? '',
                'ray'        => $d['ray'] ?? $d['cf_ray'] ?? '',
                'country'    => $d['country'] ?? $d['cf_country'] ?? '',
                'ip'         => $d['ip'] ?? $d['cf_ip'] ?? $e['ip'] ?? '',
                'uri'        => $d['uri'] ?? $e['uri'] ?? '',
                'user_id'    => $uid,
                'user_name'  => $d['user_name'] ?? $e['user_name'] ?? '',
                'user_email' => $d['user_email'] ?? '',
                'challenged' => !empty($d['challenged']),
            ];
            if ($uid > 0 && empty($row['user_email'])) {
                if (!isset($uc[$uid])) {
                    $u = get_userdata($uid);
                    $uc[$uid] = $u ? ['n' => $u->display_name, 'e' => $u->user_email] : null;
                }
                if ($uc[$uid]) {
                    $row['user_name']  = $uc[$uid]['n'];
                    $row['user_email'] = $uc[$uid]['e'];
                }
            }
            $rows[] = $row;
        }
        wp_send_json_success(array_values(array_reverse($rows)));
    }

    public static function tracer(): void
    {
        check_ajax_referer('vlt_tracer');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $sub = sanitize_key($_GET['sub'] ?? '');

        if ($sub === 'live') {
            try {
                $r = RedisFactory::create(0.5);
                if (!$r) {
                    wp_send_json_success([]);
                }
                $raw = $r->lRange(TracerConfig::VLT_TR_KEY, 0, 49);
                $r->close();
                wp_send_json_success(array_map(fn($j) => json_decode($j, true), $raw ?: []));
            } catch (\Throwable $e) {
                wp_send_json_success([]);
            }
        } elseif ($sub === 'history') {
            $date = sanitize_text_field($_GET['date'] ?? gmdate('Y-m-d'));
            $f    = TracerConfig::getDir() . '/trace-' . $date . '.json';
            if (!file_exists($f)) {
                wp_send_json_success([]);
            }
            $lines  = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $decode = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
            wp_send_json_success(array_filter(array_map(fn($l) => $decode($l, true), $lines ?: [])));
        } elseif ($sub === 'set_rate') {
            $rate = max(0, min(100, (int) ($_GET['rate'] ?? 0)));
            update_option('vlt_trace_sample_rate', $rate);
            if (!is_dir(TracerConfig::getDir())) wp_mkdir_p(TracerConfig::getDir());
            file_put_contents(TracerConfig::getDir() . '/.sample_rate', $rate);
            wp_send_json_success();
        } elseif ($sub === 'set_max') {
            $max = max(10, min(10000, (int) ($_GET['max'] ?? 200)));
            update_option('vlt_trace_max', $max);
            if (!is_dir(TracerConfig::getDir())) {
                wp_mkdir_p(TracerConfig::getDir());
            }
            file_put_contents(TracerConfig::getDir() . '/.max_traces', $max);
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }

    public static function buildGroups(array $entries, array $groupKeys): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $parts = [];
            foreach ($groupKeys as $gk) {
                $parts[] = match ($gk) {
                    'minute' => substr($e['timestamp'] ?? '', 0, 16),
                    'hour'   => substr($e['timestamp'] ?? '', 0, 13),
                    'day'    => substr($e['timestamp'] ?? '', 0, 10),
                    'week'   => date('Y-\WW', strtotime($e['timestamp'] ?? 'now')),
                    'month'  => substr($e['timestamp'] ?? '', 0, 7),
                    'year'   => substr($e['timestamp'] ?? '', 0, 4),
                    'uri'    => $e['uri'] ?? '—',
                    'ip'     => $e['ip'] ?? '—',
                    'type'   => $e['type'] ?? '—',
                    'user_id' => (string) ($e['user_id'] ?? 0),
                    default  => '—',
                };
            }
            $key = implode(' | ', $parts);
            if (!isset($groups[$key])) {
                $label = $key;
                if (in_array('user_id', $groupKeys, true)) {
                    $uid = (int) ($e['user_id'] ?? 0);
                    if ($uid > 0) {
                        $u = get_userdata($uid);
                        if ($u) $label = str_replace((string) $uid, $u->display_name . ' (' . $u->user_email . ')', $label);
                    }
                }
                $groups[$key] = ['label' => $label, 'count' => 0, 'hits' => 0, 'misses' => 0, 'purges' => 0, 'children' => []];
            }
            $groups[$key]['count']++;
            $groups[$key]['hits']   += (int) ($e['hits'] ?? 0);
            $groups[$key]['misses'] += (int) ($e['misses'] ?? 0);
            if (($e['type'] ?? '') === 'purge') $groups[$key]['purges']++;
            if (count($groups[$key]['children']) < 50) {
                $groups[$key]['children'][] = [
                    'timestamp' => $e['timestamp'] ?? '',
                    'type'      => $e['type'] ?? '',
                    'hits'      => $e['hits'] ?? 0,
                    'misses'    => $e['misses'] ?? 0,
                    'uri'       => $e['uri'] ?? '',
                    'ip'        => $e['ip'] ?? '',
                ];
            }
        }
        return array_values($groups);
    }

    private static function redisStats(\Redis $r): void
    {
        $info = $r->info();
        $keys = $r->keys('vlt_*');
        $groups = [];
        foreach ($keys as $k) {
            $group = 'default';
            if (preg_match('/^vlt_([^:]+)/', $k, $m)) $group = $m[1];
            if (!isset($groups[$group])) $groups[$group] = ['name' => $group, 'count' => 0, 'size' => 0];
            $groups[$group]['count']++;
        }
        foreach ($groups as $name => &$g) {
            $sample_keys = array_filter($keys, fn($k) => str_starts_with($k, 'vlt_' . $name . ':') || ($name === 'default' && substr_count($k, ':') === 0));
            $sample = array_slice($sample_keys, 0, 50);
            $total_size = 0;
            foreach ($sample as $sk) $total_size += strlen($r->get($sk) ?: '');
            $avg = count($sample) > 0 ? $total_size / count($sample) : 0;
            $g['size'] = (int) ($avg * $g['count']);
        }
        unset($g);
        usort($groups, fn($a, $b) => $b['count'] <=> $a['count']);
        $hits   = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $rate   = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) : 0;
        wp_send_json_success([
            'connected'   => true,
            'memory'      => $info['used_memory_human'] ?? '—',
            'memory_peak' => $info['used_memory_peak_human'] ?? '—',
            'keys'        => count($keys),
            'hits'        => $hits,
            'misses'      => $misses,
            'hit_rate'    => $rate,
            'expired'     => (int) ($info['expired_keys'] ?? 0),
            'evicted'     => (int) ($info['evicted_keys'] ?? 0),
            'uptime'      => round(($info['uptime_in_seconds'] ?? 0) / 86400, 1) . ' d.',
            'groups'      => array_values($groups),
        ]);
    }

    private static function redisKeys(\Redis $r): void
    {
        $group   = sanitize_text_field($_GET['group'] ?? 'default');
        $pattern = 'vlt_' . $group . ':*';
        $keys    = $r->keys($pattern);
        if (!$keys && $group === 'default') {
            $all  = $r->keys('vlt_*');
            $keys = array_filter($all, fn($k) => substr_count($k, ':') === 0);
        }
        $result = [];
        foreach (array_slice($keys, 0, 500) as $k) {
            $val = $r->get($k);
            $result[] = ['key' => $k, 'size' => $val !== false ? strlen($val) : 0, 'ttl' => $r->ttl($k)];
        }
        usort($result, fn($a, $b) => $b['size'] <=> $a['size']);
        wp_send_json_success($result);
    }

    private static function redisPreview(\Redis $r): void
    {
        $key = sanitize_text_field($_GET['key'] ?? '');
        if (!$key || !$r->exists($key)) {
            wp_send_json_error('Raktas nerastas');
        }
        $raw  = $r->get($key);
        $type = $r->type($key);
        $ttl  = $r->ttl($key);
        $size = strlen($raw ?: '');
        $type_names = [0 => 'none', 1 => 'string', 2 => 'set', 3 => 'list', 4 => 'zset', 5 => 'hash'];
        $serialized = false;
        $pretty = $raw;
        $unserialized = @unserialize($raw);
        if ($unserialized !== false || $raw === 'b:0;') {
            $serialized = true;
            $pretty = print_r($unserialized, true);
        } else {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (strlen($raw) > 50000) $raw = substr($raw, 0, 50000) . "\n\n... (sutrumpinta)";
        if (strlen($pretty) > 50000) $pretty = substr($pretty, 0, 50000) . "\n\n... (sutrumpinta)";
        wp_send_json_success([
            'key' => $key, 'type' => $type_names[$type] ?? 'unknown', 'ttl' => $ttl,
            'size' => $size, 'serialized' => $serialized, 'raw' => $raw, 'pretty' => $pretty,
        ]);
    }

    private static function redisDelete(\Redis $r): void
    {
        $key = sanitize_text_field($_GET['key'] ?? '');
        if ($key) $r->del($key);
        wp_send_json_success();
    }

    private static function redisDeleteGroup(\Redis $r): void
    {
        $group = sanitize_text_field($_GET['group'] ?? '');
        if (!$group) {
            wp_send_json_error();
        }
        $keys = $r->keys('vlt_' . $group . ':*');
        if ($keys) $r->del(...$keys);
        wp_send_json_success(['deleted' => count($keys)]);
    }
}
