<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * Redirect manager (301/302) — what the Redirection plugin does. Free in Gratis.
 * Stores redirects in wp_options, processes them early in template_redirect.
 */
final class RedirectManager
{
    private const OPTION = 'gratis_redirects';

    public static function register(): void
    {
        add_action('template_redirect', [__CLASS__, 'process'], -9999);
    }

    public static function process(): void
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $redirects = self::all();

        foreach ($redirects as $rule) {
            if (self::matches($path, $rule['from'])) {
                $code = (int) ($rule['code'] ?? 301);
                wp_redirect($rule['to'], $code);
                exit;
            }
        }
    }

    public static function add(string $from, string $to, int $code = 301): void
    {
        $redirects = self::all();
        $redirects[] = ['from' => $from, 'to' => $to, 'code' => $code, 'created' => gmdate('c')];
        update_option(self::OPTION, $redirects);
    }

    public static function remove(int $index): bool
    {
        $redirects = self::all();
        if (!isset($redirects[$index])) return false;
        array_splice($redirects, $index, 1);
        update_option(self::OPTION, $redirects);
        return true;
    }

    public static function all(): array
    {
        return get_option(self::OPTION, []);
    }

    private static function matches(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) return true;
        // Regex match (patterns starting with ~)
        if (str_starts_with($pattern, '~')) {
            return (bool) preg_match(substr($pattern, 1), $path);
        }
        // Wildcard match
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace(['\*'], ['.*'], preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $path);
        }
        return false;
    }
}
