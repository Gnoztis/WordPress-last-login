<?php
/**
 * Plugin Name: WordPress Last Login
 * Description: Displays the date of the last login and IP in user lists (admins only).
 * Version:     1.3.2
 * Author:      Gnostis
 * License:     GPL-2.0-or-later
 * Text Domain: wp-last-login
 * Domain Path: /lang
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Update URI:  https://github.com/Gnoztis/WordPress-last-login
 *
 * @package WordPress-last-login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPLL_VERSION', '1.3.2' );
define( 'WPLL_PLUGIN_FILE', __FILE__ );
define( 'WPLL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WPLL_PLUGIN_DIR . 'includes/class-wpll-github-updater.php';

final class WPLL_Last_Login {

	const META_LAST_LOGIN = 'wp-last-login';
	const META_LAST_IP    = 'wp-last-ip';

	const TEXTDOMAIN = 'wp-last-login';

	/**
	 * Capability required to see the extra columns.
	 *
	 * @var string
	 */
	const REQUIRED_CAP = 'manage_options';

	/**
	 * Bootstrap the plugin.
	 *
	 * IMPORTANT: this method runs while the plugin file is being included,
	 * i.e. before pluggable.php is loaded. It MUST NOT call current_user_can()
	 * or any other pluggable function directly — only register hooks.
	 */
	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'maybe_register_admin_columns' ), 20 );

		// Login tracking (always active, regardless of who views the list).
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'two_factor_user_authenticated', array( __CLASS__, 'on_login_2fa' ) );

		// Plugin updates through the native WordPress mechanism (GitHub).
		$updater = new WPLL_GitHub_Updater();
		$updater->register_hooks();
	}

	/**
	 * Deferred registration of admin columns.
	 *
	 * Runs on `init`, by which time pluggable.php is loaded and
	 * current_user_can() is safe to call.
	 */
	public static function maybe_register_admin_columns() {
		if ( self::current_user_can_see() ) {
			self::register_admin_columns();
		}
	}

	/**
	 * Whether the current user may see the extra columns.
	 *
	 * Default: only users with `manage_options` (administrators).
	 * Can be overridden via the `wpll_current_user_can` filter.
	 *
	 * @return bool
	 */
	private static function current_user_can_see() {
		$can = current_user_can( self::REQUIRED_CAP );

		/**
		 * Filter whether the current user can see the extra columns.
		 *
		 * @param bool $can Default permission (admins only).
		 */
		return (bool) apply_filters( 'wpll_current_user_can', $can );
	}

	/**
	 * Attach admin column related hooks.
	 */
	private static function register_admin_columns() {
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_columns' ), 1 );
		add_filter( 'wpmu_users_columns', array( __CLASS__, 'add_columns' ), 1 );
		add_filter( 'manage_site-users-network_columns', array( __CLASS__, 'add_columns' ), 1 );

		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );

		add_action( 'admin_print_styles-users.php', array( __CLASS__, 'column_style' ) );
		add_action( 'admin_print_styles-site-users.php', array( __CLASS__, 'column_style' ) );

		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'add_sortable' ) );
		add_filter( 'manage_users-network_sortable_columns', array( __CLASS__, 'add_sortable' ) );
		add_filter( 'pre_get_users', array( __CLASS__, 'order_by_last_login' ) );
	}

	/**
	 * Activation: seed default meta for all existing users.
	 *
	 * Only missing keys are written: reactivating the plugin (or updating it)
	 * must not erase the recorded history, which is the whole point of the
	 * stored data.
	 */
	public static function activate() {
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			self::seed_missing_meta( $user_id );
		}
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			self::TEXTDOMAIN,
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/lang'
		);
	}

	/**
	 * Seed default meta for newly registered users.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ) {
		self::seed_missing_meta( $user_id );
	}

	/**
	 * Writes the default value for every meta key of a user that lacks it.
	 *
	 * The columns read the keys and the users list is sortable by the login
	 * time, so every user should carry both keys; an existing value is never
	 * touched.
	 *
	 * @param int $user_id User ID.
	 */
	private static function seed_missing_meta( $user_id ) {
		$user_id = (int) $user_id;

		foreach ( array( self::META_LAST_LOGIN, self::META_LAST_IP ) as $key ) {
			if ( ! metadata_exists( 'user', $user_id, $key ) ) {
				update_user_meta( $user_id, $key, 0 );
			}
		}
	}

	/**
	 * Record login time and IP.
	 *
	 * @param string       $user_login User login name.
	 * @param WP_User|null $user       User object (WP 5.4+ passes it).
	 */
	public static function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}
		self::record_login( $user );
	}

	/**
	 * Record login for Two Factor authentication.
	 *
	 * The Two Factor plugin fires this after the second factor passes,
	 * since `wp_login` is halted during 2FA.
	 *
	 * @param WP_User $user User object.
	 */
	public static function on_login_2fa( $user ) {
		self::record_login( $user );
	}

	/**
	 * Persist login timestamp and IP for a user.
	 *
	 * @param WP_User|false $user User object.
	 */
	private static function record_login( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		update_user_meta( $user->ID, self::META_LAST_LOGIN, time() );
		update_user_meta( $user->ID, self::META_LAST_IP, self::get_client_ip() );
	}

	/**
	 * Resolve the real client IP of the current request.
	 *
	 * A forwarding header is only trusted when the request came from a
	 * trusted proxy. Otherwise every visitor could choose the address that
	 * gets recorded, simply by sending X-Real-IP or X-Forwarded-For: the
	 * column is an audit trail, so a forged value is worse than a proxy
	 * address.
	 *
	 * A proxy is trusted when its address is not routable from the internet
	 * (loopback, private or reserved — a normal reverse proxy on the same
	 * host or in the same network) or when it is listed in the
	 * `wpll_trusted_proxies` filter. A request that arrives straight from a
	 * public address is recorded by that address; its headers are ignored.
	 *
	 * Priority for a trusted proxy:
	 *   1. X-Real-IP        — set by nginx to the address of its peer.
	 *   2. X-Forwarded-For  — last entry is the address appended by our nginx
	 *                         (with $proxy_add_x_forwarded_for).
	 *   3. REMOTE_ADDR      — fallback (the proxy itself).
	 *
	 * @return string|null Valid IP address, null when none is known.
	 */
	private static function get_client_ip() {
		$remote = self::server_value( 'REMOTE_ADDR' );
		if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$real = self::server_value( 'HTTP_X_REAL_IP' );
		if ( '' !== $real && filter_var( $real, FILTER_VALIDATE_IP ) ) {
			return $real;
		}

		$forwarded = self::server_value( 'HTTP_X_FORWARDED_FOR' );
		if ( '' !== $forwarded ) {
			$parts = array_map( 'trim', explode( ',', $forwarded ) );
			$last  = (string) end( $parts );
			if ( filter_var( $last, FILTER_VALIDATE_IP ) ) {
				return $last;
			}
		}

		return $remote;
	}

	/**
	 * Whether the request source is allowed to set forwarding headers.
	 *
	 * @param string $remote Validated REMOTE_ADDR of the request.
	 * @return bool
	 */
	private static function is_trusted_proxy( $remote ) {
		/**
		 * Filter the proxy addresses whose forwarding headers are trusted.
		 *
		 * @param string[] $proxies IP addresses of trusted proxies. Default: none.
		 */
		$proxies = array_filter(
			array_map( 'strval', (array) apply_filters( 'wpll_trusted_proxies', array() ) )
		);

		if ( in_array( (string) $remote, $proxies, true ) ) {
			return true;
		}

		// A public address cannot be a proxy of this site: a connection from
		// the internet carries the address of the sender, so its forwarding
		// headers are controlled by the sender.
		return ! filter_var(
			$remote,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Read a $_SERVER value as a trimmed string.
	 *
	 * @param string $key Server variable name.
	 * @return string Empty string when the variable is missing.
	 */
	private static function server_value( $key ) {
		$value = isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : '';

		// A header is a string; anything else (an array injected into $_SERVER)
		// is not a value this method may return.
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Register custom columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_columns( $columns ) {
		$columns['wp-last-login'] = __( 'Last Login', self::TEXTDOMAIN );
		$columns['wp-last-ip']    = __( 'Last IP', self::TEXTDOMAIN );
		return $columns;
	}

	/**
	 * Column width.
	 */
	public static function column_style() {
		echo '<style>.column-wp-last-login{width:14%}.column-wp-last-ip{width:14%}</style>';
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $value       Default value.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_column( $value, $column_name, $user_id ) {
		// Defence in depth: the columns are only registered for allowed users,
		// but a third party can register the same column keys.
		if ( 'wp-last-login' !== $column_name && 'wp-last-ip' !== $column_name ) {
			return $value;
		}

		if ( ! self::current_user_can_see() ) {
			return $value;
		}

		if ( 'wp-last-login' === $column_name ) {
			return self::render_last_login( (int) $user_id );
		}

		return self::render_last_ip( (int) $user_id );
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function render_last_login( $user_id ) {
		$last_login = (int) get_user_meta( $user_id, self::META_LAST_LOGIN, true );

		if ( ! $last_login ) {
			return esc_html__( 'Never.', self::TEXTDOMAIN );
		}

		$format    = (string) apply_filters( 'wpll_date_format', get_option( 'date_format' ) );
		$timestamp = (int) get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $last_login ), 'U' );

		return sprintf(
			'<time title="%1$s" datetime="%2$s">%3$s</time>',
			esc_attr( date_i18n( get_option( 'time_format' ), $timestamp ) ),
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_html( date_i18n( $format, $timestamp ) )
		);
	}

	/**
	 * Render the "Last IP" column as a link to ipinfo.io.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function render_last_ip( $user_id ) {
		$last_ip = get_user_meta( $user_id, self::META_LAST_IP, true );

		if ( ! $last_ip || ! filter_var( $last_ip, FILTER_VALIDATE_IP ) ) {
			return esc_html__( 'Never.', self::TEXTDOMAIN );
		}

		$url = 'https://ipinfo.io/' . rawurlencode( (string) $last_ip );

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			esc_html( $last_ip )
		);
	}

	/**
	 * Make the Last Login column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function add_sortable( $columns ) {
		$columns['wp-last-login'] = 'wp-last-login';
		return $columns;
	}

	/**
	 * Handle ordering by Last Login.
	 *
	 * The requested column is matched against a literal: the value comes from
	 * the request, so it must never reach the query as a key. The meta key
	 * itself is a constant, never user input.
	 *
	 * Sorting by a meta key makes WP_User_Query join the meta table, so users
	 * without the key would drop out of the list. The key is seeded for every
	 * existing user on activation and for every new user on registration,
	 * which keeps the list complete.
	 *
	 * @param WP_User_Query $user_query User query.
	 * @return WP_User_Query
	 */
	public static function order_by_last_login( $user_query ) {
		if ( isset( $user_query->query_vars['orderby'] )
			&& 'wp-last-login' === $user_query->query_vars['orderby']
		) {
			$user_query->query_vars['meta_key'] = self::META_LAST_LOGIN; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$user_query->query_vars['orderby']  = 'meta_value_num';
		}

		return $user_query;
	}
}

WPLL_Last_Login::init();

/*
 * By default the "Last Login" and "Last IP" columns are visible to
 * administrators only (users with the `manage_options` capability).
 *
 * To change that, add a filter:
 *
 *     // Allow editors as well:
 *     add_filter( 'wpll_current_user_can', function ( $can ) {
 *         return $can || current_user_can( 'edit_others_posts' );
 *     } );
 *
 *     // Disable for everyone, administrators included:
 *     add_filter( 'wpll_current_user_can', '__return_false' );
 */
