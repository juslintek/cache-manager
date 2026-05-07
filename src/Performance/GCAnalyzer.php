<?php

declare(strict_types=1);

namespace VLT\CacheManager\Performance;

/**
 * PHP Garbage Collection and memory performance analyzer.
 *
 * Detects circular references, GC pressure, memory leaks in WP hooks,
 * and suggests/applies fixes.
 *
 * References:
 * - https://www.php.net/manual/en/features.gc.refcounting-basics.php
 * - https://www.php.net/manual/en/features.gc.collecting-cycles.php
 * - https://www.php.net/manual/en/features.gc.performance-considerations.php
 */
final class GCAnalyzer
{
    /** @return array{issues:array, stats:array, recommendations:array} */
    public static function analyze(): array
    {
        $issues  = [];
        $stats   = self::gcStats();
        $recs    = [];

        // 1. GC disabled
        if (!gc_enabled()) {
            $issues[] = [
                'severity' => 'critical',
                'title'    => 'PHP GC išjungtas',
                'detail'   => 'gc_enable() nebuvo iškviestas. Ciklinės nuorodos nebus išvalomos.',
                'fix'      => 'auto',
                'fix_code' => 'gc_enable();',
            ];
        }

        // 2. High cycle count — GC running too often
        $cycles = $stats['runs'] ?? 0;
        if ($cycles > 1000) {
            $issues[] = [
                'severity' => 'warning',
                'title'    => 'Didelis GC ciklų skaičius (' . $cycles . ')',
                'detail'   => 'GC buvo paleistas ' . $cycles . ' kartų šio užklausos metu. Tai rodo daug ciklinių nuorodų.',
                'fix'      => 'investigate',
            ];
        }

        // 3. Memory usage near limit
        $memUsed  = memory_get_usage(true);
        $memLimit = self::parseMemoryLimit(ini_get('memory_limit'));
        $memPct   = $memLimit > 0 ? ($memUsed / $memLimit * 100) : 0;
        if ($memPct > 70) {
            $issues[] = [
                'severity' => 'warning',
                'title'    => 'Didelis atminties naudojimas (' . round($memPct) . '%)',
                'detail'   => 'Naudojama ' . self::formatBytes($memUsed) . ' iš ' . self::formatBytes($memLimit) . '.',
                'fix'      => 'suggest',
                'suggestion' => 'Padidinkite memory_limit arba optimizuokite įskiepius.',
            ];
        }

        // 4. WP hooks with many callbacks (potential memory hogs)
        global $wp_filter;
        $heavyHooks = [];
        if (is_array($wp_filter)) {
            foreach ($wp_filter as $hook => $callbacks) {
                $count = 0;
                foreach ($callbacks as $priority => $cbs) {
                    $count += count($cbs);
                }
                if ($count > 50) {
                    $heavyHooks[$hook] = $count;
                }
            }
        }
        if ($heavyHooks) {
            arsort($heavyHooks);
            $top = array_slice($heavyHooks, 0, 5, true);
            $issues[] = [
                'severity' => 'info',
                'title'    => 'Sunkūs WP hooks (' . count($heavyHooks) . ' hooks su >50 callbacks)',
                'detail'   => 'Top hooks: ' . implode(', ', array_map(fn($h, $c) => "$h ($c)", array_keys($top), $top)),
                'fix'      => 'none',
            ];
        }

        // 5. GC collect cycles manually — check if it frees significant memory
        $before = memory_get_usage();
        $freed  = gc_collect_cycles();
        $after  = memory_get_usage();
        $freedBytes = $before - $after;
        if ($freed > 100 || $freedBytes > 1048576) {
            $issues[] = [
                'severity' => 'warning',
                'title'    => 'Ciklinės nuorodos atmintyje',
                'detail'   => "gc_collect_cycles() išvalė $freed ciklų, atlaisvino " . self::formatBytes(max(0, $freedBytes)) . '.',
                'fix'      => 'auto',
                'fix_code' => 'gc_collect_cycles();',
            ];
        }

        // 6. opcache status
        if (function_exists('opcache_get_status')) {
            $oc = opcache_get_status(false);
            if ($oc && isset($oc['memory_usage'])) {
                $ocUsed = $oc['memory_usage']['used_memory'];
                $ocFree = $oc['memory_usage']['free_memory'];
                $ocPct  = $ocUsed / ($ocUsed + $ocFree) * 100;
                if ($ocPct > 90) {
                    $issues[] = [
                        'severity' => 'warning',
                        'title'    => 'OPcache beveik pilnas (' . round($ocPct) . '%)',
                        'detail'   => 'OPcache naudoja ' . self::formatBytes($ocUsed) . '. Kai pilnas, PHP kompiliuoja failus iš naujo.',
                        'fix'      => 'suggest',
                        'suggestion' => 'Padidinkite opcache.memory_consumption php.ini.',
                    ];
                }
            }
        }

        // Recommendations
        if (empty($issues)) {
            $recs[] = '✅ Nėra kritinių GC/atminties problemų.';
        } else {
            $critical = array_filter($issues, fn($i) => $i['severity'] === 'critical');
            $warnings = array_filter($issues, fn($i) => $i['severity'] === 'warning');
            if ($critical) {
                $recs[] = count($critical) . ' kritinė problema — rekomenduojama nedelsiant taisyti.';
            }
            if ($warnings) {
                $recs[] = count($warnings) . ' įspėjimas — stebėkite atminties naudojimą.';
            }
        }

        return compact('issues', 'stats', 'recommendations') + ['recommendations' => $recs];
    }

    public static function gcStats(): array
    {
        $status = gc_status();
        return [
            'enabled'       => $status['runs'] >= 0,
            'runs'          => $status['runs'],
            'collected'     => $status['collected'],
            'threshold'     => $status['threshold'],
            'roots'         => $status['roots'],
            'memory_used'   => memory_get_usage(true),
            'memory_peak'   => memory_get_peak_usage(true),
            'memory_limit'  => ini_get('memory_limit'),
        ];
    }

    /**
     * Apply auto-fixable issues (safe, non-destructive).
     * Only calls PHP functions — never modifies files.
     */
    public static function applyAutoFixes(): array
    {
        $applied = [];
        if (!gc_enabled()) {
            gc_enable();
            $applied[] = 'gc_enable() — GC įjungtas';
        }
        $freed = gc_collect_cycles();
        if ($freed > 0) {
            $applied[] = "gc_collect_cycles() — išvalyta $freed ciklų";
        }
        return $applied;
    }

    private static function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0;
        }
        $unit  = strtolower(substr($limit, -1));
        $value = (int) $limit;
        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
