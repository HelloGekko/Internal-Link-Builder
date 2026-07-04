<?php
/**
 * Front-end linking engine.
 *
 * Turns configured keywords into links to their target, on the fly, in post
 * content, term descriptions and (optionally) selected custom fields. The stored
 * content is never modified.
 *
 * Parsing is done with DOMDocument so replacements only ever happen inside text
 * nodes — never inside tags, attributes, existing links or excluded HTML areas.
 *
 * A "source" throughout this class is an array: array( 'id' => int, 'type' =>
 * 'post'|'term' ).
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Engine
 */
class ILB_Engine {

	/**
	 * Meta key for the cached link output.
	 */
	const CACHE_META = '_ilb_link_cache';

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Index handler.
	 *
	 * @var ILB_Index
	 */
	private $index;

	/**
	 * Keyword storage handler.
	 *
	 * @var ILB_Keywords
	 */
	private $keywords;

	/**
	 * Link graph / statistics handler.
	 *
	 * @var ILB_Links
	 */
	private $links;

	/**
	 * Per-request cache of resolved targets, keyed by "type:id".
	 *
	 * @var array
	 */
	private $target_cache = array();

	/**
	 * Per-request cache of per-target override settings, keyed by "type:id".
	 *
	 * @var array
	 */
	private $override_cache = array();

	/**
	 * Memoised base candidate map (keyword groups), keyed by index token.
	 *
	 * @var array|null
	 */
	private $base_candidates = null;

	/**
	 * Index token the base candidate memo was built for.
	 *
	 * @var int|null
	 */
	private $base_token = null;

	/**
	 * Re-entrancy guard for the custom-field meta filters.
	 *
	 * @var bool
	 */
	private $meta_guard = false;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 * @param ILB_Index    $index    Index handler.
	 * @param ILB_Keywords $keywords Keyword storage handler.
	 * @param ILB_Links    $links    Link graph handler.
	 */
	public function __construct( ILB_Settings $settings, ILB_Index $index, ILB_Keywords $keywords, ILB_Links $links ) {
		$this->settings = $settings;
		$this->index    = $index;
		$this->keywords = $keywords;
		$this->links    = $links;

		// In universal mode the whole page output is processed by ILB_Output, so
		// the per-source content filters would only duplicate work.
		if ( 'universal' === $this->settings->get( 'processing_mode' ) ) {
			return;
		}

		// Run after wpautop (priority 10) so paragraphs exist as <p> elements.
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'get_the_archive_description', array( $this, 'filter_term_description' ), 20 );

