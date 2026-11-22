<?php
/**
 * Plugin Name: WordPress Last Login
 * Description: Displays the date of the last login and IP in user lists (admins only).
 * Version:     1.2.1
 * Author:      Gnostis
 * Text Domain: wp-last-login
 * Domain Path: /lang
 *
 * @package WordPress-last-login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		// Local-only plugin: prevent update checks from remote repo.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'disable_updates' ) );
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

		add_filter( 'admin_print_styles-users.php', array( __CLASS__, 'column_style' ) );
		add_filter( 'admin_print_styles-site-users.php', array( __CLASS__, 'column_style' ) );

		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'add_sortable' ) );
		add_filter( 'manage_users-network_sortable_columns', array( __CLASS__, 'add_sortable' ) );
		add_filter( 'pre_get_users', array( __CLASS__, 'order_by_last_login' ) );
	}

	/**
	 * Activation: seed default meta for all existing users.
	 */
	public static function activate() {
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			update_user_meta( $user_id, self::META_LAST_LOGIN, 0 );
			update_user_meta( $user_id, self::META_LAST_IP, 0 );
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
		update_user_meta( $user_id, self::META_LAST_LOGIN, 0 );
		update_user_meta( $user_id, self::META_LAST_IP, 0 );
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
	 * Resolve the real client IP behind a trusted nginx reverse proxy.
	 *
	 * Priority:
	 *   1. X-Real-IP        — set by nginx to the direct client address.
	 *   2. X-Forwarded-For  — last entry is the address appended by our nginx
	 *                         (when using $proxy_add_x_forwarded_for).
	 *   3. REMOTE_ADDR      — fallback (would be nginx itself).
	 *
	 * @return string|null
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = trim( (string) $_SERVER['HTTP_X_REAL_IP'] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip    = (string) end( $parts );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = trim( (string) $_SERVER['REMOTE_ADDR'] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return null;
	}

	/**
	 * Do not offer plugin updates — this plugin is local-only.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public static function disable_updates( $transient ) {
		if ( is_object( $transient ) && isset( $transient->response ) ) {
			$basename = plugin_basename( __FILE__ );
			if ( isset( $transient->response[ $basename ] ) ) {
				unset( $transient->response[ $basename ] );
			}
		}
		return $transient;
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
		if ( 'wp-last-login' === $column_name ) {
			return self::render_last_login( (int) $user_id );
		}

		if ( 'wp-last-ip' === $column_name ) {
			return self::render_last_ip( (int) $user_id );
		}

		return $value;
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

		$format    = apply_filters( 'wpll_date_format', get_option( 'date_format' ) );
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

		$url = 'https://ipinfo.io/' . rawurlencode( $last_ip );

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
 * По умолчанию колонки "Last Login" и "Last IP" видны только администраторам
 * (пользователям с возможностью `manage_options`).
 *
 * Чтобы изменить это поведение, добавьте фильтр:
 *
 *     // Разрешить также редакторам:
 *     add_filter( 'wpll_current_user_can', function ( $can ) {
 *         return $can || current_user_can( 'edit_others_posts' );
 *     } );
 *
 *     // Полностью отключить для всех (в т.ч. для админов):
 *     add_filter( 'wpll_current_user_can', '__return_false' );
 */