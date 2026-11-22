<?php
/**
 * Uninstall: removes everything the plugin stores.
 *
 * The login meta lives in the user meta table of every site of the network,
 * the update-check caches are transients of every site, so both are removed
 * for each site.
 *
 * @package WordPress-last-login
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'wpll_uninstall_site' ) ) {
	/**
	 * Removes the plugin data of the current site.
	 *
	 * Keys are spelled out: the plugin file is not loaded during uninstall,
	 * so its constants are not available here.
	 */
	function wpll_uninstall_site(): void {
		delete_metadata( 'user', 0, 'wp-last-login', '', true );
		delete_metadata( 'user', 0, 'wp-last-ip', '', true );

		delete_transient( 'wpll_github_release' );
		delete_transient( 'wpll_github_release_error' );
	}
}

if ( is_multisite() ) {
	$wpll_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $wpll_sites as $wpll_site_id ) {
		switch_to_blog( (int) $wpll_site_id );
		wpll_uninstall_site();
		restore_current_blog();
	}
} else {
	wpll_uninstall_site();
}
