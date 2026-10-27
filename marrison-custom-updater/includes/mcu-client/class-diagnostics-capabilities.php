<?php
/**
 * Lightweight site capability detection.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects capabilities without loading inactive plugins.
 */
final class Diagnostics_Capabilities {
	/**
	 * Return tri-state capabilities.
	 *
	 * @return array<string,mixed>
	 */
	public static function detect_light() {
		self::load_plugin_functions();

		$theme = wp_get_theme();

		return array(
			'wordpress_core' => true,
			'rest_api'       => function_exists( 'rest_get_server' ),
			'multisite'      => is_multisite(),
			'native_menus'   => current_theme_supports( 'menus' ) || function_exists( 'wp_get_nav_menus' ),
			'wp_cron'        => defined( 'DISABLE_WP_CRON' ) ? ! DISABLE_WP_CRON : true,
			'wp_cli'         => defined( 'WP_CLI' ) && WP_CLI ? true : 'unknown',
			'theme'          => array(
				'stylesheet' => sanitize_key( $theme->get_stylesheet() ),
				'template'   => sanitize_key( $theme->get_template() ),
				'is_child'   => $theme->get_stylesheet() !== $theme->get_template(),
			),
			'builders'       => self::builders( $theme ),
			'acf'            => self::active_or_loaded( array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php' ), array( 'ACF_VERSION' ), array( 'ACF' ) ),
			'crocoblock'     => self::crocoblock(),
			'woocommerce'    => self::woocommerce(),
			'cache'          => self::cache(),
			'diagnostics'    => array(
				'debug_log_readable'         => self::debug_log_readable(),
				'database_metadata_available'=> isset( $GLOBALS['wpdb'] ),
				'disk_space_available'       => function_exists( 'disk_free_space' ),
				'loopback_test_available'    => function_exists( 'wp_remote_get' ),
			),
		);
	}

	/**
	 * Return active plugin files.
	 *
	 * @return array<int,string>
	 */
	public static function active_plugin_files() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		return array_values( array_unique( array_map( 'sanitize_text_field', $active ) ) );
	}

	/**
	 * Classify an extension.
	 *
	 * @param string $file Plugin file.
	 * @param string $name Plugin name.
	 * @return string
	 */
	public static function extension_category( $file, $name = '' ) {
		$key = strtolower( (string) $file . ' ' . (string) $name );
		if ( false !== strpos( $key, 'elementor' ) ) {
			return 'builder';
		}
		if ( false !== strpos( $key, 'woocommerce' ) ) {
			return 'commerce';
		}
		if ( false !== strpos( $key, 'cache' ) || false !== strpos( $key, 'redis' ) || false !== strpos( $key, 'litespeed' ) ) {
			return 'cache';
		}
		if ( false !== strpos( $key, 'jet' ) || false !== strpos( $key, 'crocoblock' ) ) {
			return 'crocoblock';
		}
		if ( false !== strpos( $key, 'acf' ) || false !== strpos( $key, 'custom fields' ) ) {
			return 'fields';
		}

		return 'other';
	}