		// Custom-field linking is an opt-in advanced feature. Only hook the (hot)
		// meta filters when explicitly enabled AND fields are configured, to
		// avoid both site-wide overhead and accidental breakage.
		if ( $this->settings->get( 'enable_custom_field_linking' ) ) {
			if ( ! empty( (array) $this->settings->get( 'post_custom_fields' ) ) ) {
				add_filter( 'get_post_metadata', array( $this, 'filter_post_meta' ), 20, 4 );
			}
			if ( ! empty( (array) $this->settings->get( 'term_custom_fields' ) ) ) {
				add_filter( 'get_term_metadata', array( $this, 'filter_term_meta' ), 20, 4 );
			}
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Front-end filters
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Clears the engine's per-request caches.
	 *
	 * Production code rarely needs this (caches are per-request), but it keeps
	 * long-running processes and the test suite isolated.
	 */
	public function flush_caches() {
		$this->base_candidates = null;
		$this->base_token      = null;
		$this->target_cache    = array();
		$this->override_cache  = array();
	}

	/**
	 * The `the_content` filter entry point.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( is_admin() || is_feed() || '' === trim( (string) $content ) ) {
			return $content;
		}

		/**
		 * Filters whether the engine should process the current the_content call.
		 *
		 * Defaults to singular, main-query, in-the-loop content. Themes (e.g.
		 * some block/FSE setups) can override this to widen or narrow coverage.
		 *
		 * @param bool $should_link Whether to inject links here.
		 */
		$should_link = is_singular() && in_the_loop() && is_main_query();
		if ( ! apply_filters( 'ilb_should_link_content', $should_link ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! $this->is_post_source_allowed( $post ) ) {
			return $content;
		}

		$source = array(
			'id'   => (int) $post->ID,
			'type' => 'post',
		);

		// Serve from cache when enabled and fresh.
		$use_cache   = (bool) $this->settings->get( 'cache' );
		$fingerprint = '';
		if ( $use_cache ) {
			$fingerprint = $this->fingerprint( $content, $post->ID );
			$cached      = get_post_meta( $post->ID, self::CACHE_META, true );
			if ( is_array( $cached ) && isset( $cached['key'] ) && $cached['key'] === $fingerprint ) {
				return $cached['html'];
			}
		}

		$result = $this->link_html( $content, $source );

		if ( $use_cache ) {
			update_post_meta(
				$post->ID,
				self::CACHE_META,
				array(
					'key'  => $fingerprint,
					'html' => $result,
				)
			);
		}

		return $result;
	}

	/**
	 * Links keywords inside a term archive description.
	 *
	 * @param string $description Archive description HTML.
	 * @return string
	 */
	public function filter_term_description( $description ) {
		if ( is_admin() || '' === trim( (string) $description ) ) {
			return $description;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || ! $this->is_term_source_allowed( $term ) ) {
			return $description;
		}

		return $this->link_html(
			$description,
			array(
				'id'   => (int) $term->term_id,
				'type' => 'term',
			)
		);
	}

	/**
	 * Links keywords inside selected post custom fields on display.
	 *
	 * Only the meta keys configured under "Custom fields of posts that get used
	 * for linking" are affected, and only on front-end page views. Returns null
	 * to let WordPress read the value normally when nothing should change.
	 *
	 * @param mixed  $value     Short-circuit value (null by default).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public function filter_post_meta( $value, $object_id, $meta_key, $single ) {
		return $this->filter_object_meta( $value, $object_id, $meta_key, $single, 'post' );
	}

	/**
	 * Links keywords inside selected term custom fields on display.
	 *
	 * @param mixed  $value     Short-circuit value (null by default).
	 * @param int    $object_id Term ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public function filter_term_meta( $value, $object_id, $meta_key, $single ) {
		return $this->filter_object_meta( $value, $object_id, $meta_key, $single, 'term' );
	}

	/**
	 * Shared custom-field meta filter for posts and terms.
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @param string $type      'post' or 'term'.
	 * @return mixed
	 */
	private function filter_object_meta( $value, $object_id, $meta_key, $single, $type ) {
		if ( $this->meta_guard || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $value;
		}

		$setting = ( 'term' === $type ) ? 'term_custom_fields' : 'post_custom_fields';
		$fields  = (array) $this->settings->get( $setting );
		if ( empty( $fields ) || ! in_array( $meta_key, $fields, true ) ) {
			return $value;
		}

		/**
		 * Allows disabling automatic linking of custom fields.
		 *
		 * @param bool   $enabled  Whether to link this field.
		 * @param string $meta_key Meta key.
		 * @param int    $object_id Object ID.
		 * @param string $type     'post' or 'term'.
		 */
		if ( ! apply_filters( 'ilb_link_custom_fields', true, $meta_key, $object_id, $type ) ) {
			return $value;
		}

		$source = array(
			'id'   => (int) $object_id,
			'type' => $type,
		);
		if ( ! $this->is_source_allowed( $source ) ) {
			return $value;
		}

		// Hold the guard across the whole operation so neither the raw read nor
		// the linking work re-enters this filter for the same field.
		$this->meta_guard = true;
		$raw              = get_metadata_raw( $type, $object_id, $meta_key, $single );

		if ( null === $raw || '' === $raw || array() === $raw ) {
			$this->meta_guard = false;
			return $value;
		}

		if ( is_array( $raw ) ) {
			$result = array_map(
				function ( $item ) use ( $source ) {
					return is_string( $item ) ? $this->link_html( $item, $source ) : $item;
				},
				$raw
			);
		} else {
			$result = is_string( $raw ) ? $this->link_html( $raw, $source ) : $value;
		}

		$this->meta_guard = false;

		return $result;
	}

	/**
	 * Computes the cache fingerprint for a post's content.
	 *
	 * @param string $content Post content.
	 * @param int    $post_id Source post ID.
	 * @return string
	 */
	private function fingerprint( $content, $post_id ) {
		return md5( $content . '|' . ILB_Index::token() . '|' . wp_json_encode( $this->settings->all() ) . '|' . $post_id );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Public computation (used by the generator)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Computes the links a source post would generate, without rendering.
	 *
	 * @param WP_Post $post Source post.
	 * @return array[] List of links: each [target_id, target_type, keyword].
	 */
	public function compute_links( WP_Post $post ) {
		if ( ! $this->is_post_source_allowed( $post ) ) {
			return array();
		}

		// Approximate the rendered structure: render blocks and apply wpautop so
		// the graph matches what the front end produces. Shortcodes are left
		// unexpanded to avoid side effects during background generation.
		$content = wpautop( do_blocks( $post->post_content ) );

		return $this->extract_links(
			$content,
			array(
				'id'   => (int) $post->ID,
				'type' => 'post',
			)
		);
	}

	/**
	 * Computes the links a source term (its description) would generate.
	 *
	 * @param WP_Term $term Source term.
	 * @return array[] List of links: each [target_id, target_type, keyword].
	 */
	public function compute_links_for_term( WP_Term $term ) {
		if ( ! $this->is_term_source_allowed( $term ) ) {
			return array();
		}

		$content = wpautop( $term->description );

		return $this->extract_links(
			$content,
			array(
				'id'   => (int) $term->term_id,
				'type' => 'term',
			)
		);
	}

	/**
	 * Resolves content and returns the flat link list for the graph.
	 *
	 * @param string $content Content to scan.
	 * @param array  $source  Source descriptor.
	 * @return array[]
	 */
	private function extract_links( $content, array $source ) {
		$resolved = $this->resolve( $content, $source );
		if ( ! $resolved ) {
			return array();
		}

		$links = array();
		foreach ( $resolved['accepted'] as $placement ) {
			$links[] = array(
				'target_id'   => $placement['target']['id'],
				'target_type' => $placement['target']['type'],
				'keyword'     => $placement['anchor'],
			);
		}

		return $links;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Source eligibility
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Whether a source descriptor is allowed to link out.
	 *
	 * @param array $source Source descriptor.
	 * @return bool
	 */
	private function is_source_allowed( array $source ) {
		if ( 'term' === $source['type'] ) {
			$term = get_term( $source['id'] );
			return $term instanceof WP_Term && $this->is_term_source_allowed( $term );
		}

		$post = get_post( $source['id'] );
		return $post instanceof WP_Post && $this->is_post_source_allowed( $post );
	}

	/**
	 * Whether a post is allowed to link out to others.
	 *
	 * @param WP_Post $post Source post.
	 * @return bool
	 */
	private function is_post_source_allowed( $post ) {
		$post_types = (array) $this->settings->get( 'whitelist_post_types' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return false;
		}

		$blacklist = array_map( 'intval', (array) $this->settings->get( 'blacklist_posts' ) );
		if ( in_array( (int) $post->ID, $blacklist, true ) ) {
			return false;
		}

		if ( $this->settings->get( 'blacklist_child_pages' ) ) {
			foreach ( get_post_ancestors( $post->ID ) as $ancestor ) {
				if ( in_array( (int) $ancestor, $blacklist, true ) ) {
					return false;
				}
			}
		}

		$overrides = $this->get_overrides( $post->ID, 'post' );
		if ( ! empty( $overrides['on_global_blacklist'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a term is allowed to link out from its description.
	 *
	 * @param WP_Term $term Source term.
	 * @return bool
	 */
	private function is_term_source_allowed( $term ) {
		$taxonomies = (array) $this->settings->get( 'whitelist_taxonomies' );
		if ( ! in_array( $term->taxonomy, $taxonomies, true ) ) {
			return false;
		}

		$blacklist = array_map( 'intval', (array) $this->settings->get( 'blacklist_terms' ) );
		if ( in_array( (int) $term->term_id, $blacklist, true ) ) {
			return false;
		}

		$overrides = $this->get_overrides( $term->term_id, 'term' );
		if ( ! empty( $overrides['on_global_blacklist'] ) ) {
			return false;
		}

		return true;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Candidate keywords
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Builds the ordered candidate list for a source.
	 *
	 * @param array $source Source descriptor.
	 * @return array {
	 *     @type array $candidates Ordered candidates (keyword, lower, rank, targets[]).
	 *     @type array $lookup     Map of lowercased keyword => candidate.
	 * }
	 */
	private function get_candidates( array $source ) {
		$base = $this->base_candidates();
		if ( empty( $base ) ) {
			return array(
				'candidates' => array(),
				'lookup'     => array(),
			);
		}

		// Keywords the source explicitly excludes from linking in its content.
		$blocked = array_flip(
			array_map( 'strtolower', $this->keywords->get_content_blacklist( $source['id'], $source['type'] ) )
		);

		$candidates = array();
		foreach ( $base as $lower => $candidate ) {
			if ( ! isset( $blocked[ $lower ] ) ) {
				$candidates[] = $candidate;
			}
		}

		$this->sort_candidates( $candidates, (string) $this->settings->get( 'keyword_order' ) );

		$lookup = array();
		foreach ( $candidates as $rank => $candidate ) {
			$candidates[ $rank ]['rank']   = $rank;
			$lookup[ $candidate['lower'] ] = $candidates[ $rank ];
		}

		return array(
			'candidates' => $candidates,
			'lookup'     => $lookup,
		);
	}

	/**
	 * Builds (and caches) the base keyword-group map from the index.
	 *
	 * The result is memoised per request and stored in the object cache keyed by
	 * the index token, so the full index is read at most once per change — not
	 * once per rendered post or per source during generation.
	 *
	 * @return array<string,array> Map of lowercased keyword => candidate group.
	 */
	private function base_candidates() {
		$token = ILB_Index::token();
		if ( null !== $this->base_candidates && $this->base_token === $token ) {
			return $this->base_candidates;
		}

		$cache_key = 'ilb_base_candidates_' . $token;
		$cached    = wp_cache_get( $cache_key, 'ilb' );
		if ( is_array( $cached ) ) {
			$this->base_candidates = $cached;
			$this->base_token      = $token;
			return $cached;
		}

		$by_keyword = array();
		$seq        = 0;
		foreach ( $this->index->all_rows() as $row ) {
			$lower = $row['keyword_lower'];
			if ( ! isset( $by_keyword[ $lower ] ) ) {
				$by_keyword[ $lower ] = array(
					'keyword' => $row['keyword'],
					'lower'   => $lower,
					'seq'     => $seq++,
					'targets' => array(),
				);
			}
			$by_keyword[ $lower ]['targets'][] = array(
				'id'   => (int) $row['target_id'],
				'type' => $row['target_type'],
			);
		}

		wp_cache_set( $cache_key, $by_keyword, 'ilb', HOUR_IN_SECONDS );
		$this->base_candidates = $by_keyword;
		$this->base_token      = $token;

		return $by_keyword;
	}

	/**
	 * Sorts candidates in place according to the configured keyword order.
	 *
	 * @param array  $candidates Candidate list (by reference).
	 * @param string $order      Order mode.
	 */
	private function sort_candidates( array &$candidates, $order ) {
		$word_count = static function ( $keyword ) {
			$parts = preg_split( '/\s+/', trim( $keyword ) );
			return $parts ? count( array_filter( $parts, 'strlen' ) ) : 0;
		};
		$char_count = static function ( $keyword ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $keyword ) : strlen( $keyword );
		};

		usort(
			$candidates,
			static function ( $a, $b ) use ( $order, $word_count, $char_count ) {
				switch ( $order ) {
					case 'highest_word_count':
						$cmp = $word_count( $b['keyword'] ) - $word_count( $a['keyword'] );
						break;
					case 'lowest_word_count':
						$cmp = $word_count( $a['keyword'] ) - $word_count( $b['keyword'] );
						break;
					case 'highest_char_count':
						$cmp = $char_count( $b['keyword'] ) - $char_count( $a['keyword'] );
						break;
					case 'lowest_char_count':
						$cmp = $char_count( $a['keyword'] ) - $char_count( $b['keyword'] );
						break;
					case 'first_configured':
					default:
						$cmp = 0;
						break;
				}

				// Stable fall-back on configuration order.
				return 0 !== $cmp ? $cmp : ( $a['seq'] - $b['seq'] );
			}
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Processing
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Public entry point for linking an arbitrary piece of content that belongs
	 * to a source (used by integrations such as the ACF bridge).
	 *
	 * Applies the same source-eligibility checks and limit pipeline as the
	 * built-in the_content handling.
	 *
	 * @param string $content     Content to process (plain text or HTML).
	 * @param int    $source_id   Source object ID.
	 * @param string $source_type Either 'post' or 'term'.
	 * @return string
	 */
	public function link_source_content( $content, $source_id, $source_type ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $content;
		}

		$source = array(
			'id'   => (int) $source_id,
			'type' => ( 'term' === $source_type ) ? 'term' : 'post',
		);

		if ( ! $this->is_source_allowed( $source ) ) {
			return $content;
		}

		return $this->link_html( $content, $source );
	}

	/**
	 * Returns the content with generated links applied (or unchanged).
	 *
	 * @param string $content Content to process.
	 * @param array  $source  Source descriptor.
	 * @return string
	 */
	private function link_html( $content, array $source ) {
		$resolved = $this->resolve( $content, $source );
		if ( ! $resolved || empty( $resolved['accepted'] ) ) {
			return $content;
		}

		$this->apply_placements( $resolved['dom'], $resolved['accepted'] );

		return $this->inner_html( $resolved['dom'], $resolved['root'] );
	}

	/**
	 * Runs the matching pipeline and returns the DOM, root and accepted
	 * placements.
	 *
	 * @param string $content Content to scan.
	 * @param array  $source  Source descriptor.
	 * @return array|null
	 */
	private function resolve( $content, array $source ) {
		$dom = $this->load_dom( $content );
		if ( ! $dom ) {
			return null;
		}

		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="ilb-root"]' )->item( 0 );
		if ( ! $root ) {
			return null;
		}

		$accepted = $this->resolve_in_root( $xpath, $root, $source );
		if ( null === $accepted ) {
			return null;
		}

		return array(
			'dom'      => $dom,
			'root'     => $root,
			'accepted' => $accepted,
		);
	}

	/**
	 * Runs the matching pipeline inside an already-parsed DOM subtree.
	 *
	 * @param DOMXPath $xpath          XPath helper for the document.
	 * @param DOMNode  $root           Subtree to process.
	 * @param array    $source         Source descriptor.
	 * @param array    $extra_excluded Additional always-excluded tag names.
	 * @return array|null Accepted placements, or null when nothing is linkable.
	 */
	private function resolve_in_root( DOMXPath $xpath, $root, array $source, array $extra_excluded = array() ) {
		$data       = $this->get_candidates( $source );
		$candidates = $data['candidates'];
		$lookup     = $data['lookup'];
		if ( empty( $candidates ) ) {
			return null;
		}

		$excluded = $this->excluded_tags();
		foreach ( $extra_excluded as $tag ) {
			$excluded[ $tag ] = true;
		}

		// Collect existing link URLs so we never duplicate a manual link.
		$existing_urls = array();
		if ( $this->settings->get( 'consider_existing_links' ) ) {
			foreach ( $xpath->query( './/a[@href]', $root ) as $anchor ) {
				$existing_urls[ $this->normalize_url( $anchor->getAttribute( 'href' ) ) ] = true;
			}
		}

		// Snapshot linkable text nodes with their paragraph bucket.
		$text_nodes = array();
		foreach ( $xpath->query( './/text()', $root ) as $node ) {
			$paragraph = $this->paragraph_key( $node, $excluded );
			if ( null === $paragraph ) {
				continue;
			}
			$text_nodes[] = array(
				'node'      => $node,
				'paragraph' => $paragraph,
				'index'     => count( $text_nodes ),
			);
		}

		if ( empty( $text_nodes ) ) {
			return null;
		}

		$pattern = $this->build_pattern( $candidates );
		if ( '' === $pattern ) {
			return null;
		}

		$placements = $this->collect_placements( $text_nodes, $pattern, $lookup );

		return $this->select_placements( $placements, $source, $existing_urls );
	}

	/**
	 * Links keywords inside a complete HTML document (universal mode).
	 *
	 * Parses the full page, locates the content region (the first match from
	 * the root XPath list) and runs the regular pipeline inside it. Chrome such
	 * as navigation, header, footer and forms is always excluded.
	 *
	 * @param string $html        Complete HTML document.
	 * @param array  $source      Source descriptor (id, type).
	 * @param array  $root_xpaths Optional XPath expressions for the content
	 *                            region; the first match wins. Defaults to
	 *                            common content wrappers, then body.
	 * @return string
	 */
	public function link_document( $html, array $source, array $root_xpaths = array() ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		if ( ! $this->is_source_allowed( $source ) ) {
			return $html;
		}

		// Preserve the original doctype: the UTF-8 hint we prepend for libxml
		// would otherwise displace it.
		$doctype = '';
		$body    = $html;
		if ( preg_match( '/^\s*<!DOCTYPE[^>]*>/i', $html, $matches ) ) {
			$doctype = trim( $matches[0] );
			$body    = substr( $html, strlen( $matches[0] ) );
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $body, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return $html;
		}

		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( XML_PI_NODE === $child->nodeType ) {
				$dom->removeChild( $child );
			}
		}

		$xpath = new DOMXPath( $dom );

		if ( empty( $root_xpaths ) ) {
			$root_xpaths = array(
				'//main',
				'//*[@role="main"]',
				'//*[@id="main"]',
				'//*[@id="content"]',
				'//*[@id="primary"]',
				'//body',
			);
		}

		/**
		 * Filters the XPath expressions used to locate the content region in
		 * universal mode. The first expression that matches wins.
		 *
		 * @param string[] $root_xpaths XPath expressions.
		 * @param array    $source      Source descriptor.
		 */
		$root_xpaths = apply_filters( 'ilb_universal_roots', $root_xpaths, $source );

		$root = null;
		foreach ( $root_xpaths as $expression ) {
			$match = $xpath->query( $expression );
			if ( $match && $match->length > 0 ) {
				$root = $match->item( 0 );
				break;
			}
		}

		if ( ! $root ) {
			return $html;
		}

		$accepted = $this->resolve_in_root( $xpath, $root, $source, self::document_chrome_tags() );
		if ( empty( $accepted ) ) {
			return $html;
		}

		$this->apply_placements( $dom, $accepted );

		// Serialise from the root element rather than the document: the
		// document serializer entity-encodes all non-ASCII characters, while
		// the node serializer keeps the page's UTF-8 intact.
		$output = $dom->documentElement ? $dom->saveHTML( $dom->documentElement ) : false;
		if ( false === $output || '' === $output ) {
			return $html;
		}

		return ( '' !== $doctype ) ? $doctype . "\n" . $output : $output;
	}

	/**
	 * Tag names that are never linked in whole-document (universal) mode, on
	 * top of the configured excluded areas: page chrome and form controls.
	 *
	 * @return string[]
	 */
	public static function document_chrome_tags() {
		return array( 'head', 'title', 'nav', 'header', 'footer', 'aside', 'form', 'button', 'select', 'textarea', 'label', 'noscript', 'svg', 'iframe' );
	}

	/**
	 * Loads content into a DOMDocument wrapped in a known root element.
	 *
	 * @param string $content Content.
	 * @return DOMDocument|null
	 */
	private function load_dom( $content ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="ilb-root">' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return null;
		}

		// Remove the XML processing instruction the encoding hint introduces.
		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( XML_PI_NODE === $child->nodeType ) {
				$dom->removeChild( $child );
			}
		}

		return $dom;
	}

	/**
	 * Returns the set of lowercase tag names whose text must be skipped.
	 *
	 * @return array<string,bool>
	 */
	private function excluded_tags() {
		$map = array(
			'headlines'  => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ),
			'strong'     => array( 'strong', 'b' ),
			'div'        => array( 'div' ),
			'table'      => array( 'table' ),
			'figcaption' => array( 'figcaption' ),
			'ol'         => array( 'ol' ),
			'ul'         => array( 'ul' ),
			'blockquote' => array( 'blockquote' ),
			'em'         => array( 'em', 'i' ),
			'cite'       => array( 'cite' ),
			'code'       => array( 'code' ),
		);

		// Never link inside anchors, scripts or styles.
		$excluded = array(
			'a'      => true,
			'script' => true,
			'style'  => true,
		);

		foreach ( (array) $this->settings->get( 'exclude_html_areas' ) as $area ) {
			if ( isset( $map[ $area ] ) ) {
				foreach ( $map[ $area ] as $tag ) {
					$excluded[ $tag ] = true;
				}
			}
		}

		return $excluded;
	}

	/**
	 * Determines a text node's paragraph bucket, or null when it is not
	 * linkable (inside an excluded area).
	 *
	 * @param DOMNode            $node     Text node.
	 * @param array<string,bool> $excluded Excluded tag names.
	 * @return string|null
	 */
	private function paragraph_key( $node, array $excluded ) {
		if ( '' === trim( $node->nodeValue ) ) {
			return null;
		}

		$paragraph = null;
		$parent    = $node->parentNode;
		while ( $parent instanceof DOMElement ) {
			$tag = strtolower( $parent->nodeName );

			if ( 'ilb-root' === $parent->getAttribute( 'id' ) ) {
				break;
			}

			if ( isset( $excluded[ $tag ] ) ) {
				return null;
			}

			if ( null === $paragraph && 'p' === $tag ) {
				$paragraph = 'p-' . spl_object_id( $parent );
			}

			$parent = $parent->parentNode;
		}

		// Text outside any <p> is bucketed by its direct parent.
		if ( null === $paragraph ) {
			$paragraph = 'n-' . spl_object_id( $node->parentNode );
		}

		return $paragraph;
	}

	/**
	 * Builds the combined whole-word match pattern, longest keyword first.
	 *
	 * @param array $candidates Candidate list.
	 * @return string Regex pattern, or '' when there is nothing to match.
	 */
	private function build_pattern( array $candidates ) {
		$keywords = array();
		foreach ( $candidates as $candidate ) {
			$keywords[] = $candidate['keyword'];
		}

		// Prefer the longest keyword at any given position.
		usort(
			$keywords,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$alts = array();
		foreach ( $keywords as $keyword ) {
			$alts[] = preg_quote( $keyword, '/' );
		}

		if ( empty( $alts ) ) {
			return '';
		}

		$flags = 'u';
		if ( ! $this->settings->get( 'case_sensitive' ) ) {
			$flags .= 'i';
		}

		// Whole-word boundaries that also respect accented characters.
		return '/(?<![\p{L}\p{N}_])(' . implode( '|', $alts ) . ')(?![\p{L}\p{N}_])/' . $flags;
	}

	/**
	 * Collects every potential placement across the snapshot of text nodes.
	 *
	 * @param array  $text_nodes Snapshot of linkable text nodes.
	 * @param string $pattern    Combined match pattern.
	 * @param array  $lookup     Lowercased keyword => candidate map.
	 * @return array[] Potential placements.
	 */
	private function collect_placements( array $text_nodes, $pattern, array $lookup ) {
		$placements = array();

		foreach ( $text_nodes as $entry ) {
			$text = $entry['node']->nodeValue;
			if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[1] as $match ) {
				$matched_text = $match[0];
				$offset       = $match[1];
				$lower        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $matched_text ) : strtolower( $matched_text );

				if ( ! isset( $lookup[ $lower ] ) ) {
					continue;
				}

				$placements[] = array(
					'node'      => $entry['node'],
					'node_idx'  => $entry['index'],
					'paragraph' => $entry['paragraph'],
					'offset'    => $offset,
					'length'    => strlen( $matched_text ),
					'anchor'    => $matched_text,
					'candidate' => $lookup[ $lower ],
				);
			}
		}

		return $placements;
	}

	/**
	 * Selects which placements to apply, honouring all link limits.
	 *
	 * @param array $placements    Potential placements.
	 * @param array $source        Source descriptor.
	 * @param array $existing_urls URLs already linked in the content.
	 * @return array[] Accepted placements, with a resolved target attached.
	 */
	private function select_placements( array $placements, array $source, array $existing_urls ) {
		$unlimited = (bool) $this->settings->get( 'link_as_often_as_possible' );

		$source_overrides = $this->get_overrides( $source['id'], $source['type'] );

		$max_post      = $unlimited ? 0 : (int) $this->settings->get( 'max_links_per_post' );
		$limit_para    = ! $unlimited && $this->settings->get( 'limit_links_per_paragraph' );
		$max_para      = (int) $this->settings->get( 'max_links_per_paragraph' );
		$max_frequency = $unlimited ? 0 : (int) $this->settings->get( 'max_link_frequency' );

		// Per-target override: a source flagged "limit outgoing links" always
		// honours the per-post cap, even when "link as often as possible" is on.
		if ( ! empty( $source_overrides['limit_outgoing_links'] ) ) {
			$configured_max = (int) $this->settings->get( 'max_links_per_post' );
			if ( $configured_max > 0 ) {
				$max_post = $configured_max;
			}
		}

		// Priority ordering: by keyword rank, then document order.
		usort(
			$placements,
			static function ( $a, $b ) {
				$cmp = $a['candidate']['rank'] - $b['candidate']['rank'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				$cmp = $a['node_idx'] - $b['node_idx'];
				return 0 !== $cmp ? $cmp : ( $a['offset'] - $b['offset'] );
			}
		);

		$total           = 0;
		$per_paragraph   = array();
		$per_target_para = array();
		$per_target      = array();
		$used_urls       = $existing_urls;
		$accepted        = array();
		$source_terms    = $this->source_terms( $source );

		foreach ( $placements as $placement ) {
			if ( $max_post > 0 && $total >= $max_post ) {
				break;
			}

			$para = $placement['paragraph'];
			if ( $limit_para && isset( $per_paragraph[ $para ] ) && $per_paragraph[ $para ] >= $max_para ) {
				continue;
			}

			$target = $this->choose_target( $placement['candidate'], $source, $source_terms, $used_urls, $per_target, $max_frequency, $unlimited );
			if ( ! $target ) {
				continue;
			}

			$key = $target['type'] . ':' . $target['id'];

			// Per-target override: limit links to this target per paragraph.
			$target_overrides = $this->get_overrides( $target['id'], $target['type'] );
			if ( ! $unlimited && ! empty( $target_overrides['limit_links_per_paragraph'] ) ) {
				$tp_key = $key . '|' . $para;
				if ( isset( $per_target_para[ $tp_key ] ) && $per_target_para[ $tp_key ] >= $max_para ) {
					continue;
				}
				$per_target_para[ $tp_key ] = isset( $per_target_para[ $tp_key ] ) ? $per_target_para[ $tp_key ] + 1 : 1;
			}

			// Commit.
			$accepted[] = array(
				'node'   => $placement['node'],
				'offset' => $placement['offset'],
				'length' => $placement['length'],
				'anchor' => $placement['anchor'],
				'target' => $target,
			);

			++$total;
			$per_target[ $key ]                                  = isset( $per_target[ $key ] ) ? $per_target[ $key ] + 1 : 1;
			$used_urls[ $this->normalize_url( $target['url'] ) ] = true;
			if ( $limit_para ) {
				$per_paragraph[ $para ] = isset( $per_paragraph[ $para ] ) ? $per_paragraph[ $para ] + 1 : 1;
			}
		}

		return $accepted;
	}

	/**
	 * Picks the first eligible target for a candidate keyword.
	 *
	 * @param array $candidate     Candidate definition.
	 * @param array $source        Source descriptor.
	 * @param array $source_terms  Source terms keyed by limiting taxonomy.
	 * @param array $used_urls     URLs already linked/selected.
	 * @param array $per_target    Per-target frequency counters.
	 * @param int   $max_frequency Max links per target (0 = unlimited).
	 * @param bool  $unlimited     Whether "link as often as possible" is on.
	 * @return array|null Resolved target or null.
	 */
	private function choose_target( array $candidate, array $source, array $source_terms, array $used_urls, array $per_target, $max_frequency, $unlimited ) {
		foreach ( $candidate['targets'] as $ref ) {
			// Never link a source to itself.
			if ( $ref['type'] === $source['type'] && (int) $ref['id'] === (int) $source['id'] ) {
				continue;
			}

			$key = $ref['type'] . ':' . $ref['id'];
			if ( $max_frequency > 0 && isset( $per_target[ $key ] ) && $per_target[ $key ] >= $max_frequency ) {
				continue;
			}

			$target = $this->resolve_target( $ref['id'], $ref['type'] );
			if ( ! $target ) {
				continue;
			}

			if ( isset( $used_urls[ $this->normalize_url( $target['url'] ) ] ) ) {
				continue;
			}

			if ( ! $this->target_passes_taxonomy_limit( $ref, $source_terms ) ) {
				continue;
			}

			if ( ! $this->target_passes_incoming_limit( $ref, $source, $unlimited ) ) {
				continue;
			}

			return $target;
		}

		return null;
	}

	/**
	 * Whether linking to a target stays within the global incoming-link limit.
	 *
	 * @param array $ref       Target reference (id, type).
	 * @param array $source    Source descriptor.
	 * @param bool  $unlimited Whether "link as often as possible" is on.
	 * @return bool
	 */
	private function target_passes_incoming_limit( array $ref, array $source, $unlimited ) {
		if ( $unlimited ) {
			return true;
		}

		// The limit applies when enabled globally or on the target itself.
		$enabled = (bool) $this->settings->get( 'limit_incoming_links' );
		if ( ! $enabled ) {
			$overrides = $this->get_overrides( $ref['id'], $ref['type'] );
			$enabled   = ! empty( $overrides['limit_incoming_links'] );
		}

		if ( ! $enabled ) {
			return true;
		}

		$max = (int) $this->settings->get( 'max_incoming_links' );
		if ( $max <= 0 ) {
			return true;
		}

		$current = $this->links->incoming_count( $ref['id'], $ref['type'], (int) $source['id'], $source['type'] );

		return $current < $max;
	}

	/**
	 * Returns the per-target override settings, cached per request.
	 *
	 * @param int    $id   Object ID.
	 * @param string $type 'post' or 'term'.
	 * @return array<string,int>
	 */
	private function get_overrides( $id, $type ) {
		$key = $type . ':' . $id;
		if ( ! isset( $this->override_cache[ $key ] ) ) {
			$this->override_cache[ $key ] = $this->keywords->get_target_settings( $id, $type );
		}

		return $this->override_cache[ $key ];
	}

	/**
	 * Resolves a target's URL, title and excerpt, caching per request.
	 *
	 * @param int    $id   Target ID.
	 * @param string $type 'post' or 'term'.
	 * @return array|null
	 */
	private function resolve_target( $id, $type ) {
		$key = $type . ':' . $id;
		if ( array_key_exists( $key, $this->target_cache ) ) {
			return $this->target_cache[ $key ];
		}

		$target = null;

		if ( 'term' === $type ) {
			$term = get_term( (int) $id );
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$target = array(
						'id'      => (int) $id,
						'type'    => 'term',
						'url'     => $link,
						'title'   => $term->name,
						'excerpt' => wp_strip_all_tags( $term->description ),
					);
				}
			}
		} else {
			$post = get_post( (int) $id );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				$target = array(
					'id'      => (int) $id,
					'type'    => 'post',
					'url'     => get_permalink( $post ),
					'title'   => get_the_title( $post ),
					'excerpt' => $this->post_excerpt( $post ),
				);
			}
		}

		$this->target_cache[ $key ] = $target;

		return $target;
	}

