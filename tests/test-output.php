<?php
/**
 * Tests for universal (whole-page) processing.
 *
 * @package InternalLinkBuilder
 */

class Test_ILB_Output extends WP_UnitTestCase {

	/**
	 * Target post that keywords point at.
	 *
	 * @var int
	 */
	private $target_id;

	/**
	 * Second target post, for the builder-block keyword.
	 *
	 * @var int
	 */
	private $target_two_id;

	public function set_up() {
		parent::set_up();

		$defaults                          = ILB_Settings::defaults();
		$defaults['index_generation_mode'] = 'automatic';
		$defaults['whitelist_post_types']  = array( 'post', 'page' );
		update_option( ILB_SETTINGS_OPTION, $defaults );

		ILB_Index::install();
		ILB_Links::install();
		// The option write above is a no-op when it matches the stored value,
		// so the update_option hook does not fire; flush explicitly to drop any
		// settings cache left over from a previous test.
		ilb()->settings->flush_cache();
		ilb()->engine->flush_caches();

		$this->target_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Target',
				'post_content' => 'Target content.',
			)
		);
		ilb()->keywords->save( $this->target_id, 'post', array( 'keywords' => array( 'druif' ) ) );

		$this->target_two_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Target two',
				'post_content' => 'Second target content.',
			)
		);
		ilb()->keywords->save( $this->target_two_id, 'post', array( 'keywords' => array( 'vijg' ) ) );
	}

	/**
	 * Builds a full page document with keywords in several regions. The main
	 * region carries two different keywords (each target is only ever linked
	 * once per page); page chrome repeats the first keyword.
	 *
	 * @return string
	 */
	private function page_html() {
		return '<!DOCTYPE html><html><head><title>druif in title</title></head><body>'
			. '<nav><span>druif in nav</span></nav>'
			. '<main><p>Een tros druif in de hoofdcontent.</p><div class="builder-block"><p>Een vijg uit de page builder.</p></div></main>'
			. '<footer><p>druif in footer</p></footer>'
			. '</body></html>';
	}

	public function test_link_document_links_only_inside_content_region() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$out = ilb()->engine->link_document(
			$this->page_html(),
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		// Both keywords linked inside <main>, including the builder markup.
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_two_id ) . '"' ) );

		// Chrome stays untouched.
		$this->assertStringContainsString( '<title>druif in title</title>', $out );
		$this->assertStringContainsString( '<nav><span>druif in nav</span></nav>', $out );
		$this->assertStringContainsString( '<footer><p>druif in footer</p></footer>', $out );

		// Doctype survives the round-trip.
		$this->assertStringStartsWith( '<!DOCTYPE html>', trim( $out ) );
	}

	public function test_link_document_falls_back_to_body_without_main() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><p>Losse druif zonder main.</p></body></html>';
		$out  = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		$this->assertStringContainsString( 'href="' . get_permalink( $this->target_id ) . '"', $out );
	}

	public function test_link_document_honours_custom_root_xpaths() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$out = ilb()->engine->link_document(
			$this->page_html(),
			array(
				'id'   => $source_id,
				'type' => 'post',
			),
			ILB_Output::selectors_to_xpaths( '.builder-block' )
		);

		// Only the builder block is processed: its keyword links, the one in
		// the main paragraph outside the region does not.
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_two_id ) . '"' ) );
		$this->assertSame( 0, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
	}

	public function test_excerpt_areas_are_excluded_when_configured() {
		$settings                        = ILB_Settings::defaults();
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		$settings['exclude_html_areas']  = array( 'excerpt' );
		update_option( ILB_SETTINGS_OPTION, $settings );

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<div class="entry-summary"><p>Een druif in de samenvatting.</p></div>'
			. '<p>Een vijg in de gewone tekst.</p>'
			. '</main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		// Keyword inside the excerpt container is skipped, the normal one links.
		$this->assertSame( 0, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_two_id ) . '"' ) );
	}

	public function test_excerpt_areas_are_linked_when_not_excluded() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<div class="entry-summary"><p>Een druif in de samenvatting.</p></div>'
			. '</main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		// Default config does not exclude excerpts, so it links.
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
	}

	public function test_selectors_to_xpaths_conversion() {
		$this->assertSame(
			array(
				'//main',
				'//*[@id="content"]',
				'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
			),
			ILB_Output::selectors_to_xpaths( 'main, #content, .entry-content' )
		);

		// Invalid tokens are dropped.
		$this->assertSame( array(), ILB_Output::selectors_to_xpaths( 'div > p, [data-x], ' ) );
	}
}