	/**
	 * Load WordPress plugin helpers.
	 *
	 * @return void
	 */
	private static function load_plugin_functions() {
		if ( ! function_exists( 'is_plugin_active' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Return builder capability flags.
	 *
	 * @param \WP_Theme $theme Active theme.
	 * @return array<string,mixed>
	 */
	private static function builders( $theme ) {
		$theme_key = strtolower( $theme->get_stylesheet() . ' ' . $theme->get_template() . ' ' . $theme->get( 'Name' ) );

		return array(
			'gutenberg'              => function_exists( 'register_block_type' ),
			'elementor'              => self::active_or_loaded( array( 'elementor/elementor.php' ), array( 'ELEMENTOR_VERSION' ), array( '\\Elementor\\Plugin' ) ),
			'elementor_pro'          => self::active_or_loaded( array( 'elementor-pro/elementor-pro.php' ), array( 'ELEMENTOR_PRO_VERSION' ), array( '\\ElementorPro\\Plugin' ) ),
			'wpbakery'               => self::active_or_loaded( array( 'js_composer/js_composer.php' ), array( 'WPB_VC_VERSION' ), array( 'Vc_Manager' ) ),
			'bricks'                 => self::active_or_loaded( array( 'bricks/bricks.php' ), array( 'BRICKS_VERSION' ), array( 'Bricks\\Theme' ) ) || false !== strpos( $theme_key, 'bricks' ),
			'divi'                   => self::active_or_loaded( array( 'divi-builder/divi-builder.php' ), array( 'ET_BUILDER_VERSION' ), array( 'ET_Builder_Plugin' ) ) || false !== strpos( $theme_key, 'divi' ),
			'beaver_builder'         => self::active_or_loaded( array( 'bb-plugin/fl-builder.php' ), array( 'FL_BUILDER_VERSION' ), array( 'FLBuilder' ) ),
			'oxygen'                 => self::active_or_loaded( array( 'oxygen/functions.php' ), array( 'CT_VERSION' ), array( 'OxygenElement' ) ),
			'proprietary_or_unknown' => false,
		);
	}

	/**
	 * Return Crocoblock flags.
	 *
	 * @return array<string,bool>
	 */
	private static function crocoblock() {
		return array(
			'jetengine'       => self::plugin_active_like( 'jet-engine/' ),
			'jetmenu'         => self::plugin_active_like( 'jet-menu/' ),
			'jetformbuilder'  => self::plugin_active_like( 'jetformbuilder/' ),
			'jetsmartfilters' => self::plugin_active_like( 'jet-smart-filters/' ),
			'jetbooking'      => self::plugin_active_like( 'jet-booking/' ),
			'jetappointment'  => self::plugin_active_like( 'jet-appointments-booking/' ),
			'jetwoobuilder'   => self::plugin_active_like( 'jet-woo-builder/' ),
		);
	}

	/**
	 * Return WooCommerce flags.
	 *
	 * @return array<string,mixed>
	 */
	private static function woocommerce() {
		$active = self::active_or_loaded( array( 'woocommerce/woocommerce.php' ), array( 'WC_VERSION' ), array( 'WooCommerce' ) );
		return array(
			'active'                     => $active,
			'hpos_enabled'               => $active && function_exists( 'wc_get_container' ) ? self::woocommerce_hpos_enabled() : 'unknown',
			'action_scheduler_available' => class_exists( 'ActionScheduler', false ) || function_exists( 'as_schedule_single_action' ),
		);
	}

	/**
	 * Return cache flags.
	 *
	 * @return array<string,mixed>
	 */
	private static function cache() {
		$page_cache = 'unknown';
		$plugins    = array(
			'litespeed-cache/'      => 'litespeed',
			'wp-rocket/'            => 'wp-rocket',
			'w3-total-cache/'       => 'w3-total-cache',
			'wp-super-cache/'       => 'wp-super-cache',
			'wp-fastest-cache/'     => 'wp-fastest-cache',
			'sg-cachepress/'        => 'siteground',
			'autoptimize/'          => 'autoptimize',
			'breeze/'               => 'breeze',
			'cache-enabler/'        => 'cache-enabler',
		);
		foreach ( $plugins as $prefix => $label ) {
			if ( self::plugin_active_like( $prefix ) ) {
				$page_cache = $label;
				break;
			}
		}

		$object_cache = 'none';
		if ( self::plugin_active_like( 'redis-cache/' ) || defined( 'WP_REDIS_CONFIG' ) || defined( 'WP_REDIS_DISABLED' ) ) {
			$object_cache = 'redis';
		} elseif ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$object_cache = 'external';
		}

		return array(
			'page_cache'              => $page_cache,
			'object_cache'            => $object_cache,
			'persistent_object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : 'unknown',
			'advanced_cache_dropin'   => defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'object_cache_dropin'     => defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		);
	}

	/**
	 * Return whether a plugin, constant, or already-loaded class is active.
	 *
	 * @param array<int,string> $plugins Plugin files.
	 * @param array<int,string> $constants Constants.
	 * @param array<int,string> $classes Classes.
	 * @return bool
	 */
	private static function active_or_loaded( array $plugins, array $constants = array(), array $classes = array() ) {
		foreach ( $plugins as $plugin ) {
			if ( self::plugin_active_like( $plugin ) || ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin ) ) ) {
				return true;
			}
		}
		foreach ( $constants as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}
		foreach ( $classes as $class ) {
			if ( class_exists( $class, false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return whether an active plugin starts with a prefix.
	 *
	 * @param string $prefix Plugin prefix or file.
	 * @return bool
	 */
	private static function plugin_active_like( $prefix ) {
		$prefix = (string) $prefix;
		foreach ( self::active_plugin_files() as $plugin ) {
			if ( $plugin === $prefix || 0 === strpos( $plugin, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Safely detect WooCommerce HPOS.
	 *
	 * @return bool|string
	 */
	private static function woocommerce_hpos_enabled() {
		$enabled = get_option( 'woocommerce_custom_orders_table_enabled', '' );
		if ( '' === $enabled ) {
			return 'unknown';
		}

		return 'yes' === $enabled;
	}

	/**
	 * Return whether the configured debug log can be read.
	 *
	 * @return bool
	 */
	private static function debug_log_readable() {
		$path = '';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$path = WP_DEBUG_LOG;
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$path = WP_CONTENT_DIR . '/debug.log';
		}

		return '' !== $path && is_readable( $path );
	}
}
