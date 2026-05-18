<?php declare(strict_types=1);
namespace VLT\CacheManager\Diagnostics;

/** Detects available PHP extensions and backends at runtime. */
final class CapabilityDetector
{
    public static function detect(): array
    {
        return [
            'ext-json'       => true, // Always in PHP 8+
            'ext-sqlite3'    => extension_loaded('sqlite3'),
            'ext-dba'        => extension_loaded('dba'),
            'dba-lmdb'       => extension_loaded('dba') && in_array('lmdb', dba_handlers(), true),
            'ext-redis'      => extension_loaded('redis'),
            'ext-memcached'  => extension_loaded('memcached'),
            'ext-igbinary'   => extension_loaded('igbinary'),
            'ext-msgpack'    => extension_loaded('msgpack'),
            'ext-inotify'    => extension_loaded('inotify'),
            'ext-ffi'        => extension_loaded('ffi'),
            'ext-apcu'       => extension_loaded('apcu') && apcu_enabled(),
            'ext-imagick'    => extension_loaded('imagick'),
            'ext-simdjson'   => extension_loaded('simdjson'),
            'simdjson_plus'  => class_exists(\Simdjson\SimdJsonPlus::class),
            'fastjson'       => function_exists('fastjson_decode'),
        ];
    }

    public static function bestVolatileBackend(): string
    {
        $caps = self::detect();
        if ($caps['ext-redis']) return 'redis';
        if ($caps['ext-memcached']) return 'memcached';
        if ($caps['ext-apcu']) return 'apcu';
        return 'array';
    }

    public static function bestPersistentStore(): string
    {
        $caps = self::detect();
        if ($caps['ext-sqlite3']) return 'sqlite';
        if ($caps['dba-lmdb']) return 'lmdb';
        return 'file';
    }

    public static function bestSerializer(): string
    {
        $caps = self::detect();
        if ($caps['ext-igbinary']) return 'igbinary';
        if ($caps['ext-msgpack']) return 'msgpack';
        return 'php';
    }
}
