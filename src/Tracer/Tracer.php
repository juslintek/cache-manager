<?php

declare(strict_types=1);

namespace VLT\CacheManager\Tracer;

use VLT\CacheManager\Redis\RedisFactory;

final class Tracer
{
    private static ?self $i = null;
    private string $id;
    private float $t0;
    private array $spans = [];
    private array $stack = [];
    private array $hookStack = [];
    private array $hookTraces = [];
    private bool $done = false;
    private ?\ExcimerProfiler $profiler = null;

    private function __construct() {}

    public static function boot(): void
    {
        self::$i = new self();
        self::$i->id = bin2hex(random_bytes(8));
        self::$i->t0 = hrtime(true);

        // Excimer sampling profiler (10ms interval, ~2% overhead)
        if (class_exists(\ExcimerProfiler::class)) {
            self::$i->profiler = new \ExcimerProfiler();
            self::$i->profiler->setPeriod(0.01);
            self::$i->profiler->start();
        }

        // Hook argument tracing — capture every WP action/filter with args, timing, caller
        if (get_option('vlt_trace_hooks', false)) {
            add_action('all', [self::class, 'traceHookBefore'], -9999);
            add_action('all', [self::class, 'traceHookAfter'],  PHP_INT_MAX);
        }

        self::begin('request');
    }

    /** @internal */
    public static function traceHookBefore(): void
    {
        if (!self::$i) return;
        $hook = current_filter();
        $args = func_get_args();
        self::$i->hookStack[$hook] = [
            'ts'     => hrtime(true),
            'args'   => self::serializeArgs($args),
            'caller' => self::shortCaller(),
        ];
    }

    /** @internal */
    public static function traceHookAfter(): void
    {
        if (!self::$i) return;
        $hook = current_filter();
        if (!isset(self::$i->hookStack[$hook])) return;
        $entry = self::$i->hookStack[$hook];
        $ms    = round((hrtime(true) - $entry['ts']) / 1e6, 2);
        unset(self::$i->hookStack[$hook]);

        // Only record slow hooks (>1ms) or hooks with interesting args
        $threshold = (float) get_option('vlt_trace_hook_threshold_ms', 1.0);
        if ($ms < $threshold && empty($entry['args'])) return;

        self::$i->hookTraces[] = [
            'hook'   => $hook,
            'ms'     => $ms,
            'args'   => $entry['args'],
            'caller' => $entry['caller'],
        ];
    }

    private static function serializeArgs(array $args): array
    {
        $out = [];
        foreach (array_slice($args, 0, 3) as $arg) {
            if (is_scalar($arg)) {
                $out[] = substr((string) $arg, 0, 200);
            } elseif (is_array($arg)) {
                $out[] = '[array:' . count($arg) . '] ' . substr(json_encode(array_slice($arg, 0, 3, true)), 0, 200);
            } elseif (is_object($arg)) {
                $out[] = get_class($arg);
            } else {
                $out[] = gettype($arg);
            }
        }
        return $out;
    }

