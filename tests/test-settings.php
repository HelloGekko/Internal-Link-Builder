<?php
/**
 * Tests for settings sanitization, especially the per-tab merge that prevents
 * one tab's save from wiping the others.
 *
 * @package InternalLinkBuilder
 */

class Test_ILB_Settings extends WP_UnitTestCase {

	/**
	 * @var ILB_Settings
	 */
	private $settings;

	public function set_up() {
		parent::set_up();
		$this->settings = new ILB_Settings();
		update_option( ILB_SETTINGS_OPTION, ILB_Settings::defaults() );
	}

	public function test_defaults_contain_known_keys() {
		$defaults = ILB_Settings::defaults();
		$this->assertArrayHasKey( 'whitelist_post_types', $defaults );
		$this->assertArrayHasKey( 'link_template', $defaults );
		$this->assertSame( array( 'post', 'page' ), $defaults['whitelist_post_types'] );
	}

	public function test_saving_one_tab_preserves_other_tabs() {
		// Start from defaults, then change a General-tab value directly.
		$stored               = ILB_Settings::defaults();
		$stored['batch_size'] = 99;
		update_option( ILB_SETTINGS_OPTION, $stored );

		// Submit only the Content tab (as the form would).
		$content_input = array(
			'_tab'                 => 'content',
			'whitelist_post_types' => array( 'post' ),
			'blacklist_posts'      => array( '5', '7' ),
			'max_links_per_post'   => '3',
		);

		$clean = $this->settings->sanitize( $content_input );

		// Content values updated...
		$this->assertSame( array( 'post' ), $clean['whitelist_post_types'] );
		$this->assertSame( array( 5, 7 ), $clean['blacklist_posts'] );
		$this->assertSame( 3, $clean['max_links_per_post'] );

		// ...while the General-tab value survives.
		$this->assertSame( 99, $clean['batch_size'] );
	}

	public function test_token_sanitization_by_value_type() {
		$input = array(
			'_tab'                 => 'content',
			'whitelist_post_types' => array( 'post', 'does-not-exist' ),
			'blacklist_posts'      => array( '12', 'abc', '0', '12' ),
			'universal_selector'   => '  main, #content  ',
		);

		$clean = $this->settings->sanitize( $input );

		// Slugs validated against real post types.
		$this->assertSame( array( 'post' ), $clean['whitelist_post_types'] );
		// Ints filtered, deduped, zero dropped.
		$this->assertSame( array( 12 ), $clean['blacklist_posts'] );
		// Plain text trimmed.
		$this->assertSame( 'main, #content', $clean['universal_selector'] );
	}

	public function test_number_clamping() {
		$clean = $this->settings->sanitize(
			array(
				'_tab'       => 'general',
				'batch_size' => '9999',
			)
		);
		$this->assertSame( 250, $clean['batch_size'] );

		$clean = $this->settings->sanitize(
			array(
				'_tab'       => 'general',
				'batch_size' => '-5',
			)
		);
		$this->assertSame( 1, $clean['batch_size'] );
	}
}
