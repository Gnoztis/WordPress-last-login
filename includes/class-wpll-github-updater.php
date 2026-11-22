<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism, sourced from GitHub
 * releases and tags of Gnoztis/WordPress-last-login.
 *
 * The source of truth is the highest version found in the latest GitHub
 * release and in the tag list, whichever is newer: a tag can be published
 * without a matching release, and then the release alone would never offer an
 * update. WordPress sees the new version, shows it under Plugins and updates
 * from the package of the winning source. No third-party libraries and no
 * write requests to the repository.
 */
final class WPLL_GitHub_Updater {

	/** GitHub repository the updates come from. */
	private const REPO = 'Gnoztis/WordPress-last-login';

	/**
	 * Plugin slug in the repository — also the archive directory name on
	 * GitHub. Never use it as an update transient key: the directory of the
	 * installed plugin can have another name, see basename().
	 */
	private const SLUG = 'WordPress-last-login';

	/** Transient holding the result of the last successful update check. */
	private const UPDATE_TRANSIENT = 'wpll_github_release';

	/** Transient holding the failure marker of the last update check. */
	private const UPDATE_ERROR_TRANSIENT = 'wpll_github_release_error';

	/** A successful update check is cached for six hours. */
	private const UPDATE_CACHE_TTL = 21600;

	/** A failed update check is retried after fifteen minutes. */
	private const UPDATE_ERROR_TTL = 900;

	/** Cache of the plugin header values, read once per request. */
	private static ?array $header = null;

	/**
	 * Registers the update hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_check' ) );
		add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'action_links' ) );
	}

	/**
	 * Basename of the installed main plugin file, e.g.
	 * "WordPress-last-login/wp-last-login.php".
	 *
	 * Computed at runtime, never taken from a constant: the folder is
	 * whatever the site has. A GitHub archive unpacks into
	 * Gnoztis-WordPress-last-login-<sha>, a manual upload can use any name.
	 * WordPress keys update_plugins->response by this basename and counts
	 * every entry for the admin badge, but lists only entries whose key
	 * matches a file from get_plugins() — so a hard-coded key shows an
	 * "update available" badge with an empty update list.
	 */
	public static function basename(): string {
		$basename = plugin_basename( WPLL_PLUGIN_FILE );

		if ( '' === $basename ) {
			// plugin_basename() returns the path relative to the plugin
			// directory; an empty result means the main file was not inside
			// it, so fall back to the documented layout.
			$basename = self::SLUG . '/' . wp_basename( WPLL_PLUGIN_FILE );
		}

		return $basename;
	}

	/**
	 * Directory of the installed plugin — the slug WordPress knows it by.
	 *
	 * Empty when the main file sits directly in wp-content/plugins.
	 */
	public static function dirname(): string {
		$dir = dirname( self::basename() );

		return ( '.' === $dir || '' === $dir ) ? '' : $dir;
	}

	/* ============================================================
	 *  Update plumbing
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a version
	 * newer than the installed one.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		// Entries written by an older version of this code under a key that no
		// longer matches the installed file: WordPress counts them in the
		// badge but get_plugin_updates() never lists them.
		$this->drop_stale_entries( $transient );

		$installed = $this->installed_version();

		// Same request that installs an update: the files on disk are new, the
		// entry built before the swap still offers the version just installed.
		$this->drop_phantom_entry( $transient, $installed );

		$release = $this->get_release();

		// Both versions are needed. The constant is trustworthy between
		// requests, the header is the only trustworthy source inside the
		// request that replaces the files; injecting unless the release beats
		// both guarantees no update is offered for a version already on disk.
		if ( null === $release
			|| ! version_compare( $release['version'], WPLL_VERSION, '>' )
			|| ! version_compare( $release['version'], $installed, '>' ) ) {
			return $transient;
		}

		$basename = self::basename();

		$transient->response[ $basename ] = (object) array(
			'id'            => $basename,
			'slug'          => self::dirname(),
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Version of the plugin that is actually on disk.
	 *
	 * WPLL_VERSION is defined when the main file loads, so inside the request
	 * that installs an update it still holds the previous version while the
	 * file on disk already contains the new one. The header is the source of
	 * truth; the constant is only a fallback for an unreadable file.
	 *
	 * Not cached on purpose: the value has to be re-read after the upgrader
	 * replaces the files, and a per-request cache would keep the stale one.
	 */
	private function installed_version(): string {
		$data = function_exists( 'get_file_data' )
			? get_file_data( WPLL_PLUGIN_FILE, array( 'Version' => 'Version' ), 'plugin' )
			: array();

		$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

		return '' === $version ? WPLL_VERSION : $version;
	}

	/**
	 * Removes our own response entry when it offers what is already installed.
	 *
	 * Core rewrites the update_plugins transient in the request that replaces
	 * the files. An entry built there advertises the version that has just
	 * been installed, so the site offers the same update a second time; it
	 * would stay for up to twelve hours.
	 *
	 * @param object $transient
	 * @param string $installed Version from the plugin header on disk.
	 */
	private function drop_phantom_entry( $transient, string $installed ): void {
		$basename = self::basename();

		if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
			return;
		}

		$entry = $transient->response[ $basename ];
		$new   = isset( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

		if ( '' === $new ) {
			unset( $transient->response[ $basename ] );
			return;
		}

		// Not newer than what is on disk, or not newer than the running code:
		// in both cases there is nothing to install.
		if ( version_compare( $new, $installed, '<=' ) || version_compare( $new, WPLL_VERSION, '<=' ) ) {
			unset( $transient->response[ $basename ] );
		}
	}

	/**
	 * Removes update entries that point at this plugin's main file while
	 * sitting under a key other than the installed basename.
	 *
	 * WordPress builds the "update available" badge from
	 * count( $transient->response ), but get_plugin_updates() only looks up
	 * keys that match a file returned by get_plugins(). A stale key therefore
	 * shows a badge together with an empty update list.
	 *
	 * @param object $transient
	 */
	private function drop_stale_entries( $transient ): void {
		$basename = self::basename();
		$main     = wp_basename( WPLL_PLUGIN_FILE );

		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}

