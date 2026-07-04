<?php
/**
 * Integration tests for the linking engine's link computation.
 *
 * @package InternalLinkBuilder
 */

class Test_ILB_Engine extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		$defaults                          = ILB_Settings::defaults();
		$defaults['index_generation_mode'] = 'automatic';
		$defaults['whitelist_post_types']  = array( 'post', 'page' );
		update_option( ILB_SETTINGS_OPTION, $defaults );

		ILB_Index::install();
		ILB_Links::install();

		// Reset in-memory engine caches between tests (the DB transaction the
		// test harness uses rolls back the index token, which can otherwise make
		// a memoised candidate map leak across tests).
		ilb()->engine->flush_caches();
	}

	/**
	 * Configures keywords on a target and returns its post ID.
	 *
	 * @param string[] $keywords Keywords.
	 * @return int
	 */
	private function make_target( array $keywords ) {
		$target_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Target',
				'post_content' => 'Target content.',
			)
		);

		ilb()->keywords->save( $target_id, 'post', array( 'keywords' => $keywords ) );

		return $target_id;
	}

	public function test_keyword_in_source_links_to_target() {
		$target_id = $this->make_target( array( 'banana' ) );

		$source_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'I really like banana bread in the morning.',
			)
		);

		$links = ilb()->engine->compute_links( get_post( $source_id ) );

		$this->assertCount( 1, $links );
		$this->assertSame( $target_id, $links[0]['target_id'] );
		$this->assertSame( 'post', $links[0]['target_type'] );
		$this->assertSame( 'banana', $links[0]['keyword'] );
	}

	public function test_whole_word_matching_only() {
		$this->make_target( array( 'cat' ) );

		$source_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'The category page lists concatenated items.',
			)
		);

		$links = ilb()->engine->compute_links( get_post( $source_id ) );

		$this->assertCount( 0, $links, 'Substring of a larger word must not match.' );
	}

	public function test_source_does_not_link_to_itself() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'A page about apples and more apples.',
			)
		);

		ilb()->keywords->save( $post_id, 'post', array( 'keywords' => array( 'apples' ) ) );

		$links = ilb()->engine->compute_links( get_post( $post_id ) );

		$this->assertCount( 0, $links, 'A post must not link to itself.' );
	}

	public function test_blacklisted_source_produces_no_links() {
		$target_id = $this->make_target( array( 'mango' ) );

		$source_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Fresh mango smoothie recipe.',
			)
		);

		$settings                    = ILB_Settings::defaults();
		$settings['blacklist_posts'] = array( $source_id );
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		update_option( ILB_SETTINGS_OPTION, $settings );

		$links = ilb()->engine->compute_links( get_post( $source_id ) );

		$this->assertCount( 0, $links );
		unset( $target_id );
	}

	public function test_link_source_content_links_keyword_in_html_snippet() {
		$target_id = $this->make_target( array( 'kiwi' ) );

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$html = ilb()->engine->link_source_content( '<p>Verse kiwi uit de tuin.</p>', $source_id, 'post' );

		$this->assertStringContainsString( 'href="' . get_permalink( $target_id ) . '"', $html );
		$this->assertStringContainsString( '>kiwi</a>', $html );
	}

	public function test_link_source_content_respects_blacklisted_source() {
		$this->make_target( array( 'papaja' ) );

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$settings                         = ILB_Settings::defaults();
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		$settings['blacklist_posts']      = array( $source_id );
		update_option( ILB_SETTINGS_OPTION, $settings );

		$value = '<p>Een rijpe papaja.</p>';
		$this->assertSame( $value, ilb()->engine->link_source_content( $value, $source_id, 'post' ) );
	}
}
