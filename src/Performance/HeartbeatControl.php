<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * Heartbeat API control — reduces admin-ajax.php load.
 * What Perfmatters ($25/yr) and Heartbeat Control plugins do. Free in Gratis.
 */
final class HeartbeatControl
{
    public static function register(): void
    {
        if (!get_option('vlt_heartbeat_control', true)) return;

        // Disable on frontend entirely
        add_action('init', function () {
            if (!is_admin() && !wp_doing_ajax()) {
                wp_deregister_script('heartbeat');
            }
        }, 1);

        // Slow down in admin (60s instead of 15s)
        add_filter('heartbeat_settings', function ($settings) {
            $settings['interval'] = (int) get_option('vlt_heartbeat_interval', 60);
            return $settings;
        });

        // Keep normal in post editor (autosave needs it)
        add_filter('heartbeat_settings', function ($settings) {
            global $pagenow;
            if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
                $settings['interval'] = 30;
            }
            return $settings;
        }, 20);
    }
}
