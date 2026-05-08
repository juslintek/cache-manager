<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin;

use VLT\CacheManager\Plugin;
use VLT\CacheManager\Redis\RedisFactory;
use VLT\CacheManager\Tracer\TracerConfig;

final class RestApi
{
    private const NS = 'vlt-cache/v1';

    public static function register(): void
    {
        // MCP Bridge endpoints (mcp-for-page-builders compatibility)
        $ns_mcp = 'mcp-for-page-builders/v1';
        $admin   = [self::class, 'canManage'];

        register_rest_route($ns_mcp, '/status', [
            'methods'             => 'GET',
            'callback'            => function () {
                $theme = wp_get_theme();
                return [
                    'version'             => '1.1.0',
                    'mu_plugins_writable' => wp_is_writable(WPMU_PLUGIN_DIR),
                    'theme'               => $theme->get_stylesheet(),
                    'parent_theme'        => $theme->get_template(),
                    'theme_dir'           => $theme->get_stylesheet_directory(),
                ];
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns_mcp, '/write-mu-plugin', [
            'methods'             => 'POST',
            'callback'            => function (\WP_REST_Request $req) {
                $name = sanitize_file_name($req['filename']);
                $name = preg_replace('/[^a-zA-Z0-9\-_]/', '', pathinfo($name, PATHINFO_FILENAME)) . '.php';
                $code = $req['php_code'];
                if (!str_starts_with($code, '<?php')) {
                    return new \WP_Error('invalid', 'PHP must start with <?php');
                }
                if (!wp_mkdir_p(WPMU_PLUGIN_DIR)) {
                    return new \WP_Error('fs', 'Cannot create mu-plugins dir');
                }
                $path = WPMU_PLUGIN_DIR . '/' . $name;
                file_put_contents($path, $code);
                return ['written' => $name, 'path' => $path];
            },
            'permission_callback' => $admin,
        ]);

        register_rest_route($ns_mcp, '/write-theme-file', [
            'methods'             => 'POST',
            'callback'            => function (\WP_REST_Request $req) {
                $file = ltrim($req['path'], '/');
                if (str_contains($file, '..')) {
                    return new \WP_Error('invalid', 'Path traversal not allowed');
                }
                $theme_dir = get_stylesheet_directory();
                $full      = $theme_dir . '/' . $file;
                if (!wp_mkdir_p(dirname($full))) {
                    return new \WP_Error('fs', 'Cannot create directory');
                }
                file_put_contents($full, $req['content']);
                return ['written' => $file, 'path' => $full, 'theme' => get_stylesheet()];
            },
            'permission_callback' => $admin,
        ]);

        register_rest_route($ns_mcp, '/read-theme-file', [
            'methods'             => 'GET',
            'callback'            => function (\WP_REST_Request $req) {
                $file = ltrim($req['path'], '/');
                if (str_contains($file, '..')) {
                    return new \WP_Error('invalid', 'Path traversal not allowed');
                }
                $full = get_stylesheet_directory() . '/' . $file;
                if (!file_exists($full)) {
                    return new \WP_Error('not_found', 'File not found: ' . $file, ['status' => 404]);
                }
                return ['path' => $file, 'content' => file_get_contents($full)];
            },
            'permission_callback' => $admin,
        ]);

        register_rest_route($ns_mcp, '/option/(?P<name>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => fn(\WP_REST_Request $req) => rest_ensure_response(get_option($req['name'])),
            'permission_callback' => $admin,
        ]);

        register_rest_route($ns_mcp, '/option/(?P<name>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'POST',
            'callback'            => function (\WP_REST_Request $req) {
                $val = $req->get_json_params()['value'] ?? null;
                update_option($req['name'], $val);
                return ['updated' => $req['name']];
            },
            'permission_callback' => $admin,
        ]);

        // Plugin's own routes
        register_rest_route(self::NS, '/logs', [
            'methods' => 'GET', 'callback' => [self::class, 'logs'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/logs/uris', [
            'methods' => 'GET', 'callback' => [self::class, 'uris'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/logs/stream', [
            'methods' => 'GET', 'callback' => [self::class, 'logsStream'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        // Redis explorer
        register_rest_route(self::NS, '/redis/(?P<sub>[a-z_]+)', [
            'methods' => 'GET', 'callback' => [self::class, 'redis'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/redis/save-config', [
            'methods' => 'POST', 'callback' => [self::class, 'redisSaveConfig'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        // Cloudflare
        register_rest_route(self::NS, '/cloudflare', [
            'methods' => 'GET', 'callback' => [self::class, 'cloudflare'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/cloudflare/stream', [
            'methods' => 'GET', 'callback' => [self::class, 'cfStream'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        register_rest_route(self::NS, '/purge/(?P<type>[a-z_]+)', [
            'methods' => 'POST', 'callback' => [self::class, 'purgeType'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        // Image optimization
        register_rest_route(self::NS, '/img-optm/status', [
            'methods' => 'GET', 'callback' => [self::class, 'imgOptmStatus'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/img-optm/run', [
            'methods' => 'POST', 'callback' => [self::class, 'imgOptmRun'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/dismiss-notice', [
            'methods' => 'POST', 'callback' => [self::class, 'dismissNotice'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/server-detect', [
            'methods' => 'POST', 'callback' => [self::class, 'serverDetect'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/config-save', [
            'methods' => 'POST', 'callback' => [self::class, 'configSave'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/gc-fix', [
            'methods' => 'POST', 'callback' => [self::class, 'gcFix'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/queue-run', [
            'methods' => 'POST', 'callback' => [self::class, 'queueRun'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/cron-stream', [
            'methods' => 'GET', 'callback' => [self::class, 'cronStream'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/cron-stats', [
            'methods' => 'GET', 'callback' => [self::class, 'cronStats'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        // Tracer
        register_rest_route(self::NS, '/tracer/stream', [
            'methods' => 'GET', 'callback' => [self::class, 'tracerStream'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
        register_rest_route(self::NS, '/tracer/(?P<sub>[a-z_]+)', [
            'methods' => 'GET', 'callback' => [self::class, 'tracer'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    // ── Logs ──

    public static function logs(\WP_REST_Request $req): \WP_REST_Response
    {
        $logger = Plugin::instance()->logger();
        $date   = sanitize_text_field($req->get_param('date') ?? gmdate('Y-m-d'));
        $type   = sanitize_key($req->get_param('type') ?? '');
        $ip     = sanitize_text_field($req->get_param('ip') ?? '');
        $uri    = sanitize_text_field($req->get_param('uri') ?? '');
        $user   = sanitize_text_field($req->get_param('user') ?? '');
        $since  = sanitize_text_field($req->get_param('since') ?? '');
        $groups = array_filter(explode(',', sanitize_text_field($req->get_param('group') ?? '')));

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
            return new \WP_REST_Response(['rows' => AdminAjax::buildGroups($entries, $groups), 'meta' => $meta]);
        }

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

        return new \WP_REST_Response(['rows' => array_values(array_reverse($entries)), 'meta' => $meta]);
    }

    public static function uris(\WP_REST_Request $req): \WP_REST_Response
    {
        $date    = sanitize_text_field($req->get_param('date') ?? gmdate('Y-m-d'));
        $entries = Plugin::instance()->logger()->readLog($date);
        $uris    = array_values(array_unique(array_filter(array_column($entries, 'uri'))));
        sort($uris);
        return new \WP_REST_Response($uris);
    }

    // ── Redis ──

    public static function redis(\WP_REST_Request $req): \WP_REST_Response
    {
        $sub = $req->get_param('sub');

        if ($sub === 'detect') {
            return self::redisDetect();
        }

        $r = RedisFactory::create(2.0);
        if (!$r) {
            return new \WP_REST_Response(['error' => 'Redis nepasiekiamas'], 503);
        }
        return match ($sub) {
            'stats'        => self::redisStats($r),
            'keys'         => self::redisKeys($r, $req),
            'preview'      => self::redisPreview($r, $req),
            'delete'       => self::redisDelete($r, $req),
            'delete_group' => self::redisDeleteGroup($r, $req),
            'detect'       => self::redisDetect(),
            default        => new \WP_REST_Response(['error' => 'Unknown'], 400),
        };
    }

    private static function redisDetect(): \WP_REST_Response
    {
        return new \WP_REST_Response(\VLT\CacheManager\Redis\RedisDetector::detect());
    }

    public static function redisSaveConfig(\WP_REST_Request $req): \WP_REST_Response
    {
        $socket = sanitize_text_field($req->get_param('socket') ?? '');
        $host   = sanitize_text_field($req->get_param('host') ?? '');
        $port   = (int) ($req->get_param('port') ?? 0);

        update_option('vlt_redis_socket', $socket);
        update_option('vlt_redis_host', $host);
        update_option('vlt_redis_port', $port);

        // Verify connection with new config
        $r = \VLT\CacheManager\Redis\RedisFactory::create(2.0);
        if (!$r) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'Nepavyko prisijungti su naujais nustatymais'], 400);
        }
        $r->close();
        return new \WP_REST_Response(['ok' => true]);
    }

    private static function redisStats(\Redis $r): \WP_REST_Response
    {
        $info = $r->info();
        $keys = $r->keys('vlt_*');
        $groups = [];
        foreach ($keys as $k) {
            $group = 'default';
            if (preg_match('/^vlt_([^:]+)/', $k, $m)) {
                $group = $m[1];
            }
            if (!isset($groups[$group])) {
                $groups[$group] = ['name' => $group, 'count' => 0, 'size' => 0];
            }
            $groups[$group]['count']++;
        }
        foreach ($groups as $name => &$g) {
            $sample_keys = array_filter($keys, fn($k) => str_starts_with($k, 'vlt_' . $name . ':'));
            $sample = array_slice($sample_keys, 0, 50);
            $total_size = 0;
            foreach ($sample as $sk) {
                $total_size += strlen($r->get($sk) ?: '');
            }
            $avg = count($sample) > 0 ? $total_size / count($sample) : 0;
            $g['size'] = (int) ($avg * $g['count']);
        }
        unset($g);
        usort($groups, fn($a, $b) => $b['count'] <=> $a['count']);
        $hits   = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $rate   = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) : 0;
        return new \WP_REST_Response([
            'connected' => true, 'memory' => $info['used_memory_human'] ?? '—',
            'memory_peak' => $info['used_memory_peak_human'] ?? '—', 'keys' => count($keys),
            'hits' => $hits, 'misses' => $misses, 'hit_rate' => $rate,
            'expired' => (int) ($info['expired_keys'] ?? 0),
            'uptime' => round(($info['uptime_in_seconds'] ?? 0) / 86400, 1) . ' d.',
            'groups' => array_values($groups),
        ]);
    }

    private static function redisKeys(\Redis $r, \WP_REST_Request $req): \WP_REST_Response
    {
        $group = sanitize_text_field($req->get_param('group') ?? 'default');
        $keys  = $r->keys('vlt_' . $group . ':*');
        if (!$keys && $group === 'default') {
            $keys = array_filter($r->keys('vlt_*'), fn($k) => substr_count($k, ':') === 0);
        }
        $result = [];
        foreach (array_slice($keys, 0, 500) as $k) {
            $val = $r->get($k);
            $result[] = ['key' => $k, 'size' => $val !== false ? strlen($val) : 0, 'ttl' => $r->ttl($k)];
        }
        usort($result, fn($a, $b) => $b['size'] <=> $a['size']);
        return new \WP_REST_Response($result);
    }

    private static function redisPreview(\Redis $r, \WP_REST_Request $req): \WP_REST_Response
    {
        $key = sanitize_text_field($req->get_param('key') ?? '');
        if (!$key || !$r->exists($key)) {
            return new \WP_REST_Response(['error' => 'Not found'], 404);
        }
        $raw  = $r->get($key);
        $type = $r->type($key);
        $ttl  = $r->ttl($key);
        $size = strlen($raw ?: '');
        $names = [0 => 'none', 1 => 'string', 2 => 'set', 3 => 'list', 4 => 'zset', 5 => 'hash'];
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
        if (strlen($raw) > 50000) $raw = substr($raw, 0, 50000) . "\n…truncated";
        if (strlen($pretty) > 50000) $pretty = substr($pretty, 0, 50000) . "\n…truncated";
        return new \WP_REST_Response([
            'key' => $key, 'type' => $names[$type] ?? 'unknown', 'ttl' => $ttl,
            'size' => $size, 'serialized' => $serialized, 'raw' => $raw, 'pretty' => $pretty,
        ]);
    }

    private static function redisDelete(\Redis $r, \WP_REST_Request $req): \WP_REST_Response
    {
        $key = sanitize_text_field($req->get_param('key') ?? '');
        if ($key) {
            $r->del($key);
        }
        return new \WP_REST_Response(['ok' => true]);
    }

    private static function redisDeleteGroup(\Redis $r, \WP_REST_Request $req): \WP_REST_Response
    {
        $group = sanitize_text_field($req->get_param('group') ?? '');
        if (!$group) {
            return new \WP_REST_Response(['error' => 'No group'], 400);
        }
        $keys = $r->keys('vlt_' . $group . ':*');
        if ($keys) {
            $r->del(...$keys);
        }
        return new \WP_REST_Response(['deleted' => count($keys)]);
    }

    // ── Cloudflare ──

    public static function cloudflare(\WP_REST_Request $req): \WP_REST_Response
    {
        $date = sanitize_text_field($req->get_param('date') ?? gmdate('Y-m-d'));

        if ($date === gmdate('Y-m-d')) {
            $r = RedisFactory::create(0.5);
            if ($r) {
                $raw = $r->lRange('vlt_cf_live', 0, 499);
                $r->close();
                if ($raw) {
                    return new \WP_REST_Response(array_map(fn($j) => json_decode($j, true), $raw));
                }
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
                'ts' => $d['ts'] ?? $e['timestamp'] ?? '', 'ray' => $d['ray'] ?? '',
                'country' => $d['country'] ?? '', 'ip' => $d['ip'] ?? $e['ip'] ?? '',
                'uri' => $d['uri'] ?? $e['uri'] ?? '', 'user_id' => $uid,
                'user_name' => $d['user_name'] ?? $e['user_name'] ?? '',
                'user_email' => $d['user_email'] ?? '', 'challenged' => !empty($d['challenged']),
                'ua' => $d['ua'] ?? '',
            ];
            if ($uid > 0 && empty($row['user_email'])) {
                if (!isset($uc[$uid])) {
                    $u = get_userdata($uid);
                    $uc[$uid] = $u ? ['n' => $u->display_name, 'e' => $u->user_email] : null;
                }
                if ($uc[$uid]) {
                    $row['user_name'] = $uc[$uid]['n'];
                    $row['user_email'] = $uc[$uid]['e'];
                }
            }
            $rows[] = $row;
        }
        return new \WP_REST_Response(array_values(array_reverse($rows)));
    }

    public static function cfStream(): void
    {
        self::sseLoop('vlt_cf_live', 'ray');
    }

    // ── Logs Stream ──

    public static function logsStream(): void
    {
        self::sseLoop('vlt_logs_live', 'timestamp');
    }

    // ── Tracer Stream ──

    public static function tracerStream(): void
    {
        self::sseLoop('vlt_traces', 'id');
    }

    /**
     * Generic SSE loop: watches a Redis list for new entries by tracking the newest item's unique field.
     */
    private static function sseLoop(string $key, string $idField): void
    {
        $r = RedisFactory::create(1.0);
        if (!$r) {
            status_header(503);
            exit('Redis unavailable');
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) {
            ob_end_clean();
        }
        set_time_limit(0);
        ignore_user_abort(false);

        $lastId = null;
        $first = $r->lIndex($key, 0);
        if ($first) {
            $d = json_decode(trim($first), true);
            $lastId = $d[$idField] ?? null;
        }

        while (!connection_aborted()) {
            $newest = $r->lIndex($key, 0);
            if ($newest) {
                $d = json_decode(trim($newest), true);
                $currentId = $d[$idField] ?? null;
                if ($currentId && $currentId !== $lastId) {
                    $items = $r->lRange($key, 0, 49);
                    $fresh = [];
                    foreach ($items as $item) {
                        $entry = json_decode(trim($item), true);
                        if (($entry[$idField] ?? null) === $lastId) {
                            break;
                        }
                        $fresh[] = $entry;
                    }
                    if ($fresh) {
                        echo "data: " . json_encode($fresh) . "\n\n";
                        flush();
                    }
                    $lastId = $currentId;
                } else {
                    echo ": heartbeat\n\n";
                    flush();
                }
            } else {
                echo ": heartbeat\n\n";
                flush();
            }
            sleep(1);
        }
        $r->close();
        exit;
    }

    // ── Image Optimization ──

    public static function imgOptmStatus(): \WP_REST_Response
    {
        return new \WP_REST_Response(\VLT\CacheManager\Image\ImageOptimizer::status());
    }

    public static function imgOptmRun(\WP_REST_Request $req): \WP_REST_Response
    {
        $limit = max(1, min(500, (int) ($req->get_param('limit') ?? 50)));
        return new \WP_REST_Response(\VLT\CacheManager\Image\ImageOptimizer::runBulk($limit));
    }

    public static function dismissNotice(\WP_REST_Request $req): \WP_REST_Response
    {
        $notice = sanitize_key($req->get_json_params()['notice'] ?? '');
        if ($notice) {
            update_option($notice, true);
        }
        return new \WP_REST_Response(['ok' => true]);
    }

    public static function serverDetect(): \WP_REST_Response
    {
        return new \WP_REST_Response(\VLT\CacheManager\ServerDetector::runAndStore());
    }

    public static function configSave(\WP_REST_Request $req): \WP_REST_Response
    {
        $path    = $req->get_json_params()['path'] ?? '';
        $content = $req->get_json_params()['content'] ?? '';

        if (!$path || !$content) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'Missing path or content'], 400);
        }

        // Security: only allow known OLS/DA config paths
        $allowed = [
            '/etc/openlitespeed/',
            '/usr/local/directadmin/data/templates/custom/',
            '/usr/local/lsws/conf/',
        ];
        $allowed_path = false;
        foreach ($allowed as $prefix) {
            if (str_starts_with(realpath($path) ?: $path, $prefix)) {
                $allowed_path = true;
                break;
            }
        }
        if (!$allowed_path) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'Path not allowed'], 403);
        }

        if (!@is_writable($path) && @file_exists($path)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'File not writable'], 403);
        }

        // Create parent dir if needed (for custom/ template)
        @wp_mkdir_p(dirname($path));
        $ok = file_put_contents($path, $content);
        return new \WP_REST_Response(['ok' => $ok !== false]);
    }

    public static function purgeType(\WP_REST_Request $req): \WP_REST_Response
    {
        @ini_set('memory_limit', '512M');
        $type = sanitize_key($req->get_param('type'));
        \VLT\CacheManager\Plugin::instance()->purge()->purge($type);
        return new \WP_REST_Response(['ok' => true, 'type' => $type]);
    }

    public static function gcFix(): \WP_REST_Response
    {
        $applied = \VLT\CacheManager\Performance\GCAnalyzer::applyAutoFixes();
        return new \WP_REST_Response(['ok' => true, 'applied' => $applied]);
    }

    public static function queueRun(): \WP_REST_Response
    {
        $before = \VLT\CacheManager\Async\AsyncQueue::status()['queue_length'];
        \VLT\CacheManager\Async\AsyncQueue::processQueue();
        $after  = \VLT\CacheManager\Async\AsyncQueue::status()['queue_length'];
        return new \WP_REST_Response(['ok' => true, 'processed' => max(0, $before - $after)]);
    }

    public static function cronStats(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'log'   => \VLT\CacheManager\Async\CronMonitor::recentLog(50),
            'stats' => \VLT\CacheManager\Async\CronMonitor::hookStats(),
        ]);
    }

    public static function cronStream(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0);
        ignore_user_abort(false);

        $lastTs = (float) ($_GET['since'] ?? 0);

        while (!connection_aborted()) {
            $entries = \VLT\CacheManager\Async\CronMonitor::recentLog(20, (string) $lastTs);
            if ($entries) {
                echo 'data: ' . json_encode($entries) . "\n\n";
                $lastTs = max(array_column($entries, 'ts'));
                flush();
            } else {
                echo ": heartbeat\n\n";
                flush();
            }
            sleep(1);
        }
        exit;
    }

    // ── Tracer ──

    public static function tracer(\WP_REST_Request $req): \WP_REST_Response
    {
        $sub = $req->get_param('sub');

        if ($sub === 'live') {
            $r = RedisFactory::create(0.5);
            if (!$r) {
                return new \WP_REST_Response([]);
            }
            $raw = $r->lRange(TracerConfig::VLT_TR_KEY, 0, 49);
            $r->close();
            return new \WP_REST_Response(array_map(fn($j) => json_decode($j, true), $raw ?: []));
        }

        if ($sub === 'history') {
            $date = sanitize_text_field($req->get_param('date') ?? gmdate('Y-m-d'));
            $f    = TracerConfig::getDir() . '/trace-' . $date . '.json';
            if (!file_exists($f)) {
                return new \WP_REST_Response([]);
            }
            $lines  = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $decode = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
            return new \WP_REST_Response(array_filter(array_map(fn($l) => $decode($l, true), $lines ?: [])));
        }

        if ($sub === 'set_rate') {
            $rate = max(0, min(100, (int) ($req->get_param('rate') ?? 0)));
            update_option('vlt_trace_sample_rate', $rate);
            if (!is_dir(TracerConfig::getDir())) {
                wp_mkdir_p(TracerConfig::getDir());
            }
            file_put_contents(TracerConfig::getDir() . '/.sample_rate', $rate);
            return new \WP_REST_Response(['ok' => true]);
        }

        if ($sub === 'set_max') {
            $max = max(10, min(10000, (int) ($req->get_param('max') ?? 200)));
            update_option('vlt_trace_max', $max);
            if (!is_dir(TracerConfig::getDir())) {
                wp_mkdir_p(TracerConfig::getDir());
            }
            file_put_contents(TracerConfig::getDir() . '/.max_traces', $max);
            return new \WP_REST_Response(['ok' => true]);
        }

        return new \WP_REST_Response(['error' => 'Unknown sub'], 400);
    }
}
