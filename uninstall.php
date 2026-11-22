<?php
/**
 * Uninstall.
 *
 * @package WordPress-last-login
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die( 'WP_UNINSTALL_PLUGIN undefined.' );
}

delete_metadata( 'user', 0, 'wp-last-login', '', true );
delete_metadata( 'user', 0, 'wp-last-ip', '', true );

/* Goodbye! Thank you for having me! */