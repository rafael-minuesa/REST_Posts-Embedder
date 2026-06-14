<?php
/**
 * Self-hosted update checker for REST Posts Embedder.
 *
 * Polls a plain-JSON manifest hosted on prowoos.com — no auth, no GitHub API
 * rate limits, no embedded tokens. Hooks into WordPress's native plugin update
 * system so updates appear in wp-admin like any wp.org plugin. Mirrors the
 * WC Multilang updater.
 *
 * Declared in the global namespace (not RestPostsEmbedder) so all WordPress
 * core function calls resolve directly.
 *
 * Manifest format (example):
 *   {
 *     "version":      "3.6.1",
 *     "package":      "https://prowoos.com/wp-content/uploads/rpe-updates/restpostsembedder-3.6.1.zip",
 *     "tested":       "6.9",
 *     "requires":     "5.0",
 *     "requires_php": "7.4",
 *     "changelog":    "## [3.6.1] ...",
 *     "updated":      "2026-06-14T11:00:00Z",
 *     "homepage":     "https://github.com/rafael-minuesa/REST_Posts-Embedder"
 *   }
 *
 * @package restpostsembedder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Posts_Embedder_Update_Checker {

	const SLUG         = 'restpostsembedder';
	const MANIFEST_URL = 'https://prowoos.com/wp-content/uploads/rpe-updates/restpostsembedder.json';
	const CACHE_KEY    = 'rest_posts_embedder_update_data';
	const CACHE_EXPIRY = 43200; // 12 hours.
	const HOMEPAGE     = 'https://github.com/rafael-minuesa/REST_Posts-Embedder';

	/**
	 * Plugin basename (e.g. REST_Posts-Embedder/restpostsembedder.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * @param string $plugin_basename Result of plugin_basename( __FILE__ ) from the main file.
	 */
	public function __construct( $plugin_basename ) {
		$this->plugin_basename = $plugin_basename;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

		// Force-check: drop our cache and WP's update_plugins transient so a
		// Plugins-page refresh immediately re-fetches.
		if ( is_admin() && isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_transient( self::CACHE_KEY );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Manifest URL — filterable so a site can point at a staging manifest.
	 *
	 * @return string
	 */
	private function manifest_url() {
		return apply_filters( 'rest_posts_embedder_update_manifest_url', self::MANIFEST_URL );
	}

	/**
	 * Inject a newer version into WP's update transient.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_data();
		if ( ! $remote || empty( $remote['version'] ) ) {
			return $transient;
		}

		$current_version = isset( $transient->checked[ $this->plugin_basename ] )
			? $transient->checked[ $this->plugin_basename ]
			: REST_POSTS_EMBEDDER_VERSION;

		if ( version_compare( $remote['version'], $current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'         => self::SLUG,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $remote['version'],
				'url'          => isset( $remote['homepage'] ) ? $remote['homepage'] : self::HOMEPAGE,
				'package'      => isset( $remote['package'] ) ? $remote['package'] : '',
				'tested'       => isset( $remote['tested'] ) ? $remote['tested'] : '',
				'requires_php' => isset( $remote['requires_php'] ) ? $remote['requires_php'] : '7.4',
				'requires'     => isset( $remote['requires'] ) ? $remote['requires'] : '5.0',
			);
		} else {
			// Tell WP we checked and it's up to date (prevents a WP.org lookup).
			$transient->no_update[ $this->plugin_basename ] = (object) array(
				'slug'        => self::SLUG,
				'plugin'      => $this->plugin_basename,
				'new_version' => $current_version,
				'url'         => self::HOMEPAGE,
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View Details" popup.
	 *
	 * @param false|object $result
	 * @param string       $action
	 * @param object       $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$remote = $this->get_remote_data();
		if ( ! $remote || empty( $remote['version'] ) ) {
			return $result;
		}

		$changelog = '';
		if ( ! empty( $remote['changelog'] ) ) {
			$body      = esc_html( $remote['changelog'] );
			$body      = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $body );
			$body      = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $body );
			$body      = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body );
			$body      = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $body );
			$body      = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $body );
			$changelog = nl2br( $body );
		}

		return (object) array(
			'name'          => 'REST Posts Embedder',
			'slug'          => self::SLUG,
			'version'       => $remote['version'],
			'author'        => '<a href="https://github.com/rafael-minuesa">Rafael Minuesa</a>',
			'homepage'      => isset( $remote['homepage'] ) ? $remote['homepage'] : self::HOMEPAGE,
			'requires'      => isset( $remote['requires'] ) ? $remote['requires'] : '5.0',
			'tested'        => isset( $remote['tested'] ) ? $remote['tested'] : '',
			'requires_php'  => isset( $remote['requires_php'] ) ? $remote['requires_php'] : '7.4',
			'last_updated'  => isset( $remote['updated'] ) ? $remote['updated'] : '',
			'download_link' => isset( $remote['package'] ) ? $remote['package'] : '',
			'sections'      => array(
				'description' => 'Embed posts from any WordPress site via the REST API using the [posts_embedder] shortcode. Locale-aware byline, featured images, multi-source support, and a configurable endpoint.',
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Clear cache after plugin upgrades.
	 */
	public function clear_cache( $upgrader, $hook_extra ) {
		if (
			isset( $hook_extra['action'], $hook_extra['type'] ) &&
			'update' === $hook_extra['action'] &&
			'plugin' === $hook_extra['type']
		) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Fetch the manifest (cached 12h). Returns false on any error, so a
	 * manifest-server outage never announces a phantom update.
	 *
	 * @return array|false
	 */
	private function get_remote_data() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( $this->manifest_url(), array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'REST-Posts-Embedder/' . REST_POSTS_EMBEDDER_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return false;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_EXPIRY );
		return $data;
	}
}
