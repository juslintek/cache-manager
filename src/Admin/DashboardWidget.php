<?php declare(strict_types=1);
namespace VLT\CacheManager\Admin;

/**
 * Unified Gratis dashboard widget showing stats from all active plugins.
 */
final class DashboardWidget
{
    public static function register(): void
    {
        add_action('wp_dashboard_setup', function () {
            wp_add_dashboard_widget('gratis_dashboard', '⚡ Gratis Suite', [__CLASS__, 'render']);
        });
    }

    public static function render(): void
    {
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px">';

        // Cache stats
        if (class_exists('VLT\CacheManager\Log\Logger')) {
            $logger = new \VLT\CacheManager\Log\Logger();
            $stats = $logger->getTodayStats();
            $total = $stats['hits'] + $stats['misses'];
            $ratio = $total > 0 ? round($stats['hits'] / $total * 100) : 0;
            self::card('Cache', "{$ratio}%", 'hit ratio');
        }

        // Backup count
        if (class_exists('Gratis\Backup\BackupEngine')) {
            $dir = WP_CONTENT_DIR . '/gratis-backup-manifests';
            $count = is_dir($dir) ? count(glob($dir . '/*.json')) : 0;
            self::card('Backups', (string) $count, 'stored');
        }

        // Analytics
        if (class_exists('Gratis\Analytics\Store')) {
            $stats = \Gratis\Analytics\Store::stats(gmdate('Y-m-d 00:00:00'), gmdate('Y-m-d 23:59:59'));
            self::card('Today', (string) $stats['pageviews'], 'pageviews');
        }

        // Forms
        $entries = wp_count_posts('gratis_form_entry');
        if ($entries && $entries->publish > 0) {
            self::card('Forms', (string) $entries->publish, 'entries');
        }

        // CRM contacts
        $contacts = wp_count_posts('gratis_contact');
        if ($contacts && $contacts->publish > 0) {
            self::card('CRM', (string) $contacts->publish, 'contacts');
        }

        // Commerce orders
        $orders = wp_count_posts('gratis_order');
        if ($orders && $orders->publish > 0) {
            self::card('Orders', (string) $orders->publish, 'total');
        }

        // Security
        $blocks = get_option('gratis_security_blocks', []);
        self::card('Security', (string) count($blocks), 'blocks');

        // Email
        if (class_exists('Gratis\Mail\Logger')) {
            $mailStats = \Gratis\Mail\Logger::getStats();
            self::card('Email', (string) $mailStats['sent'], 'sent');
        }

        echo '</div>';
        echo '<p style="margin-top:12px;font-size:12px;color:#666">Gratis Suite — ' . count(get_option('active_plugins', [])) . ' plugins active | <a href="' . admin_url('admin.php?page=gratis-fleet') . '">Fleet Dashboard →</a></p>';
    }

    private static function card(string $label, string $value, string $sub): void
    {
        echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center">';
        echo '<div style="font-size:1.5em;font-weight:700;color:#1e293b">' . esc_html($value) . '</div>';
        echo '<div style="font-size:11px;color:#64748b;margin-top:2px">' . esc_html($label) . ' ' . esc_html($sub) . '</div>';
        echo '</div>';
    }
}