    private static function shortCaller(): string
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        foreach ($bt as $frame) {
            $file = $frame['file'] ?? '';
            // Skip internal WP hook machinery
            if (str_contains($file, 'class-wp-hook') || str_contains($file, 'plugin.php')) continue;
            $rel = str_replace(ABSPATH, '', $file);
            return $rel . ':' . ($frame['line'] ?? 0);
        }
        return '';
    }

    public static function instance(): ?self
    {
        return self::$i;
    }

    public static function begin(string $name, array $meta = []): void
    {
        if (!self::$i) {
            return;
        }
        self::$i->spans[] = [
            'n' => $name,
            's' => hrtime(true),
            'e' => 0,
            'd' => 0,
            'm' => $meta,
            'p' => self::$i->stack ? end(self::$i->stack) : null,
        ];
        self::$i->stack[] = count(self::$i->spans) - 1;
    }

    public static function end(): void
    {
        if (!self::$i) {
            return;
        }
        $idx = array_pop(self::$i->stack);
        if ($idx !== null && isset(self::$i->spans[$idx])) {
            self::$i->spans[$idx]['e'] = hrtime(true);
            self::$i->spans[$idx]['d'] = round((self::$i->spans[$idx]['e'] - self::$i->spans[$idx]['s']) / 1e6, 2);
        }
    }

    public static function measure(string $name, callable $fn, array $meta = []): mixed
    {
        self::begin($name, $meta);
        try {
            return $fn();
        } finally {
            self::end();
        }
    }

    public static function finish(): void
    {
        if (!self::$i || self::$i->done) {
            return;
        }
        self::$i->done = true;
        while (self::$i->stack) {
            self::end();
        }

        global $wpdb;
        $dq    = [];
        $db_ms = 0;
        $spanTimings = array_map(fn($s) => ['s' => $s['s'], 'e' => $s['e'] ?: hrtime(true)], self::$i->spans);

        foreach ($wpdb->queries ?? [] as $q) {
            $ms     = round((float) ($q[1] ?? 0) * 1000, 2);
            $db_ms += $ms;
            $sql    = $q[0] ?? '';
            $caller = $q[2] ?? '';
            // Parse caller into structured stack
            $stack  = array_map('trim', explode(',', $caller));
            // Find which span this query belongs to (by start time if available)
            $spanIdx = null;
            if (isset($q[3])) {
                // WP 5.9+ stores start time in $q[3]
                $qStart = (int) ($q[3] * 1e9); // convert to nanoseconds
                for ($si = count($spanTimings) - 1; $si >= 0; $si--) {
                    if ($qStart >= $spanTimings[$si]['s'] && $qStart <= $spanTimings[$si]['e']) {
                        $spanIdx = $si;
                        break;
                    }
                }
            }
            $dq[] = [
                'ms'     => $ms,
                'sql'    => strlen($sql) > 2000 ? substr($sql, 0, 2000) . '…' : $sql,
                'caller' => $caller,
                'stack'  => array_slice($stack, 0, 20),
                'span'   => $spanIdx,
            ];
        }

        $uid = get_current_user_id();
        $u_name = '';
        $u_email = '';
        $u_roles = [];
        if ($uid) {
            $u = get_userdata($uid);
            if ($u) {
                $u_name  = $u->display_name;
                $u_email = $u->user_email;
                $u_roles = $u->roles;
            }
        }

        // Request details
        $req_headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $req_headers[str_replace('_', '-', strtolower(substr($k, 5)))] = substr((string) $v, 0, 500);
            }
        }
        $req_body = '';
        if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PUT', 'PATCH'], true)) {
            $raw = file_get_contents('php://input') ?: '';
            $req_body = strlen($raw) > 2000 ? substr($raw, 0, 2000) . '…' : $raw;
        }
        $resp_headers = [];
        foreach (headers_list() as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2) {
                $resp_headers[trim($parts[0])] = trim(substr($parts[1], 0, 500));
            }
        }

        $trace = [
            'id'        => self::$i->id,
            'ts'        => gmdate('c'),
            'user'      => $uid,
            'user_name' => $u_name,
            'user_email'=> $u_email,
            'user_roles'=> $u_roles,
            'ip'        => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri'       => $_SERVER['REQUEST_URI'] ?? '',
            'status'    => http_response_code() ?: 200,
            'total_ms'  => round((hrtime(true) - self::$i->t0) / 1e6, 2),
            'mem'       => memory_get_peak_usage(true),
            'spans'     => array_map(fn($s) => ['n' => $s['n'], 'ms' => $s['d'], 'm' => $s['m'] ?: null, 'p' => $s['p']], self::$i->spans),
            'db'        => $dq,
            'db_n'      => count($dq),
            'db_ms'     => round($db_ms, 2),
            'hooks'     => array_slice(
                array_filter(self::$i->hookTraces, fn($h) => $h['ms'] >= (float) get_option('vlt_trace_hook_threshold_ms', 1.0)),
                0, 100
            ),
            'plugins'   => count(wp_get_active_and_valid_plugins()),
            'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'cf_ray'    => $_SERVER['HTTP_CF_RAY'] ?? '',
            'cf_co'     => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
            'tpl'       => get_template() ?: '',
            'req_headers'  => $req_headers,
            'req_body'     => $req_body,
            'req_get'      => array_map(fn($v) => substr((string) $v, 0, 500), $_GET),
            'req_post'     => array_map(fn($v) => is_string($v) ? substr($v, 0, 500) : $v, $_POST),
            'req_cookies'  => array_map(fn($v) => substr((string) $v, 0, 200), $_COOKIE),
            'resp_headers' => $resp_headers,
            'resp_code'    => http_response_code() ?: 200,
        ];

        // Collect profiler data
        if (self::$i->profiler) {
            self::$i->profiler->stop();
            $log = self::$i->profiler->getLog();
            $totalSamples = $log->getEventCount() ?: 1;
            $totalMs = $trace['total_ms'];
            $agg = $log->aggregateByFunction();
            $profile = [];
            foreach ($agg as $fn => $data) {
                $profile[] = [
                    'fn'       => $fn,
                    'file'     => $data['file'] ?? '',
                    'line'     => $data['line'] ?? 0,
                    'self_ms'  => round(($data['self'] / $totalSamples) * $totalMs, 2),
                    'total_ms' => round(($data['inclusive'] / $totalSamples) * $totalMs, 2),
                    'samples'  => $data['self'],
                ];
            }
            usort($profile, fn($a, $b) => $b['self_ms'] <=> $a['self_ms']);
            $trace['profile'] = array_slice($profile, 0, 100);
            $trace['profile_samples'] = $totalSamples;
        }

        try {
            $r = RedisFactory::create(0.2);
            if ($r) {
                $json = json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $r->lPush(TracerConfig::VLT_TR_KEY, $json);
                $r->lTrim(TracerConfig::VLT_TR_KEY, 0, TracerConfig::getMaxTraces() - 1);
                $r->expire(TracerConfig::VLT_TR_KEY, TracerConfig::VLT_TR_TTL);
                $r->close();
            }
        } catch (\Throwable $e) {
        }

        $dir = TracerConfig::getDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        @file_put_contents(
            $dir . '/trace-' . gmdate('Y-m-d') . '.json',
            json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
