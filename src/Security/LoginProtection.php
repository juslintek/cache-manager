<?php declare(strict_types=1);
namespace VLT\CacheManager\Security;

/**
 * Login security: rate limiting + lockout.
 * What Wordfence/Limit Login Attempts charge for. Free in Gratis.
 */
final class LoginProtection
{
    private const OPTION_PREFIX = 'gratis_login_attempts_';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public static function register(): void
    {
        if (!get_option('vlt_login_protection', true)) return;
        add_filter('authenticate', [__CLASS__, 'checkLockout'], 30, 3);
        add_action('wp_login_failed', [__CLASS__, 'recordFailure']);
        add_action('wp_login', [__CLASS__, 'clearAttempts'], 10, 2);
    }

    public static function checkLockout($user, string $username, string $password)
    {
        if (empty($username)) return $user;
        $ip = self::getIP();
        $key = self::OPTION_PREFIX . md5($ip);
        $data = get_transient($key);
        if ($data && ($data['count'] ?? 0) >= self::MAX_ATTEMPTS) {
            $remaining = self::LOCKOUT_MINUTES - (int)((time() - ($data['last'] ?? 0)) / 60);
            return new \WP_Error('gratis_locked', sprintf('Too many failed attempts. Try again in %d minutes.', max(1, $remaining)));
        }
        return $user;
    }

    public static function recordFailure(string $username): void
    {
        $ip = self::getIP();
        $key = self::OPTION_PREFIX . md5($ip);
        $data = get_transient($key) ?: ['count' => 0, 'last' => 0];
        $data['count']++;
        $data['last'] = time();
        set_transient($key, $data, self::LOCKOUT_MINUTES * 60);
    }

    public static function clearAttempts(string $username): void
    {
        delete_transient(self::OPTION_PREFIX . md5(self::getIP()));
    }

    private static function getIP(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
