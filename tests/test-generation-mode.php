<?php
/**
 * Tests for the index generation modes (daily / automatic / none).
 *
 * In every mode except "none" the keyword index is kept current on save, so
 * live linking always reflects the latest keywords. The heavy link-graph
 * rebuild is what differs: daily runs it once a day (plus the button),
 * automatic runs it on every change, none never runs it automatically.
 *
 * @package InternalLinkBuilder
 */

class Test_ILB_Generation_Mode extends WP_UnitTestCase {

	private function set_mode( $mode ) {
		$settings                          = ILB_Settings::defaults();
		$settings['index_generation_mode'] = $mode;
		$settings['whitelist_post_types']  = array( 'post', 'page' );
		update_option( ILB_SETTINGS_OPTION, $settings );
		ilb()->settings->flush_cache();
	}

	public function set_up() {
		parent::set_up();
		ILB_Index::install();
		ILB_Links::install();
		wp_clear_scheduled_hook( ILB_Generator::HOOK_DAILY );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( ILB_Generator::HOOK_DAILY );
		parent::tear_down();
	}

	public function test_daily_mode_keeps_keyword_index_current_on_save() {
		$this->set_mode( 'daily' );

		$target = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		ilb()->keywords->save( $target, 'post', array( 'keywords' => array( 'appel' ) ) );

		$this->assertNotEmpty(
			ilb()->index->get_targets_for_keyword( 'appel' ),
			'Daily mode must rebuild the keyword index on save so links work immediately.'
		);
	}

	public function test_automatic_mode_keeps_keyword_index_current_on_save() {
		$this->set_mode( 'automatic' );

		$target = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		ilb()->keywords->save( $target, 'post', array( 'keywords' => array( 'kiwi' ) ) );

		$this->assertNotEmpty( ilb()->index->get_targets_for_keyword( 'kiwi' ) );
	}

	public function test_none_mode_does_not_touch_keyword_index_on_save() {
		$this->set_mode( 'none' );

		$target = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		ilb()->keywords->save( $target, 'post', array( 'keywords' => array( 'peer' ) ) );

		$this->assertEmpty( ilb()->index->get_targets_for_keyword( 'peer' ) );
	}

	public function test_daily_mode_schedules_recurring_event() {
		$this->set_mode( 'daily' );
		ilb()->generator->hooks();

		$this->assertNotFalse( wp_next_scheduled( ILB_Generator::HOOK_DAILY ) );
		$this->assertSame( 'daily', wp_get_schedule( ILB_Generator::HOOK_DAILY ) );
	}

	public function test_non_daily_mode_clears_recurring_event() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ILB_Generator::HOOK_DAILY );
		$this->assertNotFalse( wp_next_scheduled( ILB_Generator::HOOK_DAILY ) );

		$this->set_mode( 'automatic' );
		ilb()->generator->hooks();

		$this->assertFalse( wp_next_scheduled( ILB_Generator::HOOK_DAILY ) );
	}
}
