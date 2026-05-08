<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\CloudLinuxDetector;

final class CloudLinuxPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-cloudlinux'; }
    public function title(): string { return 'CloudLinux'; }

    public function render(): void
    {
        echo '<div class="wrap"><h1>Podėlio Valdymas — CloudLinux</h1>';

        $ver  = CloudLinuxDetector::version();
        $lve  = CloudLinuxDetector::lveInfo();
        $recs = CloudLinuxDetector::recommendations();

        // Status table
        echo '<table class="widefat tw-fixed striped tw-max-w-4xl tw-my-5"><thead><tr><th style="width:220px">Funkcija</th><th style="width:200px">Būsena</th><th>Nauda / Kaip įjungti</th></tr></thead><tbody>';

        $rows = [
            ['CloudLinux versija',    $ver ?: '—',                                                    '—'],
            ['LVE CPU limitas',       ($lve['cpu'] ?? '—'),                                           'Jūsų procesoriaus limitas. 100% = neribota.'],
            ['LVE Atminties limitas', ($lve['pmem'] ?? '—'),                                          'Fizinės atminties limitas jūsų paskyroje.'],
            ['LVE Entry Processes',   ($lve['ep'] ?? '—'),                                            'Maks. vienu metu vykdomų PHP procesų skaičius.'],
            ['CageFS',                CloudLinuxDetector::cageFsEnabled() ? '✅ Aktyvus' : '❌ Neaktyvus', 'Failų sistemos izoliacija — saugumo funkcija.'],
            ['MySQL Governor',        CloudLinuxDetector::mysqlGovernorInstalled() ? '✅ Įdiegtas' : '❌ Neįdiegtas', 'Riboja lėtas DB užklausas, apsaugo nuo perkrovos.'],
            ['PHP Selector',          CloudLinuxDetector::phpSelectorEnabled() ? '✅ Aktyvus' : '❌ Neaktyvus', 'Pasirinkite PHP versiją kiekvienam domenui.'],
            ['AccelerateWP',          CloudLinuxDetector::accelerateWpInstalled() ? '✅ Įdiegtas' : '❌ Neįdiegtas', 'Automatinis WordPress optimizavimas (object cache, CDN, critical CSS).'],
            ['Redis (jūsų paskyra)',  CloudLinuxDetector::redisEnabled() ? '✅ Aktyvus' : '❌ Neaktyvus', 'Redis object cache jūsų domenui.'],
        ];

        foreach ($rows as [$label, $status, $note]) {
            $color = str_starts_with($status, '✅') ? 'text-green-600' : (str_starts_with($status, '❌') ? 'text-red-600' : '');
            echo '<tr><td><strong>' . esc_html($label) . '</strong></td>';
            echo '<td class="' . $color . '">' . esc_html($status) . '</td>';
            echo '<td class="tw-text-gray-500 tw-text-xs">' . esc_html($note) . '</td></tr>';
        }
        echo '</tbody></table>';

        // Recommendations
        echo '<h2>Optimizavimo rekomendacijos</h2>';
        echo '<div class="tw-max-w-4xl tw-space-y-3">';
        foreach ($recs as $rec) {
            $isEnabled = in_array($rec['status'], ['enabled', 'installed', 'available'], true);
            $border    = $isEnabled ? 'border-green-200 bg-green-50' : 'border-yellow-200 bg-yellow-50';
            $icon      = $isEnabled ? '✅' : '⚠';
            echo '<div class="tw-border tw-rounded tw-p-4' . $border . '">';
            echo '<div class="tw-flex tw-items-start tw-gap-3">';
            echo '<span class="tw-text-lg mt-0.5">' . $icon . '</span>';
            echo '<div class="tw-flex-1">';
            echo '<div class="tw-font-semibold tw-text-sm">' . esc_html($rec['title']) . '</div>';
            echo '<div class="tw-text-xs tw-text-gray-600 mt-0.5">' . esc_html($rec['benefit']) . '</div>';
            if (!$isEnabled) {
                if ($rec['fix_da'] ?? false) {
                    echo '<div class="tw-mt-2 tw-text-xs"><strong>DirectAdmin:</strong> ' . esc_html($rec['fix_da']) . '</div>';
                }
                if ($rec['fix_cmd'] ?? false) {
                    echo '<div class="tw-mt-1 tw-text-xs"><strong>SSH:</strong> <code class="tw-bg-gray-100 tw-px-1 tw-rounded">' . esc_html($rec['fix_cmd']) . '</code></div>';
                }
            }
            echo '</div></div></div>';
        }
        echo '</div>';

        // PHP Selector current version
        if (CloudLinuxDetector::phpSelectorEnabled()) {
            $user = preg_match('#^/home/([^/]+)/#', ABSPATH, $m) ? $m[1] : '';
            if ($user) {
                $json = @shell_exec('cloudlinux-selector get --json --interpreter php --user ' . escapeshellarg($user) . ' --get-current-version 2>/dev/null');
                if ($json) {
                    $data = json_decode($json, true);
                    $phpVer = $data['result'] ?? ($data['version'] ?? '');
                    if ($phpVer) {
                        echo '<p class="tw-mt-4 tw-text-sm"><strong>Dabartinė PHP versija</strong> (<code>' . esc_html($user) . '</code>): <code class="tw-bg-gray-100 tw-px-1 tw-rounded">' . esc_html($phpVer) . '</code></p>';
                    }
                }
            }
        }

        echo '</div>';
    }
}
