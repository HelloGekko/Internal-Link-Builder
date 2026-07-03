<?php
/**
 * Tests for the ACF integration helpers.
 *
 * ACF itself is not loaded in the test environment; these tests cover the
 * source mapping and the engine entry point the integration relies on.
 *
 * @package InternalLinkBuilder
 */

class Test_ILB_ACF extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		$defaults                          = ILB_Settings::defaults();
		$defaults['index_generation_mode'] = 'automatic';
		$defaults['whitelist_post_types']  = array( 'post', 'page' );
		update_option( ILB_SETTINGS_OPTION, $defaults );

		ILB_Index::install();
		ILB_Links::install();
		ilb()->engine->flush_caches();
	}

	public function test_parse_source_numeric_post_id() {
		$this->assertSame(
			array(
				'id'   => 42,
				'type' => 'post',
			),
			ILB_ACF::parse_source( 42 )
		);
		$this->assertSame(
			array(
				'id'   => 42,
				'type' => 'post',
			),
			ILB_ACF::parse_source( '42' )
		);
	}

	public function test_parse_source_modern_term_form() {
		$this->assertSame(
			array(
				'id'   => 7,
				'type' => 'term',
			),
			ILB_ACF::parse_source( 'term_7' )
		);
	}

	public function test_parse_source_legacy_taxonomy_form() {
		$this->assertSame(
			array(
				'id'   => 9,
				'type' => 'term',
			),
			ILB_ACF::parse_source( 'category_9' )
		);
	}

	public function test_parse_source_rejects_unsupported_objects() {
		$this->assertNull( ILB_ACF::parse_source( 'user_5' ) );
		$this->assertNull( ILB_ACF::parse_source( 'option' ) );
		$this->assertNull( ILB_ACF::parse_source( 'options' ) );
		$this->assertNull( ILB_ACF::parse_source( 0 ) );
		$this->assertNull( ILB_ACF::parse_source( 'comment_3' ) );
	}

	public function test_link_source_content_links_keyword_in_field_value() {
		$target_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Target',
				'post_content' => 'Target content.',
			)
		);
		ilb()->keywords->save( $target_id, 'post', array( 'keywords' => array( 'kiwi' ) ) );

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$html = ilb()->engine->link_source_content( '<p>Verse kiwi uit de tuin.</p>', $source_id, 'post' );

		$this->assertStringContainsString( 'href="' . get_permalink( $target_id ) . '"', $html );
		$this->assertStringContainsString( '>kiwi</a>', $html );
	}

	public function test_link_source_content_respects_blacklisted_source() {
		$target_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		ilb()->keywords->save( $target_id, 'post', array( 'keywords' => array( 'papaja' ) ) );

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$settings                         = ILB_Settings::defaults();
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		$settings['blacklist_posts']      = array( $source_id );
		update_option( ILB_SETTINGS_OPTION, $settings );

		$value = '<p>Een rijpe papaja.</p>';
		$this->assertSame( $value, ilb()->engine->link_source_content( $value, $source_id, 'post' ) );
	}
}
