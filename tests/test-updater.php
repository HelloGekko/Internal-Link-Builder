<?php
/**
 * Tests for the self-hosted updater.
 *
 * The manifest fetch is mocked via pre_http_request, so no network is used.
 *
 * @package InternalLinkBuilder
 */

class Test_ILB_Updater extends WP_UnitTestCase {

	/**
	 * Manifest returned by the mocked endpoint.
	 *
	 * @var array
	 */
	private $manifest;

	public function set_up() {
		parent::set_up();
		delete_transient( ILB_Updater::CACHE_KEY );

		$this->manifest = array(
			'name'         => 'Internal Link Builder',
			'version'      => '9.9.9',
			'download_url' => 'https://example.test/ilb-9.9.9.zip',
			'requires'     => '5.8',
			'tested'       => '6.5',
			'requires_php' => '7.4',
			'sections'     => array( 'changelog' => '<p>New.</p>' ),
		);

		add_filter( 'ilb_update_manifest_url', array( $this, 'manifest_url' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'ilb_update_manifest_url', array( $this, 'manifest_url' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		delete_transient( ILB_Updater::CACHE_KEY );
		parent::tear_down();
	}

	public function manifest_url() {
		return 'https://example.test/manifest.json';
	}

	public function mock_http( $pre, $args, $url ) {
		if ( 'https://example.test/manifest.json' !== $url ) {
			return $pre;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $this->manifest ),
		);
	}

	private function basename() {
		return plugin_basename( ILB_PLUGIN_FILE );
	}

	public function test_update_is_injected_when_remote_is_newer() {
		$updater = new ILB_Updater( ILB_PLUGIN_FILE, '0.0.1' );
		$result  = $updater->inject_update( new stdClass() );

		$basename = $this->basename();
		$this->assertArrayHasKey( $basename, $result->response );
		$this->assertSame( '9.9.9', $result->response[ $basename ]->new_version );
		$this->assertSame( 'https://example.test/ilb-9.9.9.zip', $result->response[ $basename ]->package );
	}

	public function test_no_update_when_current_is_up_to_date() {
		$updater = new ILB_Updater( ILB_PLUGIN_FILE, '9.9.9' );
		$result  = $updater->inject_update( new stdClass() );

		$basename = $this->basename();
		$this->assertTrue( ! isset( $result->response[ $basename ] ) );
	}

	public function test_no_update_without_download_url() {
		unset( $this->manifest['download_url'] );

		$updater = new ILB_Updater( ILB_PLUGIN_FILE, '0.0.1' );
		$result  = $updater->inject_update( new stdClass() );

		$basename = $this->basename();
		$this->assertTrue( ! isset( $result->response[ $basename ] ) );
	}

	public function test_plugin_information_returns_details() {
		$updater = new ILB_Updater( ILB_PLUGIN_FILE, '0.0.1' );

		$args = (object) array( 'slug' => dirname( $this->basename() ) );
		$info = $updater->plugin_information( false, 'plugin_information', $args );

		$this->assertIsObject( $info );
		$this->assertSame( '9.9.9', $info->version );
		$this->assertSame( 'https://example.test/ilb-9.9.9.zip', $info->download_link );
	}

	public function test_plugin_information_ignores_other_slugs() {
		$updater = new ILB_Updater( ILB_PLUGIN_FILE, '0.0.1' );
		$args    = (object) array( 'slug' => 'some-other-plugin' );

		$this->assertFalse( $updater->plugin_information( false, 'plugin_information', $args ) );
	}
}
