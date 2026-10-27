<?php
/**
 * Diagnostics collectors.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects bounded, technical, read-only diagnostic modules.
 */
final class Diagnostics_Collector {
	const LOG_TAIL_BYTES = 65536;

	/**
	 * Return a lightweight pre-maintenance fingerprint.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,mixed>
	 */
	public static function fingerprint( array $context = array() ) {
		self::load_plugin_functions();

		global $wp_version;
		$theme          = wp_get_theme();
		$plugins        = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active_plugins = Diagnostics_Capabilities::active_plugin_files();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );

		$active = array();
		foreach ( $active_plugins as $file ) {
			$data = isset( $plugins[ $file ] ) ? $plugins[ $file ] : array();
			$active[] = array(
				'file'    => sanitize_text_field( $file ),
				'name'    => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : basename( $file ) ),
				'version' => sanitize_text_field( isset( $data['Version'] ) ? $data['Version'] : '' ),
			);
		}

		return Diagnostics_Sanitizer::sanitize(
			array(
				'generated_at'       => time(),
				'source'             => sanitize_key( (string) ( isset( $context['source'] ) ? $context['source'] : '' ) ),
				'maintenance_run_id' => sanitize_text_field( (string) ( isset( $context['maintenance_run_id'] ) ? $context['maintenance_run_id'] : '' ) ),
				'wordpress_version'  => isset( $wp_version ) ? (string) $wp_version : get_bloginfo( 'version' ),
				'php_version'        => PHP_VERSION,
				'theme'              => array(
					'stylesheet' => $theme->get_stylesheet(),
					'name'       => $theme->get( 'Name' ),
					'version'    => $theme->get( 'Version' ),
					'template'   => $theme->get_template(),
				),
				'active_plugins'     => $active,
				'network_plugins'    => is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array(),
				'builders'           => Diagnostics_Capabilities::detect_light()['builders'],
				'cache'              => Diagnostics_Capabilities::detect_light()['cache'],
				'updates_available'  => array(
					'plugins' => self::plugin_update_summary( $plugins, $plugin_updates ),
					'themes'  => self::theme_update_summary( $theme_updates ),
				),
				'update_lock'        => class_exists( __NAMESPACE__ . '\\Actions_Controller' ) ? Actions_Controller::current_update_lock_status() : array( 'locked' => false ),
			)
		);
	}

	/**
	 * Collect one module.
	 *
	 * @param string              $module     Module key.
	 * @param array<string,mixed> $parameters Parameters.
	 * @return array<string,mixed>
	 */
	public static function collect_module( $module, array $parameters = array() ) {
		$module       = sanitize_key( (string) $module );
		$start        = microtime( true );
		$memory_start = function_exists( 'memory_get_usage' ) ? memory_get_usage() : 0;
		$warnings     = array();
		$partial      = false;

		try {
			switch ( $module ) {
				case 'environment':
					$data = self::environment();
					break;
				case 'extensions':
					$data = self::extensions();
					break;
				case 'capabilities':
					$data = Diagnostics_Capabilities::detect_light();
					break;
				case 'cache':
					$data = self::cache();
					break;
				case 'database':
					$data = self::database( $warnings, $partial );
					break;
				case 'cron':
					$data = self::cron( $warnings, $partial );
					break;
				case 'errors':
					$data = self::errors( $warnings, $partial );
					break;
				case 'content':
					$data = self::content( $warnings, $partial );
					break;
				case 'menus':
					$data = self::menus( $warnings, $partial );
					break;
				case 'builders':
					$data = self::builders( $warnings, $partial );
					break;
				case 'woocommerce':
					$data = self::woocommerce( $warnings, $partial );
					break;
				case 'maintenance':
					$data = self::maintenance();
					break;
				default:
					return self::error_result( $module, 'invalid_module', 'Unsupported diagnostic module.', $start, $memory_start );
			}
		} catch ( \Throwable $exception ) {
			return self::error_result( $module, 'collector_failed', $exception->getMessage(), $start, $memory_start );
		}

		return self::result( $module, $data, $warnings, $partial, $start, $memory_start );
	}

	/**
	 * Inspect a single page/post without returning content.
	 *
	 * @param int $page_id Page ID.
	 * @return array<string,mixed>
	 */
	public static function inspect_page( $page_id ) {
		$start        = microtime( true );
		$memory_start = function_exists( 'memory_get_usage' ) ? memory_get_usage() : 0;
		$page_id      = absint( $page_id );
		$post         = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $post ) {
			return self::error_result( 'page', 'page_not_found', 'Page not found.', $start, $memory_start );
		}

		$permalink = get_permalink( $post );
		$data      = array(
			'id'              => $page_id,
			'post_type'       => sanitize_key( $post->post_type ),
			'post_status'     => sanitize_key( $post->post_status ),
			'post_name'       => sanitize_title( $post->post_name ),
			'modified_gmt'    => sanitize_text_field( $post->post_modified_gmt ),
			'template'        => sanitize_text_field( (string) get_post_meta( $page_id, '_wp_page_template', true ) ),
			'parent_id'       => (int) $post->post_parent,
			'children_count'  => self::count_children( $page_id ),
			'permalink_path'  => self::url_path( $permalink ),
			'builder_markers' => array(
				'elementor' => metadata_exists( 'post', $page_id, '_elementor_data' ),
				'wpbakery'  => metadata_exists( 'post', $page_id, '_wpb_shortcodes_custom_css' ),
				'bricks'    => metadata_exists( 'post', $page_id, '_bricks_page_content_2' ) || metadata_exists( 'post', $page_id, '_bricks_data' ),
				'divi'      => metadata_exists( 'post', $page_id, '_et_pb_use_builder' ),
				'beaver'    => metadata_exists( 'post', $page_id, '_fl_builder_data' ),
				'oxygen'    => metadata_exists( 'post', $page_id, 'ct_builder_shortcodes' ),
			),
		);

		return self::result( 'page', $data, array(), false, $start, $memory_start );
	}

	/**
	 * Inspect one menu without arbitrary filesystem or SQL access.
	 *
	 * @param int    $menu_id  Menu ID.
	 * @param string $location Theme location.
	 * @return array<string,mixed>
	 */
	public static function inspect_menu( $menu_id = 0, $location = '' ) {
		$start        = microtime( true );
		$memory_start = function_exists( 'memory_get_usage' ) ? memory_get_usage() : 0;
		$menu_id      = absint( $menu_id );
		$location     = sanitize_key( (string) $location );
		$locations    = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();

		if ( $menu_id <= 0 && '' !== $location && isset( $locations[ $location ] ) ) {
			$menu_id = absint( $locations[ $location ] );
		}

		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : null;
		if ( ! $menu ) {
			return self::error_result( 'menu', 'menu_not_found', 'Menu not found.', $start, $memory_start );
		}

		$items       = wp_get_nav_menu_items( $menu->term_id );
		$items       = is_array( $items ) ? $items : array();
		$type_counts = array();
		$statuses    = array();
		$external    = 0;
		$top_level   = 0;
		$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $items as $item ) {
			$type   = sanitize_key( (string) ( isset( $item->type ) ? $item->type : 'unknown' ) );
			$status = sanitize_key( (string) ( isset( $item->post_status ) ? $item->post_status : 'unknown' ) );
			$type_counts[ $type ] = isset( $type_counts[ $type ] ) ? $type_counts[ $type ] + 1 : 1;
			$statuses[ $status ]  = isset( $statuses[ $status ] ) ? $statuses[ $status ] + 1 : 1;
			if ( empty( $item->menu_item_parent ) ) {
				$top_level++;
			}
			$item_host = ! empty( $item->url ) ? wp_parse_url( $item->url, PHP_URL_HOST ) : '';
			if ( $item_host && $home_host && strtolower( (string) $item_host ) !== strtolower( (string) $home_host ) ) {
				$external++;
			}
		}

		$data = array(
			'menu_id'            => (int) $menu->term_id,
			'name'               => sanitize_text_field( $menu->name ),
			'slug'               => sanitize_title( $menu->slug ),
			'locations'          => self::locations_for_menu( $menu->term_id, $locations ),
			'item_count'         => count( $items ),
			'top_level_count'    => $top_level,
			'external_url_count' => $external,
			'item_types'         => $type_counts,
			'item_statuses'      => $statuses,
		);

		return self::result( 'menu', $data, array(), false, $start, $memory_start );
	}

	/**
	 * Collect environment data.
	 *
	 * @return array<string,mixed>
	 */
	private static function environment() {
		global $wpdb, $wp_version;
		return array(
			'home_url'            => home_url(),
			'site_url'            => site_url(),
			'site_name'           => get_bloginfo( 'name' ),
			'language'            => get_locale(),
			'timezone'            => wp_timezone_string(),
			'environment_type'    => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'multisite'           => is_multisite(),
			'blog_id'             => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'wordpress_version'   => isset( $wp_version ) ? (string) $wp_version : get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'php_sapi'            => PHP_SAPI,
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'os_family'           => defined( 'PHP_OS_FAMILY' ) ? PHP_OS_FAMILY : php_uname( 's' ),
			'architecture'        => php_uname( 'm' ),
			'php_memory_limit'    => ini_get( 'memory_limit' ),
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '',
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'max_input_time'      => (int) ini_get( 'max_input_time' ),
			'max_input_vars'      => (int) ini_get( 'max_input_vars' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'opcache_available'   => function_exists( 'opcache_get_status' ),
			'opcache_enabled'     => self::opcache_enabled(),
			'https'               => 0 === strpos( home_url(), 'https://' ),
			'permalink_structure' => get_option( 'permalink_structure', '' ) !== '',
			'db_charset'          => isset( $wpdb->charset ) ? $wpdb->charset : '',
			'db_collate'          => isset( $wpdb->collate ) ? $wpdb->collate : '',
			'db_version'          => isset( $wpdb ) ? $wpdb->db_version() : '',
			'constants'           => array(
				'DISABLE_WP_CRON'  => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false,
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) ? (bool) WP_DEBUG : false,
				'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) ? (bool) WP_DEBUG_LOG : false,
				'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : false,
				'SCRIPT_DEBUG'     => defined( 'SCRIPT_DEBUG' ) ? (bool) SCRIPT_DEBUG : false,
				'SAVEQUERIES'      => defined( 'SAVEQUERIES' ) ? (bool) SAVEQUERIES : false,
				'WP_CACHE'         => defined( 'WP_CACHE' ) ? (bool) WP_CACHE : false,
			),
		);
	}

	/**
	 * Collect extension data.
	 *
	 * @return array<string,mixed>
	 */
	private static function extensions() {
		self::load_plugin_functions();
		$plugins        = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$mu_plugins     = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		$dropins        = function_exists( 'get_dropins' ) ? get_dropins() : array();
		$active         = Diagnostics_Capabilities::active_plugin_files();
		$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$updates        = self::plugin_update_map();
		$items          = array();

		foreach ( $plugins as $file => $data ) {
			$items[] = array(
				'file'                      => sanitize_text_field( $file ),
				'slug'                      => sanitize_key( dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file ) ),
				'name'                      => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : $file ),
				'version'                   => sanitize_text_field( isset( $data['Version'] ) ? $data['Version'] : '' ),
				'author'                    => sanitize_text_field( isset( $data['AuthorName'] ) ? $data['AuthorName'] : ( isset( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '' ) ),
				'active'                    => in_array( $file, $active, true ),
				'network_active'            => in_array( $file, $network_active, true ),
				'must_use'                  => false,
				'drop_in'                   => false,
				'update_available'          => isset( $updates[ $file ] ),
				'new_version'               => isset( $updates[ $file ]['new_version'] ) ? $updates[ $file ]['new_version'] : '',
				'requires_php'              => isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : '',
				'requires_wordpress'        => isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : '',
				'category'                  => Diagnostics_Capabilities::extension_category( $file, isset( $data['Name'] ) ? $data['Name'] : '' ),
				'builder_or_provider_hint'  => self::provider_hint( $file, isset( $data['Name'] ) ? $data['Name'] : '' ),
			);
		}

		$mu_items = array();
		foreach ( $mu_plugins as $file => $data ) {
			$mu_items[] = array(
				'file'    => sanitize_text_field( $file ),
				'name'    => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : $file ),
				'version' => sanitize_text_field( isset( $data['Version'] ) ? $data['Version'] : '' ),
				'author'  => sanitize_text_field( isset( $data['AuthorName'] ) ? $data['AuthorName'] : '' ),
			);
		}

		$dropin_items = array();
		foreach ( $dropins as $file => $data ) {
			$dropin_items[] = array(
				'file' => sanitize_text_field( $file ),
				'name' => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : $file ),
			);
		}

		return array(
			'plugins'        => $items,
			'mu_plugins'     => $mu_items,
			'dropins'        => $dropin_items,
			'themes'         => self::themes(),
			'counts'         => array(
				'plugins'    => count( $items ),
				'active'     => count( $active ),
				'mu_plugins' => count( $mu_items ),
				'dropins'    => count( $dropin_items ),
			),
		);
	}

	/**
	 * Collect cache data.
	 *
	 * @return array<string,mixed>
	 */
	private static function cache() {
		$capabilities = Diagnostics_Capabilities::detect_light();
		return array(
			'capability'      => isset( $capabilities['cache'] ) ? $capabilities['cache'] : array(),
			'wp_cache'        => defined( 'WP_CACHE' ) ? (bool) WP_CACHE : false,
			'cache_constants' => array(
				'WP_CACHE_KEY_SALT' => defined( 'WP_CACHE_KEY_SALT' ) ? true : false,
				'WP_REDIS_CONFIG'   => defined( 'WP_REDIS_CONFIG' ) ? true : false,
			),
		);
	}

	/**
	 * Collect database metadata.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function database( array &$warnings, &$partial ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			$partial    = true;
			$warnings[] = 'database_unavailable';
			return array();
		}

		$prefix  = $wpdb->prefix;
		$tables  = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
		$summary = array(
			'db_version'          => $wpdb->db_version(),
			'prefix_hash'         => hash( 'sha256', (string) $prefix ),
			'table_count'         => 0,
			'total_rows_estimate' => 0,
			'data_length_bytes'   => 0,
			'index_length_bytes'  => 0,
			'tables'              => array(),
			'autoload'            => self::autoload_summary(),
		);

		if ( ! is_array( $tables ) ) {
			$partial    = true;
			$warnings[] = 'table_status_unavailable';
			return $summary;
		}

		foreach ( $tables as $table ) {
			$name       = isset( $table['Name'] ) ? (string) $table['Name'] : '';
			$table_key  = 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : hash( 'sha256', $name );
			$rows       = isset( $table['Rows'] ) ? (int) $table['Rows'] : 0;
			$data_len   = isset( $table['Data_length'] ) ? (int) $table['Data_length'] : 0;
			$index_len  = isset( $table['Index_length'] ) ? (int) $table['Index_length'] : 0;

			$summary['table_count']++;
			$summary['total_rows_estimate'] += max( 0, $rows );
			$summary['data_length_bytes']   += max( 0, $data_len );
			$summary['index_length_bytes']  += max( 0, $index_len );
			$summary['tables'][] = array(
				'name'               => sanitize_key( $table_key ),
				'rows_estimate'      => max( 0, $rows ),
				'data_length_bytes'  => max( 0, $data_len ),
				'index_length_bytes' => max( 0, $index_len ),
				'engine'             => sanitize_text_field( isset( $table['Engine'] ) ? $table['Engine'] : '' ),
			);
		}

		usort(
			$summary['tables'],
			function ( $first, $second ) {
				return ( (int) $second['data_length_bytes'] + (int) $second['index_length_bytes'] ) <=> ( (int) $first['data_length_bytes'] + (int) $first['index_length_bytes'] );
			}
		);
		$summary['tables'] = array_slice( $summary['tables'], 0, 25 );

		return $summary;
	}

	/**
	 * Collect cron metadata.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function cron( array &$warnings, &$partial ) {
		if ( ! function_exists( '_get_cron_array' ) ) {
			$partial    = true;
			$warnings[] = 'cron_api_unavailable';
			return array();
		}

		$crons      = _get_cron_array();
		$now        = time();
		$by_hook    = array();
		$total      = 0;
		$overdue    = 0;
		$next       = 0;
		$mcu_events = array();

		if ( ! is_array( $crons ) ) {
			return array( 'total_events' => 0, 'overdue_events' => 0, 'next_run' => 0, 'hooks' => array() );
		}

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				$count = is_array( $events ) ? count( $events ) : 0;
				$total += $count;
				$hook_key = sanitize_key( (string) $hook );
				$by_hook[ $hook_key ] = isset( $by_hook[ $hook_key ] ) ? $by_hook[ $hook_key ] + $count : $count;
				if ( (int) $timestamp < $now ) {
					$overdue += $count;
				}
				if ( 0 === $next || (int) $timestamp < $next ) {
					$next = (int) $timestamp;
				}
				if ( 0 === strpos( $hook_key, 'marrison_' ) || 0 === strpos( $hook_key, 'mcu_' ) ) {
					$mcu_events[] = array( 'hook' => $hook_key, 'timestamp' => (int) $timestamp, 'count' => $count );
				}
			}
		}

		arsort( $by_hook );
		return array(
			'total_events'   => $total,
			'overdue_events' => $overdue,
			'next_run'       => $next,
			'hooks'          => array_slice( $by_hook, 0, 30, true ),
			'mcu_events'     => array_slice( $mcu_events, 0, 20 ),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false,
		);
	}

	/**
	 * Collect redacted error fingerprints.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function errors( array &$warnings, &$partial ) {
		$path = self::debug_log_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return array( 'debug_log_readable' => false, 'fingerprints' => array(), 'log_size_bytes' => 0 );
		}

		$size = filesize( $path );
		$tail = self::read_tail( $path, self::LOG_TAIL_BYTES );
		if ( is_wp_error( $tail ) ) {
			$partial    = true;
			$warnings[] = 'debug_log_read_failed';
			$tail       = '';
		}

		$fingerprints = array();
		foreach ( preg_split( "/\r\n|\n|\r/", (string) $tail ) ?: array() as $line ) {
			$line = trim( Diagnostics_Sanitizer::sanitize_text( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$fingerprint = hash( 'sha256', preg_replace( '/\d+/', '#', $line ) );
			if ( ! isset( $fingerprints[ $fingerprint ] ) ) {
				$fingerprints[ $fingerprint ] = array(
					'fingerprint' => $fingerprint,
					'count'       => 0,
					'example'     => substr( $line, 0, 240 ),
				);
			}
			$fingerprints[ $fingerprint ]['count']++;
		}

		return array(
			'debug_log_readable' => true,
			'log_size_bytes'     => (int) $size,
			'tail_bytes_read'    => strlen( (string) $tail ),
			'fingerprints'       => array_slice( array_values( $fingerprints ), 0, 25 ),
		);
	}

	/**
	 * Collect aggregate content counts.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function content( array &$warnings, &$partial ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) AS count FROM {$wpdb->posts} GROUP BY post_type, post_status", ARRAY_A );
		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$type   = sanitize_key( (string) $row['post_type'] );
				$status = sanitize_key( (string) $row['post_status'] );
				if ( ! isset( $counts[ $type ] ) ) {
					$counts[ $type ] = array();
				}
				$counts[ $type ][ $status ] = (int) $row['count'];
			}
		}

		return array(
			'post_counts'     => $counts,
			'front_page_id'   => (int) get_option( 'page_on_front', 0 ),
			'posts_page_id'   => (int) get_option( 'page_for_posts', 0 ),
			'show_on_front'   => sanitize_key( (string) get_option( 'show_on_front', '' ) ),
			'builder_markers' => self::builder_marker_counts(),
		);
	}

	/**
	 * Collect menu summaries.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function menus( array &$warnings, &$partial ) {
		$locations = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
		$menus     = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus() : array();
		$result    = array();

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			$result[] = array(
				'menu_id'    => (int) $menu->term_id,
				'name'       => sanitize_text_field( $menu->name ),
				'slug'       => sanitize_title( $menu->slug ),
				'count'      => is_array( $items ) ? count( $items ) : 0,
				'locations'  => self::locations_for_menu( $menu->term_id, $locations ),
			);
		}

		return array(
			'locations' => array_map( 'absint', (array) $locations ),
			'menus'     => $result,
			'count'     => count( $result ),
		);
	}

	/**
	 * Collect builder summaries.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function builders( array &$warnings, &$partial ) {
		$capabilities = Diagnostics_Capabilities::detect_light();
		return array(
			'detected'      => isset( $capabilities['builders'] ) ? $capabilities['builders'] : array(),
			'marker_counts' => self::builder_marker_counts(),
		);
	}

	/**
	 * Collect WooCommerce aggregate data.
	 *
	 * @param array<int,string> $warnings Warnings.
	 * @param bool             $partial  Partial flag.
	 * @return array<string,mixed>
	 */
	private static function woocommerce( array &$warnings, &$partial ) {
		global $wpdb;
		$caps = Diagnostics_Capabilities::detect_light();
		if ( empty( $caps['woocommerce']['active'] ) ) {
			return array( 'active' => false );
		}

		$product_counts = $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) AS count FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status", 'product' ), ARRAY_A );
		$order_counts   = $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) AS count FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status", 'shop_order' ), ARRAY_A );

		return array(
			'active'       => true,
			'version'      => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'hpos_enabled' => isset( $caps['woocommerce']['hpos_enabled'] ) ? $caps['woocommerce']['hpos_enabled'] : 'unknown',
			'products'     => self::rows_to_counts( $product_counts, 'post_status' ),
			'orders_legacy'=> self::rows_to_counts( $order_counts, 'post_status' ),
		);
	}

	/**
	 * Collect maintenance data.
	 *
	 * @return array<string,mixed>
	 */
	private static function maintenance() {
		return array(
			'last_cron_log'        => get_option( 'marrison_last_cron_log', array() ),
			'master_update_status' => class_exists( __NAMESPACE__ . '\\Actions_Controller' ) ? Actions_Controller::current_update_status() : array(),
			'update_lock'          => class_exists( __NAMESPACE__ . '\\Actions_Controller' ) ? Actions_Controller::current_update_lock_status() : array( 'locked' => false ),
			'diagnostic_pipeline'  => class_exists( __NAMESPACE__ . '\\Diagnostics_Storage' ) ? Diagnostics_Storage::pipeline_summary() : array(),
			'auto_update'          => array(
				'enabled'   => 'yes' === get_option( 'marrison_auto_update_enabled' ),
				'frequency' => sanitize_key( (string) get_option( 'marrison_auto_update_frequency', 'daily' ) ),
				'time'      => sanitize_text_field( (string) get_option( 'marrison_auto_update_time', '00:00' ) ),
				'month_day' => absint( get_option( 'marrison_auto_update_month_day', 0 ) ),
			),
		);
	}

	/**
	 * Return normalized module result.
	 *
	 * @param string              $module       Module key.
	 * @param array<string,mixed> $data         Data.
	 * @param array<int,string>   $warnings     Warnings.
	 * @param bool                $partial      Partial flag.
	 * @param float               $start        Start time.
	 * @param int                 $memory_start Memory start.
	 * @return array<string,mixed>
	 */
	private static function result( $module, array $data, array $warnings, $partial, $start, $memory_start ) {
		$data = Diagnostics_Sanitizer::sanitize( $data );
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = is_string( $json ) ? $json : '{}';

		return array(
			'success'            => true,
			'module'             => sanitize_key( (string) $module ),
			'schema_version'     => Diagnostics_Storage::SCHEMA_VERSION,
			'generated_at'       => time(),
			'duration_ms'        => (int) round( ( microtime( true ) - (float) $start ) * 1000 ),
			'memory_delta_bytes' => function_exists( 'memory_get_usage' ) ? max( 0, memory_get_usage() - (int) $memory_start ) : 0,
			'partial'            => (bool) $partial,
			'warnings'           => array_values( array_map( 'sanitize_key', $warnings ) ),
			'data'               => $data,
			'checksum'           => hash( 'sha256', $json ),
			'size_bytes'         => strlen( $json ),
		);
	}

	/**
	 * Return normalized module error.
	 *
	 * @param string $module       Module key.
	 * @param string $code         Error code.
	 * @param string $message      Error message.
	 * @param float  $start        Start time.
	 * @param int    $memory_start Memory start.
	 * @return array<string,mixed>
	 */
	private static function error_result( $module, $code, $message, $start, $memory_start ) {
		return array(
			'success'            => false,
			'module'             => sanitize_key( (string) $module ),
			'schema_version'     => Diagnostics_Storage::SCHEMA_VERSION,
			'generated_at'       => time(),
			'duration_ms'        => (int) round( ( microtime( true ) - (float) $start ) * 1000 ),
			'memory_delta_bytes' => function_exists( 'memory_get_usage' ) ? max( 0, memory_get_usage() - (int) $memory_start ) : 0,
			'partial'            => true,
			'warnings'           => array( sanitize_key( (string) $code ) ),
			'error_code'         => sanitize_key( (string) $code ),
			'message'            => Diagnostics_Sanitizer::sanitize_text( $message ),
			'data'               => array(),
			'checksum'           => '',
			'size_bytes'         => 0,
		);
	}

	/**
	 * Load plugin helpers.
	 *
	 * @return void
	 */
	private static function load_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Return plugin update summaries.
	 *
	 * @param array<string,array<string,string>> $plugins Plugins.
	 * @param mixed                             $updates Update transient.
	 * @return array<int,array<string,string>>
	 */
	private static function plugin_update_summary( array $plugins, $updates ) {
		$items = array();
		if ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) {
			foreach ( $updates->response as $file => $update ) {
				$data = isset( $plugins[ $file ] ) ? $plugins[ $file ] : array();
				$items[] = array(
					'file'            => sanitize_text_field( $file ),
					'name'            => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : basename( $file ) ),
					'current_version' => sanitize_text_field( isset( $data['Version'] ) ? $data['Version'] : '' ),
					'new_version'     => sanitize_text_field( is_object( $update ) && isset( $update->new_version ) ? $update->new_version : '' ),
				);
			}
		}

		return array_slice( $items, 0, 100 );
	}

	/**
	 * Return theme update summaries.
	 *
	 * @param mixed $updates Theme update transient.
	 * @return array<int,array<string,string>>
	 */
	private static function theme_update_summary( $updates ) {
		$items = array();
		if ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) {
			foreach ( $updates->response as $slug => $update ) {
				$theme = wp_get_theme( $slug );
				$items[] = array(
					'slug'            => sanitize_key( $slug ),
					'name'            => sanitize_text_field( $theme->exists() ? $theme->get( 'Name' ) : $slug ),
					'current_version' => sanitize_text_field( $theme->exists() ? $theme->get( 'Version' ) : '' ),
					'new_version'     => sanitize_text_field( is_array( $update ) && isset( $update['new_version'] ) ? $update['new_version'] : '' ),
				);
			}
		}

		return array_slice( $items, 0, 100 );
	}

	/**
	 * Return plugin update map.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function plugin_update_map() {
		self::load_plugin_functions();
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$updates = get_site_transient( 'update_plugins' );
		$map     = array();
		foreach ( self::plugin_update_summary( $plugins, $updates ) as $item ) {
			$map[ $item['file'] ] = $item;
		}

		return $map;
	}

	/**
	 * Return theme summaries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function themes() {
		$current = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$themes  = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$update = is_object( $updates ) && isset( $updates->response[ $slug ] ) ? $updates->response[ $slug ] : array();
			$themes[] = array(
				'slug'             => sanitize_key( $slug ),
				'name'             => sanitize_text_field( $theme->get( 'Name' ) ),
				'version'          => sanitize_text_field( $theme->get( 'Version' ) ),
				'active'           => $theme->get_stylesheet() === $current->get_stylesheet(),
				'parent'           => sanitize_key( $theme->get_template() ),
				'child_theme'      => $theme->get_stylesheet() !== $theme->get_template(),
				'update_available' => ! empty( $update ),
				'new_version'      => is_array( $update ) && isset( $update['new_version'] ) ? sanitize_text_field( $update['new_version'] ) : '',
			);
		}

		return $themes;
	}

	/**
	 * Return a provider hint.
	 *
	 * @param string $file Plugin file.
	 * @param string $name Plugin name.
	 * @return string
	 */
	private static function provider_hint( $file, $name ) {
		$key = strtolower( (string) $file . ' ' . (string) $name );
		foreach ( array( 'elementor', 'wpbakery', 'bricks', 'divi', 'beaver', 'oxygen', 'jet', 'woocommerce', 'acf' ) as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return $needle;
			}
		}

		return '';
	}

	/**
	 * Return autoload option summary.
	 *
	 * @return array<string,int>
	 */
	private static function autoload_summary() {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT COUNT(*) AS option_count, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" );
		if ( ! $row ) {
			return array( 'option_count' => 0, 'bytes' => 0 );
		}

		return array(
			'option_count' => (int) $row->option_count,
			'bytes'        => (int) $row->bytes,
		);
	}

	/**
	 * Return counts from database rows.
	 *
	 * @param array<int,array<string,mixed>>|null $rows Rows.
	 * @param string                              $key  Key.
	 * @return array<string,int>
	 */
	private static function rows_to_counts( $rows, $key ) {
		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ sanitize_key( (string) $row[ $key ] ) ] = (int) $row['count'];
			}
		}

		return $counts;
	}

	/**
	 * Return builder marker counts.
	 *
	 * @return array<string,int>
	 */
	private static function builder_marker_counts() {
		global $wpdb;
		$keys   = array(
			'elementor' => '_elementor_data',
			'wpbakery'  => '_wpb_shortcodes_custom_css',
			'bricks'    => '_bricks_page_content_2',
			'divi'      => '_et_pb_use_builder',
			'beaver'    => '_fl_builder_data',
			'oxygen'    => 'ct_builder_shortcodes',
		);
		$counts = array();
		foreach ( $keys as $label => $meta_key ) {
			$counts[ $label ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) );
		}

		return $counts;
	}

	/**
	 * Count child posts.
	 *
	 * @param int $page_id Page ID.
	 * @return int
	 */
	private static function count_children( $page_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d", absint( $page_id ) ) );
	}

	/**
	 * Return menu locations for a menu ID.
	 *
	 * @param int                  $menu_id   Menu ID.
	 * @param array<string,mixed>  $locations Locations.
	 * @return array<int,string>
	 */
	private static function locations_for_menu( $menu_id, array $locations ) {
		$matched = array();
		foreach ( $locations as $location => $assigned_id ) {
			if ( (int) $assigned_id === (int) $menu_id ) {
				$matched[] = sanitize_key( (string) $location );
			}
		}

		return $matched;
	}

	/**
	 * Return a URL path only.
	 *
	 * @param string|false $url URL.
	 * @return string
	 */
	private static function url_path( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? sanitize_text_field( $path ) : '';
	}

	/**
	 * Return debug log path.
	 *
	 * @return string
	 */
	private static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/debug.log';
		}

		return '';
	}

	/**
	 * Read the tail of a file.
	 *
	 * @param string $path  File path.
	 * @param int    $bytes Max bytes.
	 * @return string|\WP_Error
	 */
	private static function read_tail( $path, $bytes ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'read_failed', 'Could not read log.' );
		}

		$size = filesize( $path );
		if ( $size > $bytes ) {
			fseek( $handle, -1 * absint( $bytes ), SEEK_END );
		}
		$data = stream_get_contents( $handle );
		fclose( $handle );

		return is_string( $data ) ? $data : '';
	}

	/**
	 * Return OPcache status.
	 *
	 * @return bool|string
	 */
	private static function opcache_enabled() {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return false;
		}
		$status = @opcache_get_status( false );
		if ( ! is_array( $status ) || ! isset( $status['opcache_enabled'] ) ) {
			return 'unknown';
		}

		return (bool) $status['opcache_enabled'];
	}
}