	/**
	 * Returns a plain-text excerpt for a post without triggering heavy filters.
	 *
	 * @param WP_Post $post Target post.
	 * @return string
	 */
	private function post_excerpt( $post ) {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '…' );
	}

	/**
	 * Returns the source's terms grouped by limiting taxonomy.
	 *
	 * Only post sources participate in taxonomy limiting.
	 *
	 * @param array $source Source descriptor.
	 * @return array<string,int[]>
	 */
	private function source_terms( array $source ) {
		if ( 'post' !== $source['type'] ) {
			return array();
		}

		$taxonomies = (array) $this->settings->get( 'limiting_taxonomies' );
		$terms      = array();

		foreach ( $taxonomies as $taxonomy ) {
			$ids                = wp_get_object_terms( $source['id'], $taxonomy, array( 'fields' => 'ids' ) );
			$terms[ $taxonomy ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
		}

		return $terms;
	}

	/**
	 * Whether a target shares a term with the source in every limiting
	 * taxonomy that applies to it.
	 *
	 * @param array $ref          Target reference (id, type).
	 * @param array $source_terms Source terms by taxonomy.
	 * @return bool
	 */
	private function target_passes_taxonomy_limit( array $ref, array $source_terms ) {
		if ( empty( $source_terms ) || 'post' !== $ref['type'] ) {
			return true;
		}

		foreach ( $source_terms as $taxonomy => $source_ids ) {
			if ( empty( $source_ids ) ) {
				continue;
			}

			$target_ids = wp_get_object_terms( $ref['id'], $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $target_ids ) ) {
				continue;
			}

			if ( empty( array_intersect( $source_ids, array_map( 'intval', $target_ids ) ) ) ) {
				return false;
			}
		}

		return true;
	}

	/*
	 * -------------------------------------------------------------------------
	 * DOM mutation & output
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Applies accepted placements to the DOM, splitting text nodes.
	 *
	 * @param DOMDocument $dom      Document.
	 * @param array       $accepted Accepted placements.
	 */
	private function apply_placements( DOMDocument $dom, array $accepted ) {
		// Group placements by node so each node is rebuilt once, left to right.
		$by_node = array();
		foreach ( $accepted as $placement ) {
			$id = spl_object_id( $placement['node'] );
			if ( ! isset( $by_node[ $id ] ) ) {
				$by_node[ $id ] = array(
					'node'       => $placement['node'],
					'placements' => array(),
				);
			}
			$by_node[ $id ]['placements'][] = $placement;
		}

		foreach ( $by_node as $group ) {
			$this->rebuild_node( $dom, $group['node'], $group['placements'] );
		}
	}

	/**
	 * Replaces a single text node with text fragments and anchor elements.
	 *
	 * @param DOMDocument $dom        Document.
	 * @param DOMNode     $node       Text node.
	 * @param array       $placements Placements within this node.
	 */
	private function rebuild_node( DOMDocument $dom, $node, array $placements ) {
		usort(
			$placements,
			static function ( $a, $b ) {
				return $a['offset'] - $b['offset'];
			}
		);

		$text   = $node->nodeValue;
		$parent = $node->parentNode;
		if ( ! $parent ) {
			return;
		}

		$cursor = 0;
		foreach ( $placements as $placement ) {
			$start = $placement['offset'];
			if ( $start < $cursor ) {
				continue; // Overlap guard (should not happen).
			}

			$before = substr( $text, $cursor, $start - $cursor );
			if ( '' !== $before ) {
				$parent->insertBefore( $dom->createTextNode( $before ), $node );
			}

			foreach ( $this->build_anchor_nodes( $dom, $placement ) as $anchor_node ) {
				$parent->insertBefore( $anchor_node, $node );
			}

			$cursor = $start + $placement['length'];
		}

		$after = substr( $text, $cursor );
		if ( '' !== $after ) {
			$parent->insertBefore( $dom->createTextNode( $after ), $node );
		}

		$parent->removeChild( $node );
	}

	/**
	 * Builds the anchor node(s) for a placement from the configured template.
	 *
	 * @param DOMDocument $dom       Document.
	 * @param array       $placement Placement (anchor text + resolved target).
	 * @return DOMNode[]
	 */
	private function build_anchor_nodes( DOMDocument $dom, array $placement ) {
		$target = $placement['target'];
		$html   = $this->render_template( $target['url'], $placement['anchor'], $target['title'], $target['excerpt'] );

		$fragment = new DOMDocument();
		$prev     = libxml_use_internal_errors( true );
		$fragment->loadHTML(
			'<?xml encoding="UTF-8"><div id="ilb-a">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath   = new DOMXPath( $fragment );
		$wrapper = $xpath->query( '//*[@id="ilb-a"]' )->item( 0 );
		if ( ! $wrapper ) {
			return array( $dom->createTextNode( $placement['anchor'] ) );
		}

		$nofollow = (bool) $this->settings->get( 'nofollow' );
		$nodes    = array();
		foreach ( iterator_to_array( $wrapper->childNodes ) as $child ) {
			$imported = $dom->importNode( $child, true );
			if ( $nofollow ) {
				$this->apply_nofollow( $imported );
			}
			$nodes[] = $imported;
		}

		return $nodes;
	}

	/**
	 * Adds rel="nofollow" to anchor elements within an imported node.
	 *
	 * @param DOMNode $node Imported node.
	 */
	private function apply_nofollow( $node ) {
		$anchors = array();
		if ( $node instanceof DOMElement && 'a' === strtolower( $node->nodeName ) ) {
			$anchors[] = $node;
		}
		if ( $node instanceof DOMElement ) {
			foreach ( $node->getElementsByTagName( 'a' ) as $descendant ) {
				$anchors[] = $descendant;
			}
		}

		foreach ( $anchors as $anchor ) {
			$rel    = trim( $anchor->getAttribute( 'rel' ) );
			$tokens = $rel ? preg_split( '/\s+/', $rel ) : array();
			if ( ! in_array( 'nofollow', $tokens, true ) ) {
				$tokens[] = 'nofollow';
			}
			$anchor->setAttribute( 'rel', implode( ' ', $tokens ) );
		}
	}

	/**
	 * Renders the link output template with escaped placeholder values.
	 *
	 * @param string $url     Target URL.
	 * @param string $anchor  Anchor text (the found keyword).
	 * @param string $title   Target title.
	 * @param string $excerpt Target excerpt.
	 * @return string
	 */
	private function render_template( $url, $anchor, $title, $excerpt ) {
		$template = (string) $this->settings->get( 'link_template' );
		if ( '' === trim( $template ) ) {
			$template = '<a href="{{url}}">{{anchor}}</a>';
		}

		return str_replace(
			array( '{{url}}', '{{anchor}}', '{{title}}', '{{excerpt}}' ),
			array(
				esc_url( $url ),
				esc_html( $anchor ),
				esc_html( $title ),
				esc_html( $excerpt ),
			),
			$template
		);
	}

	/**
	 * Serialises the inner HTML of the root wrapper.
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMNode     $root Root wrapper.
	 * @return string
	 */
	private function inner_html( DOMDocument $dom, $root ) {
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Normalises a URL for duplicate comparison.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES );
		$url = preg_replace( '#^https?://#i', '', $url );

		return untrailingslashit( strtolower( trim( $url ) ) );
	}
}