			foreach ( $transient->$bucket as $key => $entry ) {
				if ( (string) $key === $basename || ! is_object( $entry ) || empty( $entry->plugin ) ) {
					continue;
				}
				if ( wp_basename( (string) $entry->plugin ) === $main ) {
					unset( $transient->$bucket[ $key ] );
				}
			}
		}
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( self::dirname() !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( null === $release ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) array(
			'name'          => 'WordPress Last Login',
			'slug'          => self::dirname(),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $header['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => esc_html__( 'Показывает дату и IP последнего входа каждого пользователя в списке пользователей (только для администраторов).', 'wp-last-login' ),
				'changelog'   => '' !== $release['body'] ? wpautop( $release['body'] ) : esc_html__( 'Описание изменений в релизе не заполнено.', 'wp-last-login' ),
			),
		);
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-WordPress-last-login-1a2b3c4 — rename it to the directory the
	 * plugin is actually installed in, otherwise WordPress unpacks the update
	 * next to the original and the site ends up with two copies.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || self::basename() !== (string) $hook_extra['plugin'] ) {
			return $source;
		}

		$dir = self::dirname();
		if ( '' === $dir ) {
			return $source;
		}

		if ( ! is_dir( $source ) || ! is_file( trailingslashit( $source ) . wp_basename( WPLL_PLUGIN_FILE ) ) ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $dir;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Resets the cached check after an upgrade so the site does not keep
	 * advertising the version it has just installed.
	 *
	 * The release cache is reset as well: an answer cached before the update
	 * can still carry the previous "tested"/"new version" pair, and the next
	 * run has to read both from the repository again.
	 *
	 * @param mixed $upgrader
	 * @param array $hook_extra
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = array() ): void {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			if ( ! in_array( self::basename(), $hook_extra['plugins'], true ) ) {
				return;
			}
		}
		$this->flush();
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'wpll-force-check', '1', admin_url( 'plugins.php' ) ),
			'wpll_force_check'
		);

		$links               = (array) $links;
		$links['wpll_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'wp-last-login' ) . '</a>';

		return $links;
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		if ( ! isset( $_GET['wpll-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'wpll_force_check' );

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Drops every cached value of this class.
	 */
	private function flush(): void {
		delete_transient( self::UPDATE_TRANSIENT );
		delete_transient( self::UPDATE_ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		self::$header = null;
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data: the highest version across the release and the tag
	 * list. The whole answer is cached for six hours, a failed check is
	 * retried after fifteen minutes.
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::UPDATE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		if ( get_transient( self::UPDATE_ERROR_TRANSIENT ) ) {
			return null;
		}

		$release = $this->fetch_latest();

		if ( null === $release ) {
			set_transient( self::UPDATE_ERROR_TRANSIENT, 1, self::UPDATE_ERROR_TTL );
			return null;
		}

		set_transient( self::UPDATE_TRANSIENT, $release, self::UPDATE_CACHE_TTL );

		return $release;
	}

	/**
	 * Picks the higher of the two sources.
	 *
	 * Both requests are always made: a repository can keep an older release
	 * while a newer tag already exists, and "release first, otherwise tag"
	 * would never offer that tag. On equal versions the release wins, because
	 * it carries the release notes.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( null === $release ) {
			return $tag;
		}
		if ( null === $tag ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	/**
	 * Candidate from the latest GitHub release, with the package of that
	 * release.
	 */
	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ) );
		if ( null === $body ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( '' === $version ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( '' === $package ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Candidate from the tag list: the highest semver tag and its zipball.
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get( sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', self::REPO ) );
		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( '' === $version ) {
				continue;
			}
			if ( '' === $best_version || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( '' === $best_version || '' === $best_tag ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf( 'https://api.github.com/repos/%s/zipball/%s', self::REPO, rawurlencode( $best_tag ) )
		);

		if ( '' === $package ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return array(
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $this->tested_version( $header['tested'] ),
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		);
	}

	/**
	 * "Tested up to" turned into the value WordPress compares with.
	 *
	 * The header holds a branch claim ("7.1"), while the update screen compares
	 * the stored value with the full running version ("7.1.5"):
	 * version_compare( $update->tested, $cur_wp_version, '>=' ). A branch claim
	 * is always lower than a patch release, which is why a bare "7.1" still
	 * renders "Compatibility with WordPress 7.1.5: Not tested".
	 *
	 * api.wordpress.org resolves this by serving the patch release of the
	 * declared branch: readmes say "Tested up to: 7.1" and the API answers
	 * "7.1.5". The same expansion is done here, so the claim covers the running
	 * patch release of the branch the author declared.
	 *
	 * @param string $tested Header value, e.g. "7.1"; empty when absent.
	 * @return string Empty when the header is absent, otherwise a version.
	 */
	private function tested_version( string $tested ): string {
		$tested = trim( $tested );
		if ( '' === $tested ) {
			return '';
		}

		$running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
		if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
			$running = (string) $GLOBALS['wp_version'];
		}
		$running = trim( (string) preg_replace( '/-.*/', '', $running ) );
		if ( '' === $running ) {
			return $tested;
		}

		$tested_parts  = explode( '.', $tested );
		$running_parts = explode( '.', $running );

		// Already as specific as the running version, or more so.
		if ( count( $tested_parts ) >= count( $running_parts ) ) {
			return $tested;
		}

		// Same branch: the claim covers the running patch release as well.
		if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		$args = array(
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => array(
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => self::SLUG . '/' . WPLL_VERSION,
				'Content-Type' => 'application/json',
			),
		);

		$token = $this->token();
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user input;
		// scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from a constant.
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only: the package URL must not point at an
	 * arbitrary host.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' );
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Strict semver: "1.2.3" and "v1.2.3" only; prereleases and four-part
	 * versions are not accepted.
	 */
	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * GitHub token from a constant or a filter; empty when none is set.
	 */
	private function token(): string {
		$token = defined( 'WPLL_GITHUB_TOKEN' ) ? (string) WPLL_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'wpll_github_token', $token );

		return trim( $token );
	}

	/**
	 * Requires at least / Requires PHP / Tested up to from the plugin header.
	 *
	 * `tested` and `requires` are different headers and must not be mixed:
	 * WordPress compares `tested` with the installed version to decide whether
	 * the update row says "compatible" or "untested".
	 *
	 * @return array{tested:string,requires:string,requires_php:string}
	 */
	private function header_data(): array {
		if ( null === self::$header ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					WPLL_PLUGIN_FILE,
					array(
						'Tested up to'      => 'Tested up to',
						'Requires at least' => 'Requires at least',
						'Requires PHP'      => 'Requires PHP',
					),
					'plugin'
				)
				: array();

			self::$header = array(
				'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
			);
		}

		return self::$header;
	}
}
