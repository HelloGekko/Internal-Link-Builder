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

	public function test_elementor_excerpt_widget_is_excluded_when_configured() {
		$settings                         = ILB_Settings::defaults();
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		$settings['exclude_html_areas']   = array( 'excerpt' );
		update_option( ILB_SETTINGS_OPTION, $settings );
		ilb()->settings->flush_cache();

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Elementor renders the post-excerpt widget as nested divs; the keyword
		// container class sits on the outer widget wrapper.
		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<div class="elementor-widget elementor-widget-theme-post-excerpt">'
			. '<div class="elementor-widget-container"><p>Een druif in de widget-samenvatting.</p></div></div>'
			. '<p>Een vijg in de gewone tekst.</p>'
			. '</main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

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

	public function test_navigation_link_to_target_does_not_block_content_linking() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$permalink = get_permalink( $this->target_id );

		// No <main>: the content region falls back to the whole <body>, which on
		// many themes contains the navigation. A menu link to the target is page
		// chrome and must not count as an existing content link, otherwise the
		// keyword would never be linked in the article body.
		$html = '<!DOCTYPE html><html><head><title>x</title></head><body>'
			. '<nav><a href="' . esc_url( $permalink ) . '">Menu</a></nav>'
			. '<div class="entry-content"><p>Een druif in de tekst.</p></div>'
			. '</body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		// The nav link stays and the body keyword is now linked too: two hrefs.
		$this->assertSame( 2, substr_count( $out, 'href="' . $permalink . '"' ) );
		$this->assertStringContainsString( '>druif</a>', $out );
	}

	public function test_existing_content_link_still_blocks_linking() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$permalink = get_permalink( $this->target_id );

		// A genuine link to the target inside the content region must still
		// suppress a second, automatic link to the same URL.
		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<p>Al <a href="' . esc_url( $permalink ) . '">gelinkt</a> eerder.</p>'
			. '<p>Een druif verderop.</p>'
			. '</main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		$this->assertSame( 1, substr_count( $out, 'href="' . $permalink . '"' ) );
	}

	public function test_engine_report_records_pipeline_counts() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		ilb()->engine->start_report();
		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<p>Een druif in de tekst.</p></main></body></html>';
		ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		$report = ilb()->engine->last_report();
		$this->assertSame( '//main', $report['content_region'] );
		$this->assertSame( 2, $report['keywords_in_index'] );
		$this->assertSame( 1, $report['keyword_matches_found'] );
		$this->assertSame( 1, $report['links_placed'] );
	}

	public function test_debug_comment_is_added_for_admins_only() {
		$source = array(
			'id'   => self::factory()->post->create( array( 'post_status' => 'publish' ) ),
			'type' => 'post',
		);

		$ref = new ReflectionProperty( ILB_Output::class, 'source' );
		$ref->setAccessible( true );
		$ref->setValue( ilb()->output, $source );

		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<p>Een druif in de tekst.</p></main></body></html>';

		// A regular visitor never sees the diagnostics.
		wp_set_current_user( 0 );
		$_GET['ilb-debug'] = '1';
		$this->assertStringNotContainsString( 'Internal Link Builder debug', ilb()->output->process( $html ) );

		// An administrator does.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$out = ilb()->output->process( $html );
		unset( $_GET['ilb-debug'] );

		$this->assertStringContainsString( 'Internal Link Builder debug', $out );
		$this->assertStringContainsString( '"links_placed":1', $out );
		$this->assertStringContainsString( '"plugin_version":"' . ILB_VERSION . '"', $out );
	}

	public function test_author_box_is_excluded_from_linking() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Elementor renders the author box as a widget; keywords in the author
		// bio are chrome, not article content, and must not be linked.
		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<div class="elementor-widget elementor-widget-author-box">'
			. '<div class="elementor-widget-container"><p>Een druif in de auteur-bio.</p></div></div>'
			. '<p>Een vijg in de gewone tekst.</p>'
			. '</main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		$this->assertSame( 0, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_two_id ) . '"' ) );
	}

	public function test_blacklisted_target_does_not_receive_links() {
		$settings                         = ILB_Settings::defaults();
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		$settings['blacklist_posts']      = array( $this->target_id );
		update_option( ILB_SETTINGS_OPTION, $settings );
		ilb()->settings->flush_cache();

		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$html      = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<p>Een druif en een vijg in de tekst.</p></main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		// The blacklisted target is never linked; the other target still is.
		$this->assertSame( 0, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
		$this->assertSame( 1, substr_count( $out, 'href="' . get_permalink( $this->target_two_id ) . '"' ) );
	}

	public function test_blacklisted_source_is_skipped_and_manual_links_untouched() {
		$source_id                        = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$settings                         = ILB_Settings::defaults();
		$settings['whitelist_post_types'] = array( 'post', 'page' );
		$settings['blacklist_posts']      = array( $source_id );
		update_option( ILB_SETTINGS_OPTION, $settings );
		ilb()->settings->flush_cache();

		ilb()->engine->start_report();
		$html = '<!DOCTYPE html><html><head><title>x</title></head><body><main>'
			. '<p>Een druif en een <a href="https://example.test/manual/">handmatige</a> link.</p>'
			. '</main></body></html>';

		$out = ilb()->engine->link_document(
			$html,
			array(
				'id'   => $source_id,
				'type' => 'post',
			)
		);

		// The blacklisted source is not processed: no automatic link is added and
		// the existing manual link is left exactly as it was.
		$this->assertSame( 0, substr_count( $out, 'href="' . get_permalink( $this->target_id ) . '"' ) );
		$this->assertStringContainsString( '<a href="https://example.test/manual/">handmatige</a>', $out );

		$report = ilb()->engine->last_report();
		$this->assertFalse( $report['source_linked'] );
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
