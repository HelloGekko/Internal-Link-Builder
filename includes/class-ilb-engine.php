<?php
/**
 * Front-end linking engine.
 *
 * Scans post content on `the_content` and turns configured keywords into links
 * to their target, on the fly. The stored content is never modified.
 *
 * Parsing is done with DOMDocument so replacements only ever happen inside text
 * nodes — never inside tags, attributes, existing links or excluded HTML areas.
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

		// Run after wpautop (priority 10) so paragraphs exist as <p> elements.
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
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

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! $this->is_source_allowed( $post ) ) {
			return $content;
		}

		// Serve from cache when enabled and fresh.
		$use_cache   = (bool) $this->settings->get( 'cache' );
		$fingerprint = '';
		if ( $use_cache ) {
			$fingerprint = $this->fingerprint( $content, $post );
			$cached      = get_post_meta( $post->ID, self::CACHE_META, true );
			if ( is_array( $cached ) && isset( $cached['key'] ) && $cached['key'] === $fingerprint ) {
				return $cached['html'];
			}
		}

		$result = $this->process( $content, $post );

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
	 * Computes the cache fingerprint for a post's content.
	 *
	 * @param string  $content Post content.
	 * @param WP_Post $post    Source post.
	 * @return string
	 */
	private function fingerprint( $content, $post ) {
		return md5( $content . '|' . ILB_Index::token() . '|' . wp_json_encode( $this->settings->all() ) . '|' . $post->ID );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Source eligibility
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Whether a post is allowed to link out to others.
	 *
	 * @param WP_Post $post Source post.
	 * @return bool
	 */
	private function is_source_allowed( $post ) {
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

		$overrides = $this->keywords->get_target_settings( $post->ID, 'post' );
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
	 * Builds the ordered candidate list for a source post.
	 *
	 * @param WP_Post $post Source post.
	 * @return array {
	 *     @type array  $candidates Ordered list of candidates (keyword, lower, rank, targets[]).
	 *     @type array  $lookup     Map of lowercased keyword => candidate.
	 * }
	 */
	private function get_candidates( $post ) {
		$rows = $this->index->all_rows();
		if ( empty( $rows ) ) {
			return array(
				'candidates' => array(),
				'lookup'     => array(),
			);
		}

		// Keywords the source post explicitly excludes from linking.
		$blocked = array_map( 'strtolower', $this->keywords->get_content_blacklist( $post->ID, 'post' ) );
		$blocked = array_flip( $blocked );

		$by_keyword = array();
		$seq        = 0;
		foreach ( $rows as $row ) {
			$lower = $row['keyword_lower'];
			if ( isset( $blocked[ $lower ] ) ) {
				continue;
			}

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

		$candidates = array_values( $by_keyword );
		$this->sort_candidates( $candidates, (string) $this->settings->get( 'keyword_order' ) );

		$lookup = array();
		foreach ( $candidates as $rank => $candidate ) {
			$candidate['rank']                    = $rank;
			$candidates[ $rank ]['rank']          = $rank;
			$lookup[ $candidate['lower'] ]        = $candidates[ $rank ];
		}

		return array(
			'candidates' => $candidates,
			'lookup'     => $lookup,
		);
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
	 * Generates linked HTML for the given content.
	 *
	 * @param string  $content Post content.
	 * @param WP_Post $post    Source post.
	 * @return string
	 */
	private function process( $content, $post ) {
		$resolved = $this->resolve( $content, $post );
		if ( ! $resolved || empty( $resolved['accepted'] ) ) {
			return $content;
		}

		$this->apply_placements( $resolved['dom'], $resolved['accepted'] );

		return $this->inner_html( $resolved['dom'], $resolved['root'] );
	}

	/**
	 * Computes the links a source post would generate, without rendering.
	 *
	 * Used by the generator to build the link graph. Runs the exact same
	 * matching and limit pipeline as the front-end render.
	 *
	 * @param WP_Post $post Source post.
	 * @return array[] List of links: each [target_id, target_type, keyword].
	 */
	public function compute_links( WP_Post $post ) {
		if ( ! $this->is_source_allowed( $post ) ) {
			return array();
		}

		// Approximate the rendered structure: paragraphs via wpautop, without
		// executing shortcodes (which may have side effects during generation).
		$content  = wpautop( $post->post_content );
		$resolved = $this->resolve( $content, $post );
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

	/**
	 * Runs the matching pipeline and returns the DOM, root and accepted
	 * placements.
	 *
	 * @param string  $content Content to scan.
	 * @param WP_Post $post    Source post.
	 * @return array|null
	 */
	private function resolve( $content, $post ) {
		$data       = $this->get_candidates( $post );
		$candidates = $data['candidates'];
		$lookup     = $data['lookup'];
		if ( empty( $candidates ) ) {
			return null;
		}

		$dom = $this->load_dom( $content );
		if ( ! $dom ) {
			return null;
		}

		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="ilb-root"]' )->item( 0 );
		if ( ! $root ) {
			return null;
		}

		$excluded = $this->excluded_tags();

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
		$accepted   = $this->select_placements( $placements, $post, $existing_urls );

		return array(
			'dom'      => $dom,
			'root'     => $root,
			'accepted' => $accepted,
		);
	}

	/**
	 * Loads content into a DOMDocument wrapped in a known root element.
	 *
	 * @param string $content Post content.
	 * @return DOMDocument|null
	 */
	private function load_dom( $content ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . '<div id="ilb-root">' . $content . '</div>',
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

		// The wrapper div is always excluded as a tag name, but its descendants
		// are walked; so we deliberately do not add "div" unless configured.
		return $excluded;
	}

	/**
	 * Determines a text node's paragraph bucket, or null when it is not
	 * linkable (inside an excluded area).
	 *
	 * @param DOMNode             $node     Text node.
	 * @param array<string,bool>  $excluded Excluded tag names.
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

				$candidate    = $lookup[ $lower ];
				$placements[] = array(
					'node'      => $entry['node'],
					'node_idx'  => $entry['index'],
					'paragraph' => $entry['paragraph'],
					'offset'    => $offset,
					'length'    => strlen( $matched_text ),
					'anchor'    => $matched_text,
					'candidate' => $candidate,
				);
			}
		}

		return $placements;
	}

	/**
	 * Selects which placements to apply, honouring all link limits.
	 *
	 * @param array   $placements    Potential placements.
	 * @param WP_Post $post          Source post.
	 * @param array   $existing_urls URLs already linked in the content.
	 * @return array[] Accepted placements, with a resolved target attached.
	 */
	private function select_placements( array $placements, $post, array $existing_urls ) {
		$unlimited = (bool) $this->settings->get( 'link_as_often_as_possible' );

		$max_post      = $unlimited ? 0 : (int) $this->settings->get( 'max_links_per_post' );
		$limit_para    = ! $unlimited && $this->settings->get( 'limit_links_per_paragraph' );
		$max_para      = (int) $this->settings->get( 'max_links_per_paragraph' );
		$max_frequency = $unlimited ? 0 : (int) $this->settings->get( 'max_link_frequency' );

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

		$total          = 0;
		$per_paragraph  = array();
		$per_target     = array();
		$used_urls      = $existing_urls;
		$accepted       = array();
		$source_terms   = $this->source_terms( $post );

		foreach ( $placements as $placement ) {
			if ( $max_post > 0 && $total >= $max_post ) {
				break;
			}

			if ( $limit_para ) {
				$para = $placement['paragraph'];
				if ( isset( $per_paragraph[ $para ] ) && $per_paragraph[ $para ] >= $max_para ) {
					continue;
				}
			}

			$target = $this->choose_target( $placement['candidate'], $post, $source_terms, $used_urls, $per_target, $max_frequency, $unlimited );
			if ( ! $target ) {
				continue;
			}

			$key = $target['type'] . ':' . $target['id'];

			// Commit.
			$accepted[] = array(
				'node'   => $placement['node'],
				'offset' => $placement['offset'],
				'length' => $placement['length'],
				'anchor' => $placement['anchor'],
				'target' => $target,
			);

			$total++;
			$per_target[ $key ] = isset( $per_target[ $key ] ) ? $per_target[ $key ] + 1 : 1;
			$used_urls[ $this->normalize_url( $target['url'] ) ] = true;
			if ( $limit_para ) {
				$para                   = $placement['paragraph'];
				$per_paragraph[ $para ] = isset( $per_paragraph[ $para ] ) ? $per_paragraph[ $para ] + 1 : 1;
			}
		}

		return $accepted;
	}

	/**
	 * Picks the first eligible target for a candidate keyword.
	 *
	 * @param array   $candidate     Candidate definition.
	 * @param WP_Post $post          Source post.
	 * @param array   $source_terms  Source terms keyed by limiting taxonomy.
	 * @param array   $used_urls     URLs already linked/selected.
	 * @param array   $per_target    Per-target frequency counters.
	 * @param int     $max_frequency Max links per target (0 = unlimited).
	 * @param bool    $unlimited     Whether "link as often as possible" is on.
	 * @return array|null Resolved target or null.
	 */
	private function choose_target( array $candidate, $post, array $source_terms, array $used_urls, array $per_target, $max_frequency, $unlimited ) {
		foreach ( $candidate['targets'] as $ref ) {
			// Never link a post to itself.
			if ( 'post' === $ref['type'] && (int) $ref['id'] === (int) $post->ID ) {
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

			if ( ! $this->target_passes_incoming_limit( $ref, $post, $unlimited ) ) {
				continue;
			}

			return $target;
		}

		return null;
	}

	/**
	 * Whether linking to a target stays within the global incoming-link limit.
	 *
	 * The count is read from the link graph, excluding the current source so a
	 * source already credited with the link can still render it. During graph
	 * generation the count accumulates as earlier sources are written.
	 *
	 * @param array   $ref       Target reference (id, type).
	 * @param WP_Post $post      Source post.
	 * @param bool    $unlimited Whether "link as often as possible" is on.
	 * @return bool
	 */
	private function target_passes_incoming_limit( array $ref, $post, $unlimited ) {
		if ( $unlimited ) {
			return true;
		}

		// The limit applies when enabled globally or on the target itself.
		$enabled = (bool) $this->settings->get( 'limit_incoming_links' );
		if ( ! $enabled ) {
			$overrides = $this->keywords->get_target_settings( $ref['id'], $ref['type'] );
			$enabled   = ! empty( $overrides['limit_incoming_links'] );
		}

		if ( ! $enabled ) {
			return true;
		}

		$max = (int) $this->settings->get( 'max_incoming_links' );
		if ( $max <= 0 ) {
			return true;
		}

		$current = $this->links->incoming_count( $ref['id'], $ref['type'], (int) $post->ID, 'post' );

		return $current < $max;
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
	 * Returns the source post's terms grouped by limiting taxonomy.
	 *
	 * @param WP_Post $post Source post.
	 * @return array<string,int[]>
	 */
	private function source_terms( $post ) {
		$taxonomies = (array) $this->settings->get( 'limiting_taxonomies' );
		$terms      = array();

		foreach ( $taxonomies as $taxonomy ) {
			$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
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
			'<?xml encoding="UTF-8">' . '<div id="ilb-a">' . $html . '</div>',
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
