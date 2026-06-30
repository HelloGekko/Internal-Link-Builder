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
	}

	/**
	 * Loads required files.
	 */
	private function includes() {
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-settings.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-admin-bar.php';
		require_once ILB_PLUGIN_DIR . 'includes/class-ilb-actions.php';

		if ( is_admin() ) {
			require_once ILB_PLUGIN_DIR . 'includes/class-ilb-admin.php';
		}
	}

	/**
	 * Instantiates the plugin components.
	 */
	private function init_components() {
		$this->settings = new ILB_Settings();
		$this->settings->hooks();

		$this->admin_bar = new ILB_Admin_Bar( $this->settings );
		$this->actions   = new ILB_Actions( $this->settings );

		if ( is_admin() ) {
			$this->admin = new ILB_Admin( $this->settings );
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
	 * Activation callback. Seeds default settings when none exist.
	 */
	public function activate() {
		if ( false === get_option( ILB_SETTINGS_OPTION, false ) ) {
			add_option( ILB_SETTINGS_OPTION, ILB_Settings::defaults() );
		}
	}

	/**
	 * Deactivation callback. Intentionally non-destructive.
	 */
	public function deactivate() {
		// No-op for now. Data removal is handled in uninstall.php and
		// respects the "keep data on uninstall" setting.
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
