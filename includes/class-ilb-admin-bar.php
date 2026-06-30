<?php
/**
 * Admin bar indicator.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Admin_Bar
 *
 * Adds an Internal Link Builder entry to the WordPress admin bar, unless the
 * "Hide the link index indicator" setting is enabled.
 */
class ILB_Admin_Bar {

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 */
	public function __construct( ILB_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
	}

	/**
	 * Adds the admin bar node.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public function add_node( $admin_bar ) {
		if ( $this->settings->get( 'hide_admin_bar' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'ilb-indicator',
				'title' => '<span class="ab-icon dashicons dashicons-admin-links" style="top:2px;"></span>' . esc_html__( 'Link Builder', 'internal-link-builder' ),
				'href'  => admin_url( 'admin.php?page=' . ILB_PAGE_SLUG ),
				'meta'  => array(
					'title' => esc_attr__( 'Internal Link Builder', 'internal-link-builder' ),
				),
			)
		);
	}
}
