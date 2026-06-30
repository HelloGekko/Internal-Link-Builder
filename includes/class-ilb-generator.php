<?php
/**
 * Index/link-graph generator.
 *
 * Rebuilds the link graph (wp_ilb_links) in batches. Each source post is
 * processed in a fixed order so incoming-link counts accumulate deterministically.
 * Uses Action Scheduler when available and falls back to WP-Cron otherwise.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Generator
 */
class ILB_Generator {

	/**
	 * Action that kicks off a full regeneration.
	 */
	const HOOK_KICKOFF = 'ilb_generate_index';

	/**
	 * Action that processes a single batch of sources.
	 */
	const HOOK_BATCH = 'ilb_generate_index_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'internal-link-builder';

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Engine (used to compute links per source).
	 *
	 * @var ILB_Engine
	 */
	private $engine;

	/**
	 * Link graph handler.
	 *
	 * @var ILB_Links
	 */
	private $links;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 * @param ILB_Engine   $engine   Engine.
	 * @param ILB_Links    $links    Link graph handler.
	 */
	public function __construct( ILB_Settings $settings, ILB_Engine $engine, ILB_Links $links ) {
		$this->settings = $settings;
		$this->engine   = $engine;
		$this->links    = $links;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( self::HOOK_KICKOFF, array( $this, 'run_kickoff' ) );
		add_action( self::HOOK_BATCH, array( $this, 'run_batch' ), 10, 1 );

		// Automatic mode: any change that affects the index schedules a rebuild.
		if ( 'automatic' === $this->settings->get( 'index_generation_mode' ) ) {
			add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
			add_action( 'deleted_post', array( $this, 'schedule' ) );
			add_action( 'ilb_keywords_saved', array( $this, 'schedule' ) );
			add_action( 'update_option_' . ILB_SETTINGS_OPTION, array( $this, 'schedule' ) );
		}
	}

	/**
	 * Schedules a rebuild after a post in a whitelisted type is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$post_types = (array) $this->settings->get( 'whitelist_post_types' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		$this->schedule();
	}

	/**
	 * Schedules a debounced full regeneration (coalesces rapid changes).
	 */
	public function schedule() {
		if ( $this->kickoff_pending() ) {
			return;
		}

		$delay = (int) apply_filters( 'ilb_generation_debounce', 30 );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_KICKOFF, array(), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + $delay, self::HOOK_KICKOFF );
	}

	/**
	 * Whether a kickoff is already scheduled.
	 *
	 * @return bool
	 */
	private function kickoff_pending() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return (bool) as_next_scheduled_action( self::HOOK_KICKOFF, null, self::GROUP );
		}

		return (bool) wp_next_scheduled( self::HOOK_KICKOFF );
	}

	/**
	 * Starts a full regeneration: clears the graph and enqueues the first batch.
	 */
	public function run_kickoff() {
		$this->links->clear_all();
		$this->schedule_batch( 0 );
	}

	/**
	 * Processes one batch of source posts.
	 *
	 * @param int $offset Offset into the ordered source list.
	 */
	public function run_batch( $offset = 0 ) {
		$offset     = max( 0, (int) $offset );
		$batch_size = $this->batch_size();

		$post_ids = $this->source_ids( $offset, $batch_size );
		if ( empty( $post_ids ) ) {
			/**
			 * Fires when a full link-graph regeneration has completed.
			 */
			do_action( 'ilb_generation_complete' );
			return;
		}

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$links = $this->engine->compute_links( $post );
			$this->links->replace_for_source( $post->ID, 'post', $links );
		}

		// Chain the next batch only if this one was full.
		if ( count( $post_ids ) === $batch_size ) {
			$this->schedule_batch( $offset + $batch_size );
		} else {
			do_action( 'ilb_generation_complete' );
		}
	}

	/**
	 * Returns the configured batch size, clamped to the supported range.
	 *
	 * @return int
	 */
	private function batch_size() {
		return min( 250, max( 1, (int) $this->settings->get( 'batch_size' ) ) );
	}

	/**
	 * Returns a page of whitelisted source post IDs, ordered by ID.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 * @return int[]
	 */
	private function source_ids( $offset, $limit ) {
		$post_types = (array) $this->settings->get( 'whitelist_post_types' );
		if ( empty( $post_types ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Schedules a single batch action.
	 *
	 * @param int $offset Offset for the batch.
	 */
	private function schedule_batch( $offset ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_BATCH, array( $offset ), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + 1, self::HOOK_BATCH, array( $offset ) );
	}

	/**
	 * Cancels all pending generation work (Action Scheduler and WP-Cron).
	 */
	public static function cancel_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK_KICKOFF );

		// WP-Cron batch events carry an offset argument, so clear by timestamp.
		$crons = _get_cron_array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				if ( isset( $hooks[ self::HOOK_BATCH ] ) ) {
					foreach ( $hooks[ self::HOOK_BATCH ] as $event ) {
						wp_unschedule_event( $timestamp, self::HOOK_BATCH, $event['args'] );
					}
				}
			}
		}
	}
}
