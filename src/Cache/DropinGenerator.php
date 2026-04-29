<?php

declare(strict_types=1);

namespace VLT\CacheManager\Cache;

final class DropinGenerator
{
    public function generate(): string
    {
        return <<<'DROPIN'
<?php

declare(strict_types=1);
/**
 * Plugin Name: VLT Object Cache
 * Description: Redis objektų talpykla su PhpRedis.
 */
defined( 'ABSPATH' ) || exit;

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new VLT_WP_Object_Cache();
}
function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}
function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $group, $data, $expire );
}
function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $group, $data, $expire );
}
function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $group, $data, $expire );
}
function wp_cache_delete( $key, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group );
}
function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush();
}
function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}
function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}
function wp_cache_close() {
	return $GLOBALS['wp_object_cache']->close();
}
function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}
function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}
function wp_cache_switch_to_blog( $blog_id ) {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
}
function wp_cache_supports( $feature ) {
	return in_array( $feature, [ 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ], true );
}
function wp_cache_get_multiple( $keys, $group = 'default', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}
function wp_cache_set_multiple( $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, $expire );
}
function wp_cache_delete_multiple( $keys, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
}
function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime();
}
function wp_cache_flush_group( $group ) {
	return $GLOBALS['wp_object_cache']->flush_group( $group );
}

class VLT_WP_Object_Cache {
	private $redis;
	private $connected = false;
	private $cache = [];
	private $global_groups = [];
	private $non_persistent = [ 'comment' => true, 'counts' => true, 'plugins' => true, 'themes' => true ];
	private $blog_prefix = '';
	public $cache_hits = 0;
	public $cache_misses = 0;
	private $debug = false;

	public function __construct() {
		$this->blog_prefix = is_multisite() ? get_current_blog_id() . ':' : '';
		$this->debug = isset( $_COOKIE['vlt_debug_cache'] );
		try {
			$this->redis = new Redis();
			$this->connected = $this->redis->connect( '127.0.0.1', 6379, 1.0 );
			if ( $this->connected ) {
				$this->redis->setOption( Redis::OPT_PREFIX, 'vlt_' );
				$this->redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
			}
		} catch ( Exception $e ) {
			$this->connected = false;
		}
	}

	private function key( $key, $group ) {
		$prefix = isset( $this->global_groups[ $group ] ) ? '' : $this->blog_prefix;
		return "$group:$prefix$key";
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( $this->debug ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}
		$k = $this->key( $key, $group );
		if ( ! $force && isset( $this->cache[ $k ] ) ) {
			$found = true;
			$this->cache_hits++;
			return is_object( $this->cache[ $k ] ) ? clone $this->cache[ $k ] : $this->cache[ $k ];
		}
		if ( isset( $this->non_persistent[ $group ] ) || ! $this->connected ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}
		$val = $this->redis->get( $k );
		if ( $val === false ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}
		$found = true;
		$this->cache_hits++;
		$this->cache[ $k ] = $val;
		return is_object( $val ) ? clone $val : $val;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$out = [];
		foreach ( $keys as $key ) {
			$out[ $key ] = $this->get( $key, $group, $force );
		}
		return $out;
	}

	public function set( $key, $group = 'default', $data = '', $expire = 0 ) {
		$k = $this->key( $key, $group );
		$this->cache[ $k ] = $data;
		if ( isset( $this->non_persistent[ $group ] ) || ! $this->connected ) {
			return true;
		}
		return $expire > 0 ? $this->redis->setex( $k, $expire, $data ) : $this->redis->set( $k, $data );
	}

	public function set_multiple( $data, $group = 'default', $expire = 0 ) {
		$out = [];
		foreach ( $data as $key => $val ) {
			$out[ $key ] = $this->set( $key, $group, $val, $expire );
		}
		return $out;
	}

	public function add( $key, $group = 'default', $data = '', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( isset( $this->cache[ $k ] ) ) {
			return false;
		}
		if ( $this->connected && ! isset( $this->non_persistent[ $group ] ) ) {
			if ( $this->redis->exists( $k ) ) {
				return false;
			}
		}
		return $this->set( $key, $group, $data, $expire );
	}

	public function replace( $key, $group = 'default', $data = '', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( ! isset( $this->cache[ $k ] ) ) {
			if ( ! $this->connected || isset( $this->non_persistent[ $group ] ) || ! $this->redis->exists( $k ) ) {
				return false;
			}
		}
		return $this->set( $key, $group, $data, $expire );
	}

	public function delete( $key, $group = 'default' ) {
		$k = $this->key( $key, $group );
		unset( $this->cache[ $k ] );
		if ( isset( $this->non_persistent[ $group ] ) || ! $this->connected ) {
			return true;
		}
		return (bool) $this->redis->del( $k );
	}

	public function delete_multiple( $keys, $group = 'default' ) {
		$out = [];
		foreach ( $keys as $key ) {
			$out[ $key ] = $this->delete( $key, $group );
		}
		return $out;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group );
		if ( $this->connected && ! isset( $this->non_persistent[ $group ] ) ) {
			$val = $this->redis->incrBy( $k, $offset );
			$this->cache[ $k ] = $val;
			return $val;
		}
		$val = isset( $this->cache[ $k ] ) ? (int) $this->cache[ $k ] + $offset : $offset;
		if ( $val < 0 ) $val = 0;
		$this->cache[ $k ] = $val;
		return $val;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group );
		if ( $this->connected && ! isset( $this->non_persistent[ $group ] ) ) {
			$val = $this->redis->decrBy( $k, $offset );
			if ( $val < 0 ) {
				$val = 0;
				$this->redis->set( $k, $val );
			}
			$this->cache[ $k ] = $val;
			return $val;
		}
		$val = isset( $this->cache[ $k ] ) ? (int) $this->cache[ $k ] - $offset : 0 - $offset;
		if ( $val < 0 ) $val = 0;
		$this->cache[ $k ] = $val;
		return $val;
	}

	public function flush() {
		$this->cache = [];
		if ( $this->connected ) {
			return $this->redis->flushDb();
		}
		return true;
	}

	public function flush_runtime() {
		$this->cache = [];
		return true;
	}

	public function flush_group( $group ) {
		foreach ( array_keys( $this->cache ) as $k ) {
			if ( str_starts_with( $k, "$group:" ) ) {
				unset( $this->cache[ $k ] );
			}
		}
		if ( $this->connected ) {
			$keys = $this->redis->keys( "$group:*" );
			if ( $keys ) {
				$this->redis->del( $keys );
			}
		}
		return true;
	}

	public function close() {
		if ( $this->connected ) {
			$this->redis->close();
			$this->connected = false;
		}
		return true;
	}

	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->global_groups[ $g ] = true;
		}
	}

	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->non_persistent[ $g ] = true;
		}
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = is_multisite() ? $blog_id . ':' : '';
	}

	public function stats() {
		echo "<p>Hits: {$this->cache_hits}, Misses: {$this->cache_misses}</p>";
	}

	public function is_connected() {
		return $this->connected;
	}

	public function redis_instance() {
		return $this->connected ? $this->redis : null;
	}
}
DROPIN;
    }
}
