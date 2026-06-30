<?php
/**
 * Main plugin bootstrap.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Plugin
 *
 * Loads dependencies and wires up the plugin's components.
 */
final class ILB_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var ILB_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	public $settings;

	/**
	 * Admin UI handler.
	 *
	 * @var ILB_Admin|null
	 */
	public $admin = null;

	/**
	 * Admin bar handler.
	 *
	 * @var ILB_Admin_Bar
	 */
	public $admin_bar;

	/**
	 * Actions (AJAX maintenance tools) handler.
	 *
	 * @var ILB_Actions
	 */
	public $actions;

	/**
	 * Keyword index handler.
	 *
	 * @var ILB_Index
	 */
	public $index;

	/**
	 * Keyword storage handler.
	 *
	 * @var ILB_Keywords
	 */
	public $keywords;

	/**
	 * Post metabox handler.
	 *
	 * @var ILB_Metabox|null
	 */
	public $metabox = null;

	/**
	 * Term fields handler.
	 *
	 * @var ILB_Term_Fields|null
	 */
	public $term_fields = null;

	/**
	 * Front-end linking engine.
	 *
	 * @var ILB_Engine
	 */
	public $engine;

	/**
	 * Link graph / statistics handler.
	 *
	 * @var ILB_Links
	 */
	public $links;

	/**
	 * Index/link-graph generator.
	 *
	 * @var ILB_Generator
	 */
	public $generator;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @return ILB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor: load dependencies and register hooks.
	 */
	private function __construct() {
		$this->includes();
		$this->init_components();
		$this->register_lifecycle_hooks();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );

		// Create tables when a new site is added on multisite.
		add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 20 );
	}

	/**
	 * Loads required files.
	 */
	private function includes() {
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-settings.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-index.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-links.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-keywords.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-engine.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-generator.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-admin-bar.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-actions.php';

		if ( is_admin() ) {
			require_once ILB_PLUGIN_DIR . 'includes/class-ilb-admin.php';
			require_once ILB_PLUGIN_DIR . 'includes/class-ilb-metabox.php';
			require_once ILB_PLUGIN_DIR . 'includes/class-ilb-term-fields.php';
		}
	}

	/**
	 * Instantiates the plugin components.
	 */
	private function init_components() {
		$this->settings = new ILB_Settings();
		$this->settings->hooks();

		$this->index     = new ILB_Index();
		$this->links     = new ILB_Links();
		$this->keywords  = new ILB_Keywords( $this->settings, $this->index );
		$this->engine    = new ILB_Engine( $this->settings, $this->index, $this->keywords, $this->links );
		$this->generator = new ILB_Generator( $this->settings, $this->engine, $this->links );
		$this->generator->hooks();

		$this->admin_bar = new ILB_Admin_Bar( $this->settings );
		$this->actions   = new ILB_Actions( $this->settings );

		if ( is_admin() ) {
			$this->admin       = new ILB_Admin( $this->settings );
			$this->metabox     = new ILB_Metabox( $this->settings, $this->keywords );
			$this->term_fields = new ILB_Term_Fields( $this->settings, $this->keywords );
		}
	}

	/**
	 * Registers activation/deactivation hooks.
	 */
	private function register_lifecycle_hooks() {
		register_activation_hook( ILB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( ILB_PLUGIN_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 0,
					'no_found_rows' => true,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->install_site();
				restore_current_blog();
			}

			return;
		}

		$this->install_site();
	}

	/**
	 * Installs tables and default settings for the current site.
	 */
	private function install_site() {
		if ( false === get_option( ILB_SETTINGS_OPTION, false ) ) {
			add_option( ILB_SETTINGS_OPTION, ILB_Settings::defaults() );
		}

		ILB_Index::install();
		ILB_Links::install();
	}

	/**
	 * Installs tables for a newly created multisite blog.
	 *
	 * @param WP_Site $new_site New site object.
	 */
	public function on_new_site( $new_site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( ILB_PLUGIN_BASENAME ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		$this->install_site();
		restore_current_blog();
	}

	/**
	 * Runs deferred table installs/upgrades when the schema version changed.
	 *
	 * Ensures plugin updates that add or change tables take effect without a
	 * manual deactivate/reactivate.
	 */
	public function maybe_upgrade() {
		if ( get_option( ILB_Index::DB_VERSION_OPTION ) === ILB_Index::DB_VERSION ) {
			return;
		}

		ILB_Index::install();
		ILB_Links::install();
	}

	/**
	 * Deactivation callback. Intentionally non-destructive.
	 */
	public function deactivate() {
		// Stop any pending background generation; data removal is handled in
		// uninstall.php and respects the "keep data on uninstall" setting.
		ILB_Generator::cancel_all();
	}

	/**
	 * Loads the plugin text domain for translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'internal-link-builder',
			false,
			dirname( ILB_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
