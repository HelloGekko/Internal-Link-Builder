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
	 * Option storing the current generation progress.
	 */
	const STATUS_OPTION = 'ilb_generation_status';

	/**
	 * Number of sources processed per foreground (browser-driven) step.
	 */
	const FOREGROUND_CHUNK = 20;

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
		add_action( self::HOOK_BATCH, array( $this, 'run_batch' ), 10, 2 );

		add_action( 'wp_ajax_ilb_run_generation', array( $this, 'ajax_run_generation' ) );
		add_action( 'wp_ajax_ilb_index_status', array( $this, 'ajax_index_status' ) );

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
		$this->set_status(
			array(
				'running'   => 1,
				'processed' => 0,
				'total'     => $this->source_total(),
				'updated'   => time(),
			)
		);
		$this->schedule_batch( 'post', 0 );
	}

	/**
	 * Processes one batch of sources.
	 *
	 * Posts are processed first (phase "post"), then term descriptions (phase
	 * "term"). Sources within a phase are processed in a fixed order so
	 * incoming-link counts accumulate deterministically.
	 *
	 * @param string $phase  Source phase: 'post' or 'term'.
	 * @param int    $offset Offset into the ordered source list.
	 */
	public function run_batch( $phase = 'post', $offset = 0 ) {
		$phase      = ( 'term' === $phase ) ? 'term' : 'post';
		$offset     = max( 0, (int) $offset );
		$batch_size = $this->batch_size();

		$processed = ( 'term' === $phase )
			? $this->process_term_batch( $offset, $batch_size )
			: $this->process_post_batch( $offset, $batch_size );

		$base = ( 'term' === $phase ) ? $this->count_posts() : 0;
		$this->update_progress( $base + $offset + $processed );

		if ( $processed === $batch_size ) {
			// This phase has more work.
			$this->schedule_batch( $phase, $offset + $batch_size );
			return;
		}

		if ( 'post' === $phase ) {
			// Posts done; start the term phase.
			$this->schedule_batch( 'term', 0 );
			return;
		}

		$this->finish_status();

		/**
		 * Fires when a full link-graph regeneration has completed.
		 */
		do_action( 'ilb_generation_complete' );
	}

	/**
	 * Processes a batch of post sources.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Batch size.
	 * @return int Number of sources processed.
	 */
	private function process_post_batch( $offset, $limit ) {
		$post_ids = $this->source_ids( $offset, $limit );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$links = $this->engine->compute_links( $post );
			$this->links->replace_for_source( $post->ID, 'post', $links );
		}

		return count( $post_ids );
	}

	/**
	 * Processes a batch of term sources (their descriptions).
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Batch size.
	 * @return int Number of sources processed.
	 */
	private function process_term_batch( $offset, $limit ) {
		$term_ids = $this->term_source_ids( $offset, $limit );

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$links = $this->engine->compute_links_for_term( $term );
			$this->links->replace_for_source( $term->term_id, 'term', $links );
		}

		return count( $term_ids );
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
	 * Returns a page of whitelisted term source IDs (terms with a description),
	 * ordered by term ID.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 * @return int[]
	 */
	private function term_source_ids( $offset, $limit ) {
		$taxonomies = (array) $this->settings->get( 'whitelist_taxonomies' );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'number'     => $limit,
				'offset'     => $offset,
			)
		);

		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Foreground (browser-driven) generation + progress
	 * -------------------------------------------------------------------------
	 */

	/**
	 * AJAX: drives a browser-driven generation, one chunk per request.
	 *
	 * Expects a "step" of 'begin' (clear + count) or 'continue' (process the
	 * given phase/offset). Returns the next phase/offset and progress so the
	 * admin JS can render a progress bar without relying on cron timing.
	 */
	public function ajax_run_generation() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_ajax().
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'begin';

		if ( 'begin' === $step ) {
			// Background scheduling is redundant while running in the foreground.
			self::cancel_all();
			$this->links->clear_all();
			$total = $this->source_total();
			$this->set_status(
				array(
					'running'   => 1,
					'processed' => 0,
					'total'     => $total,
					'updated'   => time(),
				)
			);

			wp_send_json_success(
				array(
					'phase'     => 'post',
					'offset'    => 0,
					'processed' => 0,
					'total'     => $total,
					'done'      => false,
				)
			);
		}

		$phase  = ( isset( $_POST['phase'] ) && 'term' === $_POST['phase'] ) ? 'term' : 'post';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$chunk = self::FOREGROUND_CHUNK;

		$processed = ( 'term' === $phase )
			? $this->process_term_batch( $offset, $chunk )
			: $this->process_post_batch( $offset, $chunk );

		$base       = ( 'term' === $phase ) ? $this->count_posts() : 0;
		$cumulative = $base + $offset + $processed;
		$this->update_progress( $cumulative );

		$next_phase  = $phase;
		$next_offset = $offset + $chunk;
		$done        = false;

		if ( $processed < $chunk ) {
			if ( 'post' === $phase ) {
				$next_phase  = 'term';
				$next_offset = 0;
			} else {
				$done = true;
			}
		}

		$status = $this->status();

		if ( $done ) {
			$this->finish_status();
			do_action( 'ilb_generation_complete' );
		}

		wp_send_json_success(
			array(
				'phase'     => $next_phase,
				'offset'    => $next_offset,
				'processed' => $status['processed'],
				'total'     => $status['total'],
				'done'      => $done,
			)
		);
	}

	/**
	 * AJAX: returns the current index status for polling.
	 */
	public function ajax_index_status() {
		$this->guard_ajax();
		wp_send_json_success( $this->status() );
	}

	/**
	 * Verifies an admin AJAX request, dying on failure.
	 */
	private function guard_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		check_ajax_referer( ILB_Actions::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Returns the current index status (counts, progress and run state).
	 *
	 * @return array
	 */
	public function status() {
		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();

		$index = new ILB_Index();

		return array(
			'running'   => ! empty( $status['running'] ),
			'processed' => isset( $status['processed'] ) ? (int) $status['processed'] : 0,
			'total'     => isset( $status['total'] ) ? (int) $status['total'] : 0,
			'keywords'  => $index->count(),
			'links'     => $this->links->count(),
		);
	}

	/**
	 * Total number of sources (whitelisted posts + terms).
	 *
	 * @return int
	 */
	public function source_total() {
		return $this->count_posts() + $this->count_terms();
	}

	/**
	 * Counts whitelisted, published source posts.
	 *
	 * @return int
	 */
	private function count_posts() {
		$post_types = (array) $this->settings->get( 'whitelist_post_types' );
		if ( empty( $post_types ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Counts whitelisted term sources.
	 *
	 * @return int
	 */
	private function count_terms() {
		$taxonomies = (array) $this->settings->get( 'whitelist_taxonomies' );
		if ( empty( $taxonomies ) ) {
			return 0;
		}

		$count = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Stores the generation status.
	 *
	 * @param array $status Status data.
	 */
	private function set_status( array $status ) {
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Updates the processed counter in the status option.
	 *
	 * @param int $processed Cumulative processed count.
	 */
	private function update_progress( $processed ) {
		$status              = get_option( self::STATUS_OPTION, array() );
		$status              = is_array( $status ) ? $status : array();
		$status['processed'] = (int) $processed;
		$status['updated']   = time();
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Marks the generation as finished.
	 */
	private function finish_status() {
		$status              = get_option( self::STATUS_OPTION, array() );
		$status              = is_array( $status ) ? $status : array();
		$status['running']   = 0;
		$status['processed'] = isset( $status['total'] ) ? (int) $status['total'] : ( isset( $status['processed'] ) ? (int) $status['processed'] : 0 );
		$status['updated']   = time();
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Schedules a single batch action.
	 *
	 * @param string $phase  Source phase ('post' or 'term').
	 * @param int    $offset Offset for the batch.
	 */
	private function schedule_batch( $phase, $offset ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_BATCH, array( $phase, $offset ), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + 1, self::HOOK_BATCH, array( $phase, $offset ) );
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
