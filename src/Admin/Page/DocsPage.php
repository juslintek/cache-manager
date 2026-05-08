<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class DocsPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-docs'; }
    public function title(): string { return 'Docs'; }

    public function render(): void
    {
        echo '<div class="wrap"><h1>Cache Manager — Documentation & Tips</h1>';

        $sections = [

            'LSCache Setup (DirectAdmin + OpenLiteSpeed)' => [
                'icon' => '🚀',
                'content' => <<<'HTML'
<h3>1. Server-level Cache Root</h3>
<p>Add to <code>/etc/openlitespeed/httpd-includes.conf</code>:</p>
<pre><code>&lt;IfModule Litespeed&gt;
CacheRoot /home/lscache/
&lt;/IfModule&gt;</code></pre>

<h3>2. Per-VHost Cache Root (all domains)</h3>
<p>Create <code>/usr/local/directadmin/data/templates/custom/cust_httpd.CUSTOM.2.pre</code>:</p>
<pre><code>&lt;IfModule Litespeed&gt;
  CacheRoot lscache
&lt;/IfModule&gt;</code></pre>
<p>Apply to all vhosts:</p>
<pre><code>cd /usr/local/directadmin/custombuild && ./build rewrite_confs</code></pre>

<h3>3. Enable Cache in httpd-lscache.conf</h3>
<p>Edit <code>/usr/local/directadmin/custombuild/configure/openlitespeed/conf/httpd-lscache.conf</code>:</p>
<pre><code>enableCache         1</code></pre>
<p>Then rebuild: <code>./build rewrite_confs</code></p>

<h3>4. Enable Cache Lookup in .htaccess</h3>
<p>Add to your domain's <code>public_html/.htaccess</code>:</p>
<pre><code>&lt;IfModule LiteSpeed&gt;
  CacheLookup public on
&lt;/IfModule&gt;</code></pre>

<h3>5. Restart LiteSpeed</h3>
<pre><code>service lsws restart</code></pre>

<h3>Verify</h3>
<p>Check response headers — should see <code>x-litespeed-cache: hit</code> on second request:</p>
<pre><code>curl -I https://yourdomain.com/</code></pre>
HTML,
            ],

            'Redis Object Cache (DirectAdmin)' => [
                'icon' => '🔴',
                'content' => <<<'HTML'
<h3>Enable Redis for a user</h3>
<p>DirectAdmin → Extra Features → Redis → Enable</p>
<p>Socket path: <code>/home/&lt;user&gt;/.redis/redis.sock</code></p>

<h3>Configure WordPress to use Redis socket</h3>
<p>Add to <code>wp-config.php</code> before <code>wp-settings.php</code>:</p>
<pre><code>define( 'WP_REDIS_SCHEME', 'unix' );
define( 'WP_REDIS_PATH', '/home/&lt;user&gt;/.redis/redis.sock' );</code></pre>

<h3>Install object-cache.php drop-in</h3>
<p>Use the plugin's Settings page → Actions → Install object-cache.php</p>
<p>Or via WP-CLI: <code>wp redis enable</code></p>

<h3>Verify</h3>
<pre><code>redis-cli -s /home/&lt;user&gt;/.redis/redis.sock ping
# Should return: PONG</code></pre>
HTML,
            ],

            'Performance Optimizations' => [
                'icon' => '⚡',
                'content' => <<<'HTML'
<h3>Native Lazy Loading (WordPress 5.5+)</h3>
<p>WordPress automatically adds <code>loading="lazy"</code> to images. Ensure it's not disabled:</p>
<pre><code>// In functions.php — remove this if present:
add_filter( 'wp_lazy_loading_enabled', '__return_false' );</code></pre>

<h3>HTTP/3 / QUIC (LiteSpeed)</h3>
<p>LiteSpeed supports HTTP/3 natively. Enable in LiteSpeed admin → Listeners → QUIC.</p>
<p>Add to <code>.htaccess</code>:</p>
<pre><code>Header always set Alt-Svc 'h3=":443"; ma=86400'</code></pre>

<h3>Browser Cache Headers</h3>
<p>Add to <code>.htaccess</code>:</p>
<pre><code>&lt;IfModule mod_expires.c&gt;
  ExpiresActive On
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
&lt;/IfModule&gt;</code></pre>

<h3>Preload Critical Resources</h3>
<p>Add to <code>functions.php</code>:</p>
<pre><code>add_action( 'wp_head', function() {
    echo '&lt;link rel="preload" href="' . get_template_directory_uri() . '/style.css" as="style"&gt;';
    echo '&lt;link rel="preconnect" href="https://fonts.googleapis.com"&gt;';
}, 1 );</code></pre>

<h3>DNS Prefetch</h3>
<pre><code>add_filter( 'wp_resource_hints', function( $hints, $relation_type ) {
    if ( 'dns-prefetch' === $relation_type ) {
        $hints[] = 'https://fonts.googleapis.com';
        $hints[] = 'https://cdn.yourdomain.com';
    }
    return $hints;
}, 10, 2 );</code></pre>

<h3>Disable Emoji Scripts (saves ~20KB)</h3>
<pre><code>remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );</code></pre>

<h3>Defer Non-Critical JS</h3>
<pre><code>add_filter( 'script_loader_tag', function( $tag, $handle ) {
    $defer = ['your-script-handle'];
    if ( in_array( $handle, $defer ) ) {
        return str_replace( ' src', ' defer src', $tag );
    }
    return $tag;
}, 10, 2 );</code></pre>
HTML,
            ],

            'OPcache Tuning' => [
                'icon' => '⚙',
                'content' => <<<'HTML'
<h3>Recommended php.ini settings</h3>
<pre><code>opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
opcache.enable_cli=1
opcache.jit=1255
opcache.jit_buffer_size=128M</code></pre>
<p>Set via DirectAdmin → PHP Settings → php.ini or <code>/usr/local/php84/lib/php.ini</code></p>

<h3>Clear OPcache after deploy</h3>
<pre><code>opcache_reset();</code></pre>
HTML,
            ],

            'CloudLinux LVE & AccelerateWP' => [
                'icon' => '☁',
                'content' => <<<'HTML'
<h3>Check your LVE limits</h3>
<pre><code>lvectl list | grep &lt;uid&gt;</code></pre>

<h3>AccelerateWP (admin only)</h3>
<p>CloudLinux AccelerateWP provides automated WordPress optimization including object cache, CDN, and critical CSS.</p>
<p>Enable for a user (root/admin):</p>
<pre><code># Check if available
ls /usr/share/cloudlinux/wpos/

# Enable via DA: Extra Features → AccelerateWP</code></pre>

<h3>PHP Selector — set optimal version</h3>
<pre><code>cloudlinux-selector set --interpreter php \
  --user &lt;username&gt; \
  --current-version 8.4</code></pre>

<h3>Enable Redis for user (admin)</h3>
<pre><code># Via DirectAdmin API
curl -u admin:password "https://server:2222/CMD_API_REDIS?action=enable&user=username"</code></pre>
HTML,
            ],

            'Troubleshooting' => [
                'icon' => '🔧',
                'content' => <<<'HTML'
<h3>LSCache not caching</h3>
<ul>
    <li>Check <code>x-litespeed-cache</code> header — should be <code>hit</code> or <code>miss</code></li>
    <li>Ensure <code>CacheLookup public on</code> is in <code>.htaccess</code></li>
    <li>Ensure <code>enableCache 1</code> in <code>httpd-lscache.conf</code></li>
    <li>Logged-in users are never cached (by design)</li>
    <li>Pages with <code>Set-Cookie</code> headers are not cached</li>
</ul>

<h3>Redis not connecting</h3>
<ul>
    <li>Check socket exists: <code>ls -la /home/&lt;user&gt;/.redis/redis.sock</code></li>
    <li>Verify PHP extension: <code>php -m | grep redis</code></li>
    <li>Check object-cache.php uses correct socket path</li>
    <li>Use Settings → Redis → Auto-detect to find the correct connection</li>
</ul>

<h3>High memory usage</h3>
<ul>
    <li>Increase <code>memory_limit</code> in <code>.user.ini</code> or <code>wp-config.php</code></li>
    <li>Check OPcache memory: Settings → OPcache</li>
    <li>Use Performance → GC Analyzer to detect memory leaks</li>
</ul>

<h3>Purge not working</h3>
<ul>
    <li>Use Dashboard → Purge buttons (SSE-based, shows progress)</li>
    <li>Avoid direct <code>?action=vlt_purge</code> URL — it's deprecated</li>
    <li>Check Redis protected keys aren't being deleted</li>
</ul>
HTML,
            ],

        ];

        foreach ($sections as $title => $section) {
            echo '<div style="margin-bottom:24px;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden">';
            echo '<div style="background:#f6f7f7;padding:12px 16px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center;gap:8px">';
            echo '<span style="font-size:20px">' . $section['icon'] . '</span>';
            echo '<h2 style="margin:0;font-size:15px">' . esc_html($title) . '</h2>';
            echo '</div>';
            echo '<div style="padding:16px 20px;font-size:13px;line-height:1.7">';
            echo $section['content']; // trusted internal content
            echo '</div></div>';
        }

        echo '</div>';
    }
}
