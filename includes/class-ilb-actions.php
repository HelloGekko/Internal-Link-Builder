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
	 * Cancels all pending scheduled actions in the plugin's group.
	 */
	public function ajax_cancel_schedules() {
		$this->guard();

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Action Scheduler is not available; there is nothing to cancel.', 'internal-link-builder' ),
				)
			);
		}

		as_unschedule_all_actions( '', array(), self::SCHEDULE_GROUP );

		wp_send_json_success(
			array(
				'message' => __( 'All pending scheduled actions have been cancelled.', 'internal-link-builder' ),
			)
		);
	}

	/**
	 * Placeholder for the collation-fixing maintenance tool.
	 *
	 * The statistics tables are introduced together with the linking engine;
	 * until then this reports that there is nothing to repair.
	 */
	public function ajax_fix_collations() {
		$this->guard();

		/**
		 * Fires when the "Fix collations" maintenance tool runs. The linking
		 * engine hooks here to align table collations once its tables exist.
		 */
		do_action( 'ilb_fix_collations' );

		wp_send_json_success(
			array(
				'message' => __( 'Collation check complete. No statistics tables require fixing yet.', 'internal-link-builder' ),
			)
		);
	}
}
