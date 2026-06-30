<?php
/**
 * Maintenance actions exposed on the Actions tab (AJAX handlers).
 *
 * The linking engine is not implemented yet, so these handlers perform the
 * safe, available parts of each task (e.g. cancelling Action Scheduler actions
 * in our group) and report back gracefully when a subsystem is absent.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Actions
 */
class ILB_Actions {

	/**
	 * Nonce action shared with the admin page.
	 */
	const NONCE_ACTION = 'ilb_actions';

	/**
	 * Action Scheduler group used by the plugin.
	 */
	const SCHEDULE_GROUP = 'internal-link-builder';

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

		add_action( 'wp_ajax_ilb_cancel_schedules', array( $this, 'ajax_cancel_schedules' ) );
		add_action( 'wp_ajax_ilb_fix_collations', array( $this, 'ajax_fix_collations' ) );
	}

	/**
	 * Verifies the request nonce and capability, dying on failure.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'internal-link-builder' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Cancels all pending generation work (Action Scheduler and WP-Cron).
	 */
	public function ajax_cancel_schedules() {
		$this->guard();

		ILB_Generator::cancel_all();

		wp_send_json_success(
			array(
				'message' => __( 'All pending scheduled actions have been cancelled.', 'internal-link-builder' ),
			)
		);
	}

	/**
	 * Converts the plugin's tables to the database's default collation.
	 */
	public function ajax_fix_collations() {
		$this->guard();

		$index_ok = ILB_Index::fix_collation();
		$links_ok = ILB_Links::fix_collation();

		/**
		 * Fires after the "Fix collations" maintenance tool has run.
		 */
		do_action( 'ilb_fix_collations' );

		if ( $index_ok && $links_ok ) {
			wp_send_json_success(
				array(
					'message' => __( 'Collations have been aligned for the keyword index and link tables.', 'internal-link-builder' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'Could not align all table collations. Please check your database permissions.', 'internal-link-builder' ),
			)
		);
	}
}
