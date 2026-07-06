<?php
/**
 * Self-hosted plugin updater.
 *
 * Lets WordPress offer updates for this plugin the same way it does for
 * wordpress.org plugins, but sourced from a JSON manifest you host yourself
 * (e.g. on hellogekko.nl). No third-party library and no license check.
 *
 * The manifest URL defaults to a value that can be overridden with the
 * ILB_UPDATE_URL constant (in wp-config.php) or the `ilb_update_manifest_url`
 * filter. See UPDATES.md for the manifest format and release flow.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Updater
 */
class ILB_Updater {

	/**
	 * Transient key for the cached manifest.
	 */
	const CACHE_KEY = 'ilb_update_manifest';

	/**
	 * How long a successful manifest fetch is cached.
	 */
	const CACHE_TTL = 21600; // 6 hours.

	/**
	 * How long a failed fetch is cached (avoids hammering a down endpoint).
	 */
	const CACHE_TTL_ERROR = 1800; // 30 minutes.

	/**
	 * Plugin basename, e.g. "internal-link-builder/internal-link-builder.php".
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin slug (folder name).
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Currently installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * URL of the update manifest.
	 *
	 * @var string
	 */
	private $manifest_url;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $version     Installed plugin version.
	 */
	public function __construct( $plugin_file, $version ) {
		$this->basename = plugin_basename( $plugin_file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;

		$default = 'https://hellogekko.nl/updates/internal-link-builder.json';
		if ( defined( 'ILB_UPDATE_URL' ) && ILB_UPDATE_URL ) {
			$default = ILB_UPDATE_URL;
		}

		/**
		 * Filters the URL of the plugin's update manifest.
		 *
		 * @param string $url Manifest URL.
		 */
		$this->manifest_url = (string) apply_filters( 'ilb_update_manifest_url', $default );
	}

	/**
	 * Registers the update hooks.
	 */
	public function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
	}

	/**
	 * Clears the cached manifest (e.g. right after an update completes).
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Fetches (and caches) the update manifest.
	 *
	 * @return array|null Manifest data, or null when unavailable.
	 */
	private function get_manifest() {
		if ( '' === $this->manifest_url ) {
			return null;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return isset( $cached['__error'] ) ? null : $cached;
		}

		$response = wp_remote_get(
			$this->manifest_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, array( '__error' => true ), self::CACHE_TTL_ERROR );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			set_transient( self::CACHE_KEY, array( '__error' => true ), self::CACHE_TTL_ERROR );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Injects an available update into the plugins update transient.
	 *
	 * @param mixed $transient Update transient (stdClass) or falsey.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = $this->get_manifest();
		if ( ! $manifest || empty( $manifest['download_url'] ) ) {
			return $transient;
		}

		if ( version_compare( $this->version, $manifest['version'], '>=' ) ) {
			return $transient;
		}

		$item = array(
			'id'           => $this->basename,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => (string) $manifest['version'],
			'url'          => isset( $manifest['homepage'] ) ? (string) $manifest['homepage'] : '',
			'package'      => (string) $manifest['download_url'],
			'tested'       => isset( $manifest['tested'] ) ? (string) $manifest['tested'] : '',
			'requires'     => isset( $manifest['requires'] ) ? (string) $manifest['requires'] : '',
			'requires_php' => isset( $manifest['requires_php'] ) ? (string) $manifest['requires_php'] : '',
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->basename ] = (object) $item;

		return $transient;
	}

	/**
	 * Supplies the "View details" information for this plugin.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$manifest = $this->get_manifest();
		if ( ! $manifest ) {
			return $result;
		}

		$info = array(
			'name'          => isset( $manifest['name'] ) ? (string) $manifest['name'] : 'Internal Link Builder',
			'slug'          => $this->slug,
			'version'       => (string) $manifest['version'],
			'author'        => isset( $manifest['author'] ) ? (string) $manifest['author'] : '',
			'homepage'      => isset( $manifest['homepage'] ) ? (string) $manifest['homepage'] : '',
			'requires'      => isset( $manifest['requires'] ) ? (string) $manifest['requires'] : '',
			'tested'        => isset( $manifest['tested'] ) ? (string) $manifest['tested'] : '',
			'requires_php'  => isset( $manifest['requires_php'] ) ? (string) $manifest['requires_php'] : '',
			'last_updated'  => isset( $manifest['last_updated'] ) ? (string) $manifest['last_updated'] : '',
			'download_link' => isset( $manifest['download_url'] ) ? (string) $manifest['download_url'] : '',
			'sections'      => ( isset( $manifest['sections'] ) && is_array( $manifest['sections'] ) ) ? $manifest['sections'] : array(),
		);

		return (object) $info;
	}
}
