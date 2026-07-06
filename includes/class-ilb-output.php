<?php
/**
 * Front-end page processing.
 *
 * Buffers the final HTML output of front-end pages and runs the linking engine
 * over the rendered document. Because this operates on the finished page, it
 * works regardless of how the content was produced — page builders, ACF,
 * shortcodes, widgets or custom templates.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Output
 */
class ILB_Output {

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Linking engine.
	 *
	 * @var ILB_Engine
	 */
	private $engine;

	/**
	 * Source descriptor for the buffered request, resolved before buffering.
	 *
	 * @var array|null
	 */
	private $source = null;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 * @param ILB_Engine   $engine   Linking engine.
	 */
	public function __construct( ILB_Settings $settings, ILB_Engine $engine ) {
		$this->settings = $settings;
		$this->engine   = $engine;
	}

	/**
	 * Registers the buffering hook.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_buffer' ), 1 );
	}

	/**
	 * Starts output buffering when page processing applies to this request.
	 */
	public function maybe_buffer() {
		if ( is_admin() || is_feed() || is_robots() || is_embed() || is_preview() || is_customize_preview() || is_404() ) {
			return;
		}

		$this->source = $this->detect_source();
		if ( ! $this->source ) {
			return;
		}

		// Nothing to link anywhere yet: don't buffer or parse the page at all.
		// This keeps sites that installed the plugin but have not configured
		// (or fully removed) keywords at full speed.
		if ( ! $this->engine->has_candidates() ) {
			return;
		}

		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Determines the link source for the current request.
	 *
	 * @return array|null Source descriptor or null when unsupported.
	 */
	private function detect_source() {
		if ( is_singular() ) {
			$post = get_post();

			return ( $post instanceof WP_Post ) ? array(
				'id'   => (int) $post->ID,
				'type' => 'post',
			) : null;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			return ( $term instanceof WP_Term ) ? array(
				'id'   => (int) $term->term_id,
				'type' => 'term',
			) : null;
		}

		return null;
	}

	/**
	 * Output-buffer callback: links keywords in the rendered page.
	 *
	 * @param string $html Buffered page output.
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->source ) {
			return $html;
		}

		// Only touch HTML documents (not JSON, XML sitemaps, streams, ...).
		if ( false === stripos( $html, '<html' ) ) {
			return $html;
		}

		$debug = $this->is_debug_request();
		if ( $debug ) {
			$this->engine->start_report();
		}

		$linked = $this->engine->link_document( $html, $this->source, $this->root_xpaths() );

		// Never let a processing problem blank the page.
		$out = ( is_string( $linked ) && '' !== $linked ) ? $linked : $html;

		return $debug ? $this->append_debug_comment( $out ) : $out;
	}

	/**
	 * Whether the current request asked for the admin-only linking diagnostics.
	 *
	 * @return bool
	 */
	private function is_debug_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic, gated on capability.
		return isset( $_GET['ilb-debug'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Appends a machine-readable diagnostics comment describing what the engine
	 * did on this page, so an administrator can see why keywords were or were not
	 * linked (visible in "View source" as <!-- Internal Link Builder debug ... -->).
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function append_debug_comment( $html ) {
		$report = $this->engine->last_report();

		$report['limits'] = array(
			'max_links_per_post'        => (int) $this->settings->get( 'max_links_per_post' ),
			'max_link_frequency'        => (int) $this->settings->get( 'max_link_frequency' ),
			'link_as_often_as_possible' => (int) (bool) $this->settings->get( 'link_as_often_as_possible' ),
			'consider_existing_links'   => (int) (bool) $this->settings->get( 'consider_existing_links' ),
			'exclude_html_areas'        => array_values( (array) $this->settings->get( 'exclude_html_areas' ) ),
			'content_region_setting'    => (string) $this->settings->get( 'universal_selector' ),
		);

		$comment = "\n<!-- Internal Link Builder debug: " . wp_json_encode( $report ) . " -->\n";

		if ( false !== stripos( $html, '</body>' ) ) {
			return preg_replace( '/<\/body>/i', $comment . '</body>', $html, 1 );
		}

		return $html . $comment;
	}

	/**
	 * Returns the configured content-region XPaths, if any.
	 *
	 * An empty result lets the engine fall back to its built-in list
	 * (main, [role=main], #main, #content, #primary, body).
	 *
	 * @return string[]
	 */
	private function root_xpaths() {
		$selector = trim( (string) $this->settings->get( 'universal_selector' ) );
		if ( '' === $selector ) {
			return array();
		}

		return self::selectors_to_xpaths( $selector );
	}

	/**
	 * Converts a comma-separated list of simple CSS selectors to XPath.
	 *
	 * Supported forms: "tag", "#id" and ".class". Anything else is ignored.
	 *
	 * @param string $selectors Selector list, e.g. "main, #content, .entry-content".
	 * @return string[]
	 */
	public static function selectors_to_xpaths( $selectors ) {
		$xpaths = array();

		foreach ( explode( ',', (string) $selectors ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}

			if ( ! preg_match( '/^([#.]?)([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $matches ) ) {
				continue;
			}

			$prefix = $matches[1];
			$name   = $matches[2];

			if ( '#' === $prefix ) {
				$xpaths[] = '//*[@id="' . $name . '"]';
			} elseif ( '.' === $prefix ) {
				$xpaths[] = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")]';
			} else {
				$xpaths[] = '//' . strtolower( $name );
			}
		}

		return $xpaths;
	}
}
