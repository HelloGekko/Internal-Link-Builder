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

		$linked = $this->engine->link_document( $html, $this->source, $this->root_xpaths() );

		// Never let a processing problem blank the page.
		return ( is_string( $linked ) && '' !== $linked ) ? $linked : $html;
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
