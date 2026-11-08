<?php
/**
 * EqualFix Accessibility — Fix Engine
 *
 * Applies Fix Set A (the free, built-in, forever-unlocked fix set) to rendered
 * HTML via output buffering. This class is intentionally WordPress-independent
 * (settings are injected) so the logic can be unit-tested outside WordPress.
 *
 * Fix Set A (complete forever):
 *  - lang attribute on <html> when missing
 *  - alt="" on decorative images missing alt (never invents descriptive text)
 *  - aria-label on icon-only buttons/links with no accessible name (derived, never invented)
 *  - associate form inputs with visible labels where a unique match exists
 *  - skip-to-content link injection where the theme lacks one
 *
 * Safety contract: non-destructive (rendered output only), reversible
 * (deactivation restores original output), scoped (no global text replacement).
 * On any parse failure the original HTML is returned untouched.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

class EqualFix_Fixer {

	/**
	 * Fix type identifiers.
	 */
	const FIX_LANG        = 'lang';
	const FIX_ALT         = 'alt';
	const FIX_ARIA_LABEL  = 'aria_label';
	const FIX_LABEL_ASSOC = 'label_assoc';
	const FIX_SKIP_LINK   = 'skip_link';

	/**
	 * Settings array.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Records of fixes applied during the last run.
	 *
	 * @var array
	 */
	private $applied = array();

	/**
	 * Whether the last run bailed out safely.
	 *
	 * @var bool
	 */
	private $bailed = false;

	/**
	 * Nodes this run CREATED (Fix-First Waves 3+4): caption
	 * tracks, transcript blocks, the interactive key handler.
	 * The tiered diff recognises insertions by object identity
	 * — never by markup resemblance — so a page that happens
	 * to contain similar markup can never be mistaken for one
	 * of ours. The node OBJECTS are held (not spl_object_id:
	 * PHP mints fresh DOM wrapper objects per access, so an
	 * id captured at creation need not match the id of the
	 * same node enumerated later — the first wp56 run proved
	 * exactly that, off by one wrapper).
	 *
	 * @var DOMNode[]
	 */
	private $inserted_nodes = array();

	/**
	 * Whether an interactive-kit rule stamped an element on
	 * this page (the key handler script is owed only then).
	 *
	 * @var bool
	 */
	private $interactive_stamped = false;

	/**
	 * Whether this run is the tiered full-page delivery (the
	 * only path allowed to add the interactive script — a
	 * content fragment must never gain a <script>).
	 *
	 * @var bool
	 */
	private $tiered_delivery = false;

	/**
	 * Constructor.
	 *
	 * @param array $settings Settings. Keys: fix_lang, fix_alt, fix_aria_label,
	 *                        fix_label_assoc, fix_skip_link (bool), excluded_selectors
	 *                        (array of simple selectors), locale (string, e.g. 'en-US'),
	 *                        skip_link_text (string).
	 */
	public function __construct( array $settings = array() ) {
		$defaults = array(
			'fix_lang'            => true,
			'fix_alt'             => true,
			'fix_aria_label'      => true,
			'fix_label_assoc'     => true,
			'fix_skip_link'       => true,
			'excluded_selectors'  => array(),
			'locale'              => 'en-US',
			'skip_link_text'      => 'Skip to content',
			// Owner-written rules (next batch): the Findings
			// rows' own words, in Pro's rule shapes, applied as
			// attribute ops only so the tiered engine's diff
			// stays valid. page_url scopes page-bound rules.
			'manual_alt_rules'    => array(),
			'manual_name_rules'   => array(),
			'manual_label_rules'  => array(),
			'manual_title_rules'  => array(),
			// Owner-approved attribute rules (Fix-First,
			// 2026-11-07): deterministic fixes the owner
			// approved from a proposal panel; same delivery
			// discipline as the manual stores.
			'manual_attr_rules'   => array(),
			// Owner-supplied inserted content (Fix-First
			// Waves 3+4, 2026-11-07): caption tracks and
			// transcript blocks the owner provided, inserted
			// at render through the tiered engine's marked-
			// insertion path.
			'manual_insert_rules' => array(),
			'page_url'            => '',
		);
		$this->settings = array_merge( $defaults, $settings );
	}

	/**
	 * Get records of fixes applied in the last run.
	 *
	 * @return array Each record: type, element, before, after.
	 */
	public function get_applied() {
		return $this->applied;
	}

	/**
	 * Whether the last run bailed out and returned the original HTML.
	 *
	 * @return bool
	 */
	public function did_bail() {
		return $this->bailed;
	}

	/**
	 * Apply enabled fixes to a full HTML document.
	 *
	 * @param string $html Full HTML document.
	 * @return string Fixed HTML, or the original HTML on any failure.
	 */
	public function fix( $html ) {
		// 1.0: live delivery is the tiered surgical engine — the
		// full-page parse/re-serialize path is deleted. The DOM rule
		// engine still decides WHAT changes; only the delivery
		// mechanics changed (see fix_page_tiered()).
		return $this->fix_page_tiered( $html );
	}

	/**
	 * Tiered fix delivery for a full page (1.0, WP3).
	 *
	 * The page is parsed twice with the HTML5 parser: a pristine
	 * analysis copy and a working copy the rule engine mutates. The
	 * difference between the two trees is translated into surgical
	 * operations on the ORIGINAL string — attribute splices on
	 * parser-anchored tags, plus the skip-link insertion after the
	 * body tag. Everything outside the spliced substrings passes
	 * through byte-identical; scripts, styles and templates are
	 * never inside a splice region.
	 *
	 * Canary discipline (Lovable Mod 2): each operation must locate
	 * its target uniquely — DOM matches and source matches for a
	 * target descriptor must agree in count, paired positionally —
	 * or the operation stands down. A structural surprise anywhere
	 * in the diff aborts the whole page: the original is served
	 * untouched rather than risk a partial rewrite.
	 *
	 * @param string $html Full page HTML.
	 * @return string Fixed HTML, or the original untouched.
	 */
	public function fix_page_tiered( $html ) {
		$this->applied             = array();
		$this->bailed              = false;
		$this->inserted_nodes      = array();
		$this->interactive_stamped = false;
		$this->tiered_delivery     = true;

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			$this->bailed = true;
			return $html;
		}
		$trimmed = ltrim( $html );
		if ( '' === $trimmed || '<' !== $trimmed[0] ) {
			$this->bailed = true;
			return $html;
		}
		try {
			$pristine = $this->parse( $html );
			$work     = $this->parse( $html );
			if ( ! $pristine || ! $work ) {
				$this->bailed = true;
				return $html;
			}
			$this->apply_fixes( $work );
			if ( empty( $this->applied ) ) {
				return $html;
			}
			$ops = array();
			if ( ! $this->diff_walk( $pristine->documentElement, $work->documentElement, $ops ) ) {
				// Structural surprise: serve the original page.
				$this->bailed = true;
				return $html;
			}
			if ( empty( $ops ) ) {
				return $html;
			}
			$out = $this->apply_ops( $html, $pristine, $ops );
			if ( null === $out ) {
				$this->bailed = true;
				return $html;
			}
			return $out;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			$this->bailed = true;
			return $html;
		}
	}

	/**
	 * Walk the pristine and mutated trees in parallel, collecting
	 * surgical operations. Returns false on any structural surprise
	 * (child sequences that cannot be reconciled).
	 *
	 * @param DOMElement $a    Pristine element.
	 * @param DOMElement $b    Mutated element.
	 * @param array      $ops  Collected operations (by reference).
	 * @return bool
	 */
	private function diff_walk( $a, $b, &$ops ) {
		if ( $b->attributes ) {
			foreach ( $b->attributes as $attr ) {
				if ( ! $a->hasAttribute( $attr->name ) || $a->getAttribute( $attr->name ) !== $attr->value ) {
					$ops[] = array(
						'kind'  => 'attr',
						'el'    => $a,
						'name'  => $attr->name,
						'value' => $attr->value,
					);
				}
			}
		}
		$ca = $this->element_children( $a );
		$cb = $this->element_children( $b );
		if ( count( $ca ) !== count( $cb ) ) {
			// Node-inserting fixes reconcile here: the skip link
			// (anchor + style, both marked) at the start of
			// <body>, and the Waves 3+4 insertions — nodes this
			// run CREATED, recognised by object identity in
			// $this->inserted_nodes (a caption track inside its
			// <video>, a transcript block right after its media
			// element, the interactive key handler in <body>).
			// Anything else that changes a child count is a
			// structural surprise: the page bails untouched.
			$kept      = array();
			$inserted  = array();
			$body_html = '';
			foreach ( $cb as $child ) {
				if ( $this->is_inserted_node( $child )
					|| ( 'body' === strtolower( $a->tagName ) && $this->is_skip_insert( $child ) ) ) {
					$inserted[] = $child;
					continue;
				}
				$kept[] = $child;
			}
			if ( empty( $inserted ) || count( $kept ) !== count( $ca ) ) {
				return false;
			}
			// Classify each insertion into a splice op. The
			// walk through $cb repeats so a transcript can find
			// the kept sibling it follows.
			$kept_seen = 0;
			foreach ( $cb as $child ) {
				if ( ! $this->is_inserted_node( $child )
					&& ! ( 'body' === strtolower( $a->tagName ) && $this->is_skip_insert( $child ) ) ) {
					$kept_seen++;
					continue;
				}
				$child_tag = strtolower( $child->tagName );
				if ( 'track' === $child_tag && in_array( strtolower( $a->tagName ), array( 'video', 'audio' ), true ) ) {
					// A caption track joins its media element
					// as a child: splice just before the media
					// element's end tag, after any <source>
					// children (their order is untouched).
					$ops[] = array(
						'kind'  => 'insert_media_end',
						'el'    => $a,
						'where' => 'before',
						'html'  => $b->ownerDocument->saveHTML( $child ),
					);
					continue;
				}
				if ( 'div' === $child_tag && 'transcript' === $child->getAttribute( 'data-equalfix' ) ) {
					// A transcript block belongs immediately
					// AFTER its media element: the kept sibling
					// right before it must be that element.
					if ( $kept_seen < 1 ) {
						return false;
					}
					$anchor = $ca[ $kept_seen - 1 ];
					if ( ! in_array( strtolower( $anchor->tagName ), array( 'video', 'audio' ), true ) ) {
						return false;
					}
					$ops[] = array(
						'kind'  => 'insert_media_end',
						'el'    => $anchor,
						'where' => 'after',
						'html'  => $b->ownerDocument->saveHTML( $child ),
					);
					continue;
				}
				if ( 'body' === strtolower( $a->tagName ) ) {
					// Skip link + key handler: spliced in
					// right after the <body> tag, in the
					// order they were created.
					$body_html .= $b->ownerDocument->saveHTML( $child );
					continue;
				}
				return false;
			}
			if ( '' !== $body_html ) {
				$ops[] = array(
					'kind' => 'insert_body_start',
					'html' => $body_html,
				);
			}
			$cb = $kept;
		}
		foreach ( $ca as $i => $child_a ) {
			if ( strtolower( $child_a->tagName ) !== strtolower( $cb[ $i ]->tagName ) ) {
				return false;
			}
			if ( ! $this->diff_walk( $child_a, $cb[ $i ], $ops ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Element children of a node, in document order.
	 *
	 * @param DOMNode $node Node.
	 * @return DOMElement[]
	 */
	private function element_children( $node ) {
		$out = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Whether a node is one of the skip-link fix's insertions.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private function is_skip_insert( $el ) {
		if ( 'a' === strtolower( $el->tagName ) && false !== strpos( ' ' . $el->getAttribute( 'class' ) . ' ', ' equalfix-skip-link ' ) ) {
			return true;
		}
		return 'style' === strtolower( $el->tagName ) && 'skip-link-style' === $el->getAttribute( 'data-equalfix' );
	}

	/**
	 * Apply surgical operations to the original HTML string.
	 *
	 * @param string      $html     Original page HTML.
	 * @param DOMDocument $pristine Pristine parse (for ordinals).
	 * @param array       $ops      Operations from diff_walk().
	 * @return string|null Fixed HTML, or null when nothing applied.
	 */
	private function apply_ops( $html, $pristine, $ops ) {
		$spans   = $this->shield_spans( $html );
		$splices = array(); // [offset, insert_text]
		foreach ( $ops as $op ) {
			if ( 'insert_body_start' === $op['kind'] ) {
				$body = $this->find_source_tags( $html, 'body', $spans );
				if ( 1 !== count( $body ) ) {
					continue;
				}
				$splices[] = array( $body[0]['end'], 0, $op['html'] );
				continue;
			}
			if ( 'insert_media_end' === $op['kind'] ) {
				// Waves 3+4: splice next to a media element's
				// end tag (a track just inside it, a transcript
				// just after it). The anchor is located with
				// the same descriptor/peer canary as an
				// attribute splice; the end tag must sit
				// outside every shielded span.
				$el        = $op['el'];
				$tag       = strtolower( $el->tagName );
				$desc      = $this->descriptor( $el );
				$dom_peers = $this->dom_descriptor_peers( $pristine, $tag, $desc );
				$src_tags  = $this->find_source_tags( $html, $tag, $spans );
				$src_peers = array();
				foreach ( $src_tags as $cand ) {
					if ( $this->attrs_match( $cand['attrs'], $desc ) ) {
						$src_peers[] = $cand;
					}
				}
				if ( count( $dom_peers ) !== count( $src_peers ) || 0 === count( $dom_peers ) ) {
					continue;
				}
				$ordinal = null;
				foreach ( $dom_peers as $i => $peer ) {
					if ( $peer === $el ) {
						$ordinal = $i;
						break;
					}
				}
				if ( null === $ordinal || ! isset( $src_peers[ $ordinal ] ) ) {
					continue;
				}
				$end = $this->find_end_tag( $html, $tag, $src_peers[ $ordinal ]['end'], $spans );
				if ( null === $end ) {
					continue;
				}
				$splices[] = array( 'before' === $op['where'] ? $end[0] : $end[1], 0, $op['html'] );
				continue;
			}
			// Attribute splice on a parser-anchored element.
			$el        = $op['el'];
			$tag       = strtolower( $el->tagName );
			$desc      = $this->descriptor( $el );
			$dom_peers = $this->dom_descriptor_peers( $pristine, $tag, $desc );
			$src_tags  = $this->find_source_tags( $html, $tag, $spans );
			$src_peers = array();
			foreach ( $src_tags as $cand ) {
				if ( $this->attrs_match( $cand['attrs'], $desc ) ) {
					$src_peers[] = $cand;
				}
			}
			// Canary: counts must agree, paired positionally.
			if ( count( $dom_peers ) !== count( $src_peers ) || 0 === count( $dom_peers ) ) {
				continue;
			}
			$ordinal = null;
			foreach ( $dom_peers as $i => $peer ) {
				if ( $peer === $el ) {
					$ordinal = $i;
					break;
				}
			}
			if ( null === $ordinal || ! isset( $src_peers[ $ordinal ] ) ) {
				continue;
			}
			$target = $src_peers[ $ordinal ];
			$quoted = '"' . htmlspecialchars( $op['value'], ENT_QUOTES, 'UTF-8' ) . '"';
			if ( isset( $target['attrs'][ strtolower( $op['name'] ) ] ) ) {
				// The attribute exists with a different (usually
				// empty) value: replace it in place — inserting a
				// duplicate attribute would be ignored by browsers,
				// which honour the first occurrence.
				$tag_text = substr( $html, $target['start'], $target['end'] - $target['start'] );
				// The attribute may be valueless in the source
				// (<a aria-label title="…">): the value group is
				// optional, or the replacement silently drops
				// (WP7 Lovable-review find). The whitespace
				// lookbehind anchors the match to a whole
				// attribute name, never the tail of data-* names.
				if ( preg_match( '/(?<=\s)' . preg_quote( $op['name'], '/' ) . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/i', $tag_text, $am, PREG_OFFSET_CAPTURE ) ) {
					$splices[] = array( $target['start'] + $am[0][1], strlen( $am[0][0] ), $op['name'] . '=' . $quoted );
				}
				continue;
			}
			$insert_at = $target['end'] - 1;
			if ( $target['self_closing'] ) {
				// Insert BEFORE the slash, wherever it sits:
				// "/>" and " />" both place it at end-2, but a
				// gapped "/ >" tail is longer (WP7 hardening).
				$tag_text = substr( $html, $target['start'], $target['end'] - $target['start'] );
				if ( preg_match( '/\/\s*>$/', $tag_text, $sm ) ) {
					$insert_at = $target['end'] - strlen( $sm[0] );
				}
			}
			$splices[] = array( $insert_at, 0, ' ' . $op['name'] . '=' . $quoted );
		}
		if ( empty( $splices ) ) {
			return null;
		}
		// Apply from the end backwards so offsets stay valid.
		usort(
			$splices,
			function ( $x, $y ) {
				return $y[0] <=> $x[0];
			}
		);
		foreach ( $splices as $s ) {
			$html = substr_replace( $html, $s[2], $s[0], $s[1] );
		}
		return $html;
	}

	/**
	 * Whether a node is one this run created (identity
	 * comparison against the held node objects).
	 *
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private function is_inserted_node( $node ) {
		foreach ( $this->inserted_nodes as $inserted ) {
			if ( $inserted === $node ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Locate an element's end tag in the source, searching
	 * from a byte offset and skipping shielded spans (script
	 * bodies, comments — a "</video>" inside a script string
	 * is not an end tag). Media elements never nest inside
	 * themselves in parsed content, so the first unshielded
	 * match after the opening tag is the element's own.
	 *
	 * @param string $html  Source HTML.
	 * @param string $tag   Tag name (lowercase).
	 * @param int    $from  Byte offset to start from.
	 * @param array  $spans Shield spans from shield_spans().
	 * @return array|null [start, end] byte offsets of the end tag.
	 */
	private function find_end_tag( $html, $tag, $from, $spans ) {
		if ( ! preg_match_all( '/<\/' . preg_quote( $tag, '/' ) . '\s*>/i', $html, $m, PREG_OFFSET_CAPTURE, (int) $from ) ) {
			return null;
		}
		foreach ( $m[0] as $match ) {
			$start = $match[1];
			$inside = false;
			foreach ( $spans as $span ) {
				if ( $start >= $span[0] && $start < $span[1] ) {
					$inside = true;
					break;
				}
			}
			if ( ! $inside ) {
				return array( $start, $start + strlen( $match[0] ) );
			}
		}
		return null;
	}

	/**
	 * The descriptor of an element: tag + original attributes.
	 *
	 * @param DOMElement $el Element.
	 * @return array<string,string>
	 */
	private function descriptor( $el ) {
		$out = array();
		if ( $el->attributes ) {
			foreach ( $el->attributes as $attr ) {
				$out[ strtolower( $attr->name ) ] = $attr->value;
			}
		}
		return $out;
	}

	/**
	 * All elements in the pristine document sharing a descriptor.
	 *
	 * @param DOMDocument $dom  Pristine document.
	 * @param string      $tag  Tag name.
	 * @param array       $desc Descriptor attributes.
	 * @return DOMElement[]
	 */
	private function dom_descriptor_peers( $dom, $tag, $desc ) {
		$out = array();
		foreach ( $dom->getElementsByTagName( $tag ) as $el ) {
			if ( $this->descriptor( $el ) === $desc ) {
				$out[] = $el;
			}
		}
		return $out;
	}

	/**
	 * Whether a source tag's parsed attributes equal a descriptor
	 * (values compared both raw and entity-decoded).
	 *
	 * @param array $attrs Source attributes.
	 * @param array $desc  Descriptor attributes.
	 * @return bool
	 */
	private function attrs_match( $attrs, $desc ) {
		if ( count( $attrs ) !== count( $desc ) ) {
			return false;
		}
		foreach ( $desc as $name => $value ) {
			if ( ! isset( $attrs[ $name ] ) ) {
				return false;
			}
			$src = $attrs[ $name ];
			if ( $src !== $value && html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Byte spans of the original HTML that splices must never touch:
	 * comments, CDATA, and script/style/template element bodies.
	 *
	 * @param string $html HTML.
	 * @return array[] List of [start, end] offsets.
	 */
	private function shield_spans( $html ) {
		$spans = array();
		$pats  = array(
			'/<!--.*?-->/s',
			'/<!\[CDATA\[.*?\]\]>/s',
			'#<(script|style|template)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>#is',
		);
		foreach ( $pats as $pat ) {
			if ( preg_match_all( $pat, $html, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $hit ) {
					$spans[] = array( $hit[1], $hit[1] + strlen( $hit[0] ) );
				}
			}
		}
		return $spans;
	}

	/**
	 * Locate every source tag with a given name outside shielded
	 * spans, with its parsed attributes and end offset (just past
	 * the closing '>').
	 *
	 * @param string $html  HTML.
	 * @param string $tag   Tag name.
	 * @param array  $spans Shield spans from shield_spans().
	 * @return array[] Each: {attrs, end, self_closing}
	 */
	private function find_source_tags( $html, $tag, $spans ) {
		$out = array();
		if ( ! preg_match_all( '/<' . preg_quote( $tag, '/' ) . '(?=[\s\/>])/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		$len = strlen( $html );
		foreach ( $m[0] as $hit ) {
			$start = $hit[1];
			foreach ( $spans as $span ) {
				if ( $start >= $span[0] && $start < $span[1] ) {
					continue 2;
				}
			}
			// Scan to the closing '>', respecting quoted values.
			$i     = $start + strlen( $hit[0] );
			$quote = '';
			while ( $i < $len ) {
				$ch = $html[ $i ];
				if ( '' !== $quote ) {
					if ( $ch === $quote ) {
						$quote = '';
					}
				} elseif ( '"' === $ch || "'" === $ch ) {
					$quote = $ch;
				} elseif ( '>' === $ch ) {
					break;
				}
				$i++;
			}
			if ( $i >= $len ) {
				continue;
			}
			$tag_text = substr( $html, $start, $i - $start + 1 );
			$out[]    = array(
				'attrs'        => $this->parse_tag_attrs( $tag_text ),
				'start'        => $start,
				'end'          => $i + 1,
				'self_closing' => (bool) preg_match( '/\/\s*>$/', $tag_text ),
			);
		}
		return $out;
	}

	/**
	 * Parse a source tag's attributes (name => value, names lowered).
	 *
	 * @param string $tag_text Full tag text, e.g. '<img src="x">'.
	 * @return array<string,string>
	 */
	private function parse_tag_attrs( $tag_text ) {
		$attrs = array();
		if ( preg_match_all( '/([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $tag_text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $i => $a ) {
				if ( 0 === $i ) {
					continue; // The tag name itself.
				}
				$value = '';
				foreach ( array( 2, 3, 4 ) as $g ) {
					if ( isset( $a[ $g ] ) && '' !== $a[ $g ] ) {
						$value = $a[ $g ];
						break;
					}
				}
				$attrs[ strtolower( $a[1] ) ] = $value;
			}
		}
		return $attrs;
	}

	/**
	 * Apply enabled fixes to an HTML fragment (used for before/after previews).
	 *
	 * @param string $fragment HTML fragment.
	 * @return string Fixed fragment.
	 */

	/**
	 * Restore UTF-8 characters that saveHTML() entity-encodes.
	 *
	 * Browsers decode entities in text and attributes, but NOT inside
	 * raw-text elements: a <script> string or <style> content value
	 * containing &eacute; is used literally, silently corrupting
	 * non-ASCII content on any page a fix touches. Structural
	 * entities (&amp; &lt; &gt; &quot;) stay encoded.
	 *
	 * @param string $html Serialized HTML.
	 * @return string
	 */
	private function restore_utf8( $html ) {
		return preg_replace_callback(
			'/&([a-zA-Z][a-zA-Z0-9]+|#[0-9]+|#x[0-9a-fA-F]+);/',
			function ( $m ) {
				if ( in_array( strtolower( $m[1] ), array( 'amp', 'lt', 'gt', 'quot', 'apos', '#39', '#039' ), true ) ) {
					return $m[0];
				}
				$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return $decoded !== $m[0] ? $decoded : $m[0];
			},
			$html
		);
	}

	/**
	 * Shield script/style raw-text contents with placeholder tokens.
	 *
	 * libxml's HTML parser — the fallback used only when the bundled
	 * HTML5 parser class is unavailable — does not treat script/style
	 * content as raw text: tag-like text inside a script string is
	 * eaten on a parse → serialize round trip. The fragment methods
	 * shield the contents before their round trip and restore them
	 * byte-for-byte afterwards. With the HTML5 parser present this is
	 * never called: that parser handles raw text correctly.
	 *
	 * @param string $html HTML to shield.
	 * @param array  $map  Filled with token => original content.
	 * @return string Shielded HTML.
	 */
	private function shield_raw_text( $html, &$map ) {
		$map = array();
		$i   = 0;
		$out = preg_replace_callback(
			'#<(script|style)\b([^>]*)>(.*?)</\1\s*>#is',
			function ( $m ) use ( &$map, &$i, $html ) {
				if ( '' === $m[3] ) {
					return $m[0];
				}
				do {
					$token = 'EQFRAW' . $i . '_' . substr( md5( $html . '|' . $i ), 0, 8 );
					$i++;
				} while ( false !== strpos( $html, $token ) );
				$map[ $token ] = $m[3];
				return '<' . $m[1] . $m[2] . '>' . $token . '</' . $m[1] . '>';
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Restore raw-text contents shielded by shield_raw_text().
	 *
	 * @param string $html Serialized HTML containing the tokens.
	 * @param array  $map  Token => original content.
	 * @return string
	 */
	private function unshield_raw_text( $html, $map ) {
		return empty( $map ) ? $html : strtr( $html, $map );
	}

	public function fix_fragment( $fragment ) {
		$wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $fragment . '</body></html>';
		$fixed   = $this->fix( $wrapped );
		if ( $this->did_bail() ) {
			return $fragment;
		}
		$raw_map  = array();
		$to_parse = $fixed;
		if ( ! class_exists( 'EqualFix_Parser' ) ) {
			// Fallback parser only: shield script/style raw text
			// before this extraction round trip and restore it
			// afterwards, so tag-like script strings survive.
			$to_parse = $this->shield_raw_text( $fixed, $raw_map );
		}
		$dom = $this->parse( $to_parse );
		if ( ! $dom ) {
			return $fragment;
		}
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return $fragment;
		}
		$out = '';
		foreach ( $bodies->item( 0 )->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return '' !== $out ? $this->unshield_raw_text( $this->restore_utf8( $out ), $raw_map ) : $fragment;
	}

	/**
	 * Fix an HTML fragment directly on the DOM (Tier 2, 1.0): used
	 * for post content via the the_content filter, where the mutated
	 * surface is the content fragment itself, never the page. The
	 * caller constructs this fixer with page-level fixes (lang,
	 * skip link) disabled — those never belong inside content.
	 *
	 * @param string $fragment HTML fragment.
	 * @return string Fixed fragment, or the original on any failure.
	 */
	public function fix_fragment_dom( $fragment ) {
		$this->applied             = array();
		$this->bailed              = false;
		$this->inserted_nodes      = array();
		$this->interactive_stamped = false;
		$this->tiered_delivery     = false;
		if ( ! is_string( $fragment ) || '' === trim( $fragment ) ) {
			return $fragment;
		}
		try {
			$raw_map = array();
			$inner   = $fragment;
			if ( ! class_exists( 'EqualFix_Parser' ) ) {
				// Fallback parser only: shield script/style raw
				// text before the parse → serialize round trip.
				$inner = $this->shield_raw_text( $fragment, $raw_map );
			}
			$wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $inner . '</body></html>';
			$dom     = $this->parse( $wrapped );
			if ( ! $dom ) {
				$this->bailed = true;
				return $fragment;
			}
			$this->apply_fixes( $dom );
			if ( empty( $this->applied ) ) {
				return $fragment;
			}
			$bodies = $dom->getElementsByTagName( 'body' );
			if ( 0 === $bodies->length ) {
				$this->bailed = true;
				return $fragment;
			}
			$out = '';
			foreach ( $bodies->item( 0 )->childNodes as $child ) {
				$out .= $dom->saveHTML( $child );
			}
			return '' !== $out ? $this->unshield_raw_text( $this->restore_utf8( $out ), $raw_map ) : $fragment;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			$this->bailed = true;
			return $fragment;
		}
	}


	/**
	 * Parse HTML into a DOMDocument. Returns false on failure.
	 *
	 * @param string $html HTML document.
	 * @return DOMDocument|false
	 */
	private function parse( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return false;
		}
		// 1.0: the HTML5 parse layer (EqualFix_Parser).
		if ( class_exists( 'EqualFix_Parser' ) ) {
			return EqualFix_Parser::parse( $html );
		}
		// Load-order safety only (the main plugin file requires the
		// parser before this class).
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return false;
		}
		$htmls = $dom->getElementsByTagName( 'html' );
		if ( 0 === $htmls->length ) {
			return false;
		}
		return $dom;
	}

	/**
	 * Run each enabled fix over the document.
	 *
	 * @param DOMDocument $dom Parsed document.
	 */
	private function apply_fixes( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );

		if ( $this->settings['fix_lang'] ) {
			$this->fix_lang( $dom );
		}
		if ( $this->settings['fix_alt'] ) {
			$this->fix_images( $dom, $xpath );
		}
		if ( $this->settings['fix_aria_label'] ) {
			$this->fix_names( $dom, $xpath );
		}
		if ( $this->settings['fix_label_assoc'] ) {
			$this->fix_labels( $dom, $xpath );
		}
		if ( $this->settings['fix_skip_link'] ) {
			$this->fix_skip_link( $dom, $xpath );
		}
		if ( ! empty( $this->settings['manual_alt_rules'] ) || ! empty( $this->settings['manual_name_rules'] ) || ! empty( $this->settings['manual_label_rules'] ) || ! empty( $this->settings['manual_title_rules'] ) ) {
			$this->apply_manual_rules( $dom, $xpath );
		}
		// Attribute rules run AFTER the manual stores so their
		// don't-stomp guards judge the final state of each
		// element (Fix-First, 2026-11-07).
		if ( ! empty( $this->settings['manual_attr_rules'] ) ) {
			$this->apply_attr_rules( $dom, $xpath );
		}
		// Inserted content (Fix-First Waves 3+4): caption
		// tracks and transcript blocks the owner supplied.
		// Runs last so its target matching judges the page as
		// the other rules left it.
		if ( ! empty( $this->settings['manual_insert_rules'] ) ) {
			$this->apply_insert_rules( $dom, $xpath );
		}
		// The interactive kit's key handler (Waves 3+4): one
		// delegated script, owed only when a kit rule actually
		// stamped an element on this page, and only on the
		// tiered full-page path.
		if ( $this->interactive_stamped && $this->tiered_delivery ) {
			$this->insert_interactive_script( $dom );
		}
	}

	/**
	 * Apply owner-written rules (next batch). Every application
	 * is an ATTRIBUTE change on an existing element — the one op
	 * family the tiered delivery engine can always splice — and
	 * each rule fires only on its recorded target (and, for alt
	 * rules, only on its recorded page).
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function apply_manual_rules( DOMDocument $dom, DOMXPath $xpath ) {
		// Alt text the owner wrote (or a decorative mark).
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			if ( $this->is_excluded( $img ) ) {
				continue;
			}
			$rule = $this->manual_alt_rule_for( $img );
			if ( null === $rule ) {
				continue;
			}
			$alt = trim( $img->getAttribute( 'alt' ) );
			$src = $img->getAttribute( 'src' );
			// Never overwrite a real description: an empty alt,
			// or one that is just the file name (the finding the
			// owner acted on), are the only writable states.
			if ( '' !== $alt && strtolower( $alt ) !== strtolower( basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) && ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $alt ) ) {
				continue;
			}
			$before = $dom->saveHTML( $img );
			$img->setAttribute( 'alt', $rule['alt'] );
			$this->record( self::FIX_ALT, $img, $before );
		}
		// Names the owner wrote for controls with none.
		if ( ! empty( $this->settings['manual_name_rules'] ) ) {
			// role="link" included (T1-04): an owner's written
			// name for a role="link" control must be applicable
			// at render, not just countable in the dashboard.
			$nodes = $xpath->query( '//button | //a | //input[@type="submit" or @type="button" or @type="reset" or @type="image"] | //*[@role="link"]' );
			$list  = array();
			foreach ( $nodes as $node ) {
				$list[] = $node;
			}
			foreach ( $list as $el ) {
				if ( $this->is_excluded( $el ) ) {
					continue;
				}
				// "Already named" is the audit's test (T1-06):
				// visible text containing a letter or digit is
				// a name — but text that is ONLY a symbol is
				// the audit's symbol-name finding, whose whole
				// point is that the owner's written name must
				// be able to replace it at render.
				$rule_vt = $this->visible_text( $el );
				if ( ( '' !== $rule_vt && 1 === preg_match( '/[\p{L}\p{N}]/u', $rule_vt ) ) || '' !== trim( $el->getAttribute( 'aria-label' ) ) || $el->hasAttribute( 'aria-labelledby' ) ) {
					continue;
				}
				if ( 'input' === strtolower( $el->tagName ) && '' !== trim( $el->getAttribute( 'value' ) ) ) {
					continue;
				}
				foreach ( $this->settings['manual_name_rules'] as $rule ) {
					if ( ! isset( $rule['target'], $rule['aria_label'] ) || ! $this->manual_target_matches( $el, $rule['target'] ) ) {
						continue;
					}
					$before = $dom->saveHTML( $el );
					$el->setAttribute( 'aria-label', $rule['aria_label'] );
					$this->record( self::FIX_ARIA_LABEL, $el, $before );
					break;
				}
			}
		}
		// Labels the owner wrote for fields with none. Delivered
		// as aria-label — the free engine's own label mechanism
		// (its automatic label fix uses the same delivery for
		// placeholder-only fields); the owner's exact words are
		// the field's accessible name either way.
		if ( ! empty( $this->settings['manual_label_rules'] ) ) {
			$nodes = $xpath->query( '//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")] | //select | //textarea' );
			$list  = array();
			foreach ( $nodes as $node ) {
				$list[] = $node;
			}
			foreach ( $list as $el ) {
				if ( $this->is_excluded( $el ) || $this->has_label( $el, $xpath ) ) {
					continue;
				}
				foreach ( $this->settings['manual_label_rules'] as $rule ) {
					if ( ! isset( $rule['target'], $rule['label'] ) || ! $this->manual_target_matches( $el, $rule['target'] ) ) {
						continue;
					}
					$before = $dom->saveHTML( $el );
					$el->setAttribute( 'aria-label', $rule['label'] );
					$this->record( self::FIX_LABEL_ASSOC, $el, $before );
					break;
				}
			}
		}
		// Titles the owner wrote for untitled frames.
		if ( ! empty( $this->settings['manual_title_rules'] ) ) {
			foreach ( $dom->getElementsByTagName( 'iframe' ) as $frame ) {
				if ( $this->is_excluded( $frame ) ) {
					continue;
				}
				if ( '' !== trim( $frame->getAttribute( 'title' ) ) || '' !== trim( $frame->getAttribute( 'aria-label' ) ) ) {
					continue;
				}
				foreach ( $this->settings['manual_title_rules'] as $rule ) {
					if ( ! isset( $rule['target'], $rule['title'] ) || ! $this->manual_target_matches( $frame, $rule['target'] ) ) {
						continue;
					}
					$before = $dom->saveHTML( $frame );
					$frame->setAttribute( 'title', $rule['title'] );
					$this->record( self::FIX_ARIA_LABEL, $frame, $before );
					break;
				}
			}
		}
	}

	/**
	 * Apply owner-approved attribute rules (Fix-First,
	 * 2026-11-07). Each rule fires only on its recorded target
	 * and page scope; every op passes a don't-stomp guard that
	 * re-judges the element's CURRENT state, so a page the
	 * owner has since fixed by hand is left exactly as they
	 * left it. Applications are attribute changes on existing
	 * elements — the op family the tiered engine splices.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function apply_attr_rules( DOMDocument $dom, DOMXPath $xpath ) {
		$rules = $this->settings['manual_attr_rules'];
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return;
		}
		$page_pattern = '' !== $this->settings['page_url'] ? $this->manual_page_pattern( $this->settings['page_url'] ) : '';
		// Duplicate-id rules (Waves 3+4) rename by occurrence
		// count, not by the per-element op walk below — they
		// get their own applier, and the walk skips them.
		$this->apply_dup_id_rules( $dom, $rules, $page_pattern );
		$list = array();
		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			$list[] = $node;
		}
		foreach ( $list as $el ) {
			if ( $this->is_excluded( $el ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( ! isset( $rule['target'], $rule['ops'] ) || ! is_array( $rule['ops'] ) ) {
					continue;
				}
				if ( $this->rule_has_dup_op( $rule ) ) {
					continue;
				}
				if ( ! $this->attr_target_matches( $el, $rule['target'] ) ) {
					continue;
				}
				if ( isset( $rule['group'] ) && 'interactive' === $rule['group'] ) {
					// A content-fragment pass over this same
					// page may have stamped the kit already
					// (fragments never carry the script): the
					// full kit carrying THIS rule's marker
					// means the page owes the key handler —
					// record that and move on.
					foreach ( $rule['ops'] as $kit_op ) {
						if ( isset( $kit_op['data']['marker'] ) && '' !== $kit_op['value']
							&& $el->getAttribute( 'data-equalfix-interactive' ) === $kit_op['value']
							&& 'button' === $el->getAttribute( 'role' ) && $el->hasAttribute( 'tabindex' ) ) {
							$this->interactive_stamped = true;
						}
					}
				}
				if ( isset( $rule['group'] ) && 'interactive' === $rule['group'] && ( $this->interactive_descendant_exists( $el ) || $el->hasAttribute( 'role' ) || $el->hasAttribute( 'tabindex' ) ) ) {
					// The button kit is all-or-nothing, at
					// every level: an element that wraps a
					// real control can never become one
					// itself, and an element that gained a
					// role or tabindex of its own since the
					// audit gets NOTHING stamped — a partial
					// kit (tabindex without the role, a
					// handler marker without either) is worse
					// than none. (wp56's first run proved the
					// per-op guards alone allow exactly that
					// partial stamp.)
					continue;
				}
				if ( ! empty( $rule['pages'] ) && is_array( $rule['pages'] ) && '' !== $page_pattern ) {
					$hit = false;
					foreach ( $rule['pages'] as $p ) {
						if ( $this->manual_pattern_normalize( $p ) === $this->manual_pattern_normalize( $page_pattern ) ) {
							$hit = true;
							break;
						}
					}
					if ( ! $hit ) {
						continue;
					}
				}
				$before = null;
				foreach ( $rule['ops'] as $op ) {
					if ( ! isset( $op['attr'], $op['value'] ) ) {
						continue;
					}
					if ( null === $before && $this->attr_op_would_apply( $el, $op ) ) {
						$before = $dom->saveHTML( $el );
					}
					if ( null !== $before && $this->attr_op_apply( $el, $op ) ) {
						if ( 'data-equalfix-interactive' === $op['attr'] ) {
							// The kit landed on this element:
							// the page now owes the key handler.
							$this->interactive_stamped = true;
						}
						// Applied (or already applied by an
						// earlier op in this same rule).
						continue;
					}
				}
				if ( null !== $before ) {
					$this->record( 'attr', $el, $before );
				}
			}
		}
	}

	/**
	 * Whether a rule carries the duplicate-id rename op.
	 *
	 * @param array $rule Rule.
	 * @return bool
	 */
	private function rule_has_dup_op( $rule ) {
		foreach ( (array) $rule['ops'] as $op ) {
			if ( isset( $op['kind'] ) && 'rename-dup' === $op['kind'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply duplicate-id rules (Fix-First Waves 3+4): rename
	 * the LATER copy of a duplicated id. The op names the id
	 * and the occurrence (the audit's finding element is
	 * always the second presented copy); the candidate at
	 * that position must ALSO match the rule's full recorded
	 * target — if the page's copies have shifted (a hidden
	 * copy the audit never counted, a redesign), the rule
	 * stands down rather than rename the wrong element.
	 * References (href="#id", aria-*, for=) are never touched:
	 * they bind to the first copy, which keeps the id. The
	 * new value is the first of id-2, id-3, … that no element
	 * on the page already carries.
	 *
	 * @param DOMDocument $dom          Document.
	 * @param array       $rules        Attribute rules.
	 * @param string      $page_pattern Current page pattern.
	 */
	private function apply_dup_id_rules( DOMDocument $dom, $rules, $page_pattern ) {
		foreach ( $rules as $rule ) {
			if ( ! isset( $rule['target'], $rule['ops'] ) || ! is_array( $rule['ops'] ) || ! $this->rule_has_dup_op( $rule ) ) {
				continue;
			}
			if ( ! empty( $rule['pages'] ) && is_array( $rule['pages'] ) && '' !== $page_pattern ) {
				$hit = false;
				foreach ( $rule['pages'] as $p ) {
					if ( $this->manual_pattern_normalize( $p ) === $this->manual_pattern_normalize( $page_pattern ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
			}
			$op = null;
			foreach ( $rule['ops'] as $o ) {
				if ( isset( $o['kind'] ) && 'rename-dup' === $o['kind'] ) {
					$op = $o;
					break;
				}
			}
			if ( null === $op || empty( $op['data']['from'] ) ) {
				continue;
			}
			$from       = (string) $op['data']['from'];
			$occurrence = isset( $op['data']['occurrence'] ) ? max( 2, (int) $op['data']['occurrence'] ) : 2;
			$sharing    = array();
			$all_ids    = array();
			foreach ( $dom->getElementsByTagName( '*' ) as $el ) {
				$id = $el->getAttribute( 'id' );
				if ( '' === $id ) {
					continue;
				}
				$all_ids[ $id ] = true;
				if ( $id === $from ) {
					$sharing[] = $el;
				}
			}
			if ( count( $sharing ) < $occurrence ) {
				continue;
			}
			$candidate = $sharing[ $occurrence - 1 ];
			if ( $this->is_excluded( $candidate ) ) {
				continue;
			}
			if ( ! $this->attr_target_matches( $candidate, $rule['target'] ) ) {
				continue;
			}
			$n = 2;
			while ( isset( $all_ids[ $from . '-' . $n ] ) ) {
				$n++;
			}
			$before = $dom->saveHTML( $candidate );
			$candidate->setAttribute( 'id', $from . '-' . $n );
			$this->record( 'attr', $candidate, $before );
		}
	}

	/**
	 * Whether an element contains a descendant that is itself
	 * interactive (a real control, or anything carrying role,
	 * tabindex or a click handler) — the button kit's
	 * all-or-nothing exclusion.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private function interactive_descendant_exists( DOMElement $el ) {
		foreach ( $el->getElementsByTagName( '*' ) as $desc ) {
			if ( in_array( strtolower( $desc->tagName ), array( 'a', 'button', 'input', 'select', 'textarea', 'summary' ), true ) ) {
				return true;
			}
			if ( $desc->hasAttribute( 'role' ) || $desc->hasAttribute( 'tabindex' ) || $desc->hasAttribute( 'onclick' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a link's accessible name is STILL one of the
	 * generic phrases the audit flags (the audit's own list
	 * and normalisation): the link-generic guard. A name that
	 * comes from aria-labelledby lives in other elements —
	 * an aria-label cannot override it, so those stand down.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private function name_still_generic( DOMElement $el ) {
		if ( $el->hasAttribute( 'aria-labelledby' ) ) {
			return false;
		}
		$name = trim( $el->getAttribute( 'aria-label' ) );
		if ( '' === $name ) {
			$name = $this->visible_text( $el );
		}
		$norm = strtolower( trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $name ) ) );
		$norm = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $norm ) ) );
		return in_array( $norm, array( 'click here', 'here', 'read more', 'learn more', 'more', 'link', 'this link', 'go', 'continue' ), true );
	}

	/**
	 * Whether an element matches an attribute rule's recorded
	 * target: the manual matcher's discipline (recorded attrs
	 * exact, class subset, icon on the element or a descendant)
	 * plus the style string when one was recorded (normalised
	 * on both sides), plus exact match for any other recorded
	 * attribute (the ARIA-reference attributes ride here —
	 * after a successful fix the element no longer matches,
	 * which makes re-application a no-op).
	 *
	 * @param DOMElement $el     Element.
	 * @param array      $target Target descriptor.
	 * @return bool
	 */
	private function attr_target_matches( $el, $target ) {
		if ( ! $this->manual_target_matches( $el, $target ) ) {
			return false;
		}
		if ( isset( $target['style'] ) ) {
			if ( $this->normalize_style( $el->getAttribute( 'style' ) ) !== $this->normalize_style( (string) $target['style'] ) ) {
				return false;
			}
		}
		if ( isset( $target['style-color'] ) ) {
			// The contrast identity: when the element carries
			// an inline colour, it must be the recorded one
			// (raw channels, the guard's own comparison). No
			// inline colour → the audited colour came from a
			// stylesheet this engine cannot see; the key does
			// not block (the op's state check decides).
			foreach ( $this->style_declarations( $el->getAttribute( 'style' ) ) as $decl ) {
				if ( 'color' === $decl['prop'] ) {
					$cur = $this->parse_color( $decl['value'] );
					if ( null === $cur || $this->color_hex( $cur ) !== strtolower( (string) $target['style-color'] ) ) {
						return false;
					}
					break;
				}
			}
		}
		foreach ( $target as $attr => $value ) {
			if ( in_array( $attr, array( 'tag', 'class', 'icon', 'style', 'style-color', 'id', 'onclick', 'type', 'placeholder', 'name', 'href', 'src' ), true ) ) {
				continue;
			}
			if ( $el->getAttribute( (string) $attr ) !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalise a style attribute for comparison (mirrors the
	 * settings layer's normaliser).
	 *
	 * @param string $style Style attribute.
	 * @return string
	 */
	private function normalize_style( $style ) {
		$s = preg_replace( '/\s+/', ' ', trim( (string) $style ) );
		$s = preg_replace( '/\s*([:;])\s*/', '$1', (string) $s );
		return rtrim( (string) $s, ';' );
	}

	/**
	 * Would this op change the element? The don't-stomp gate,
	 * judged on the element's current state.
	 *
	 * @param DOMElement $el Element.
	 * @param array      $op Op { attr, kind, value, guard, data }.
	 * @return bool
	 */
	private function attr_op_would_apply( $el, $op ) {
		$attr    = strtolower( (string) $op['attr'] );
		$kind    = isset( $op['kind'] ) ? (string) $op['kind'] : 'set';
		$guard   = isset( $op['guard'] ) ? (string) $op['guard'] : '';
		$current = $el->hasAttribute( $attr ) ? $el->getAttribute( $attr ) : null;
		if ( 'style-color' === $kind ) {
			return $this->style_color_state( $el, $op ) > 0;
		}
		if ( 'rename-dup' === $kind ) {
			// Duplicate-id renames run through their own
			// occurrence-counting applier, never this walk.
			return false;
		}
		switch ( $guard ) {
			case 'no-role':
				// The button kit only claims elements with no
				// role of their own — a role the owner (or
				// their builder) set since the audit is theirs.
				return ! $el->hasAttribute( 'role' );
			case 'no-tabindex':
				return ! $el->hasAttribute( 'tabindex' );
			case 'generic-name':
				// The link's name must still be the generic
				// phrase the audit flagged.
				return $this->name_still_generic( $el );
			case 'tabindex-positive':
				// Only the audited state is fixable: a POSITIVE
				// tabindex still on the element.
				return null !== $current && is_numeric( trim( $current ) ) && (int) trim( $current ) > 0;
			case 'focusable-open':
				// The finding's condition must still hold: the
				// control still sits inside hidden content.
				return $this->inside_aria_hidden( $el );
			case 'no-autocomplete':
				// Any autocomplete the owner (or their form
				// plugin) has since set is their choice.
				return ! $el->hasAttribute( 'autocomplete' );
			case 'zoom-disabled':
				return null !== $current && $this->viewport_blocks_zoom( $current );
			case 'dangling-present':
				if ( null === $current || ! isset( $op['data']['ids'] ) || ! is_array( $op['data']['ids'] ) ) {
					return false;
				}
				$have = preg_split( '/\s+/', trim( $current ) );
				foreach ( $op['data']['ids'] as $id ) {
					if ( in_array( (string) $id, $have, true ) ) {
						return true;
					}
				}
				return false;
			case 'still-empty':
				return '' === trim( (string) $el->textContent ) && ! $el->hasAttribute( 'aria-label' );
			default:
				return null === $current || $current !== (string) $op['value'];
		}
	}

	/**
	 * Apply one op (call only when attr_op_would_apply() said
	 * yes — the style op re-checks internally because its
	 * guard and its write are the same walk).
	 *
	 * @param DOMElement $el Element.
	 * @param array      $op Op.
	 * @return bool Whether a write happened.
	 */
	private function attr_op_apply( $el, $op ) {
		$kind = isset( $op['kind'] ) ? (string) $op['kind'] : 'set';
		if ( 'style-color' === $kind ) {
			return $this->apply_style_color_op( $el, $op );
		}
		if ( ! $this->attr_op_would_apply( $el, $op ) ) {
			return false;
		}
		$el->setAttribute( (string) $op['attr'], (string) $op['value'] );
		return true;
	}

	/**
	 * Whether the element sits inside aria-hidden="true"
	 * content (itself included).
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private function inside_aria_hidden( $el ) {
		$node = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
			if ( $node->hasAttribute( 'aria-hidden' ) && 'true' === strtolower( trim( $node->getAttribute( 'aria-hidden' ) ) ) ) {
				return true;
			}
			$node = $node->parentNode;
		}
		return false;
	}

	/**
	 * Whether a viewport content string still blocks zoom (the
	 * audit check's own predicate).
	 *
	 * @param string $content Meta content.
	 * @return bool
	 */
	private function viewport_blocks_zoom( $content ) {
		foreach ( preg_split( '/[,;]/', (string) $content ) as $part ) {
			$p = strtolower( trim( $part ) );
			if ( 'user-scalable=no' === $p || 'user-scalable=0' === $p ) {
				return true;
			}
			if ( 1 === preg_match( '/^maximum-scale\s*=\s*([0-9]*\.?[0-9]+)$/', $p, $m ) && (float) $m[1] < 2 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The style-color op's state: 0 = stand down, 1 = an inline
	 * colour declaration is present and still the audited one,
	 * 2 = no inline colour and the rule was stylesheet-origin
	 * (an inline colour may be set).
	 *
	 * @param DOMElement $el Element.
	 * @param array      $op Op.
	 * @return int
	 */
	private function style_color_state( $el, $op ) {
		$data   = isset( $op['data'] ) && is_array( $op['data'] ) ? $op['data'] : array();
		// Compare raw-to-raw when the raw inline colour was
		// recorded (an alpha colour's measured fg is a
		// composite and would never equal its own declaration).
		$fg     = ! empty( $data['fg_raw'] ) ? strtolower( (string) $data['fg_raw'] ) : ( isset( $data['fg'] ) ? strtolower( (string) $data['fg'] ) : '' );
		$inline = ! empty( $data['inline'] );
		foreach ( $this->style_declarations( $el->getAttribute( 'style' ) ) as $decl ) {
			if ( 'color' === $decl['prop'] ) {
				$cur = $this->parse_color( $decl['value'] );
				if ( null === $cur || $this->color_hex( $cur ) !== $fg ) {
					// A different inline colour is the owner's
					// newer choice (or unreadable) — stand down.
					return 0;
				}
				return 1;
			}
		}
		// No inline colour declaration. A rule recorded against
		// an inline colour whose declaration has since vanished
		// means the page changed — stand down. A stylesheet-
		// origin rule may set the inline colour.
		return $inline ? 0 : 2;
	}

	/**
	 * Write the style-color op: replace the colour declaration
	 * in place (other declarations and the cascade position
	 * survive; !important follows the winning declaration,
	 * recorded at proposal time), or set an inline colour when
	 * the audited colour came from a stylesheet.
	 *
	 * @param DOMElement $el Element.
	 * @param array      $op Op.
	 * @return bool Whether a write happened.
	 */
	private function apply_style_color_op( $el, $op ) {
		$state = $this->style_color_state( $el, $op );
		if ( 0 === $state ) {
			return false;
		}
		$data  = isset( $op['data'] ) && is_array( $op['data'] ) ? $op['data'] : array();
		$decls = $this->style_declarations( $el->getAttribute( 'style' ) );
		if ( 1 === $state ) {
			foreach ( $decls as $i => $decl ) {
				if ( 'color' === $decl['prop'] ) {
					$decls[ $i ]['value'] = (string) $op['value'];
					$decls[ $i ]['imp']   = $decl['imp'] || ! empty( $data['imp'] );
					break;
				}
			}
		} else {
			$decls[] = array(
				'prop'  => 'color',
				'value' => (string) $op['value'],
				'imp'   => ! empty( $data['imp'] ),
			);
		}
		$el->setAttribute( 'style', $this->style_serialize( $decls ) );
		return true;
	}

	/**
	 * Split a style attribute into declarations.
	 *
	 * @param string $style Style attribute.
	 * @return array[] Each: { prop, value, imp }.
	 */
	private function style_declarations( $style ) {
		$out = array();
		foreach ( explode( ';', (string) $style ) as $part ) {
			$part = trim( $part );
			if ( '' === $part || false === strpos( $part, ':' ) ) {
				continue;
			}
			list( $prop, $value ) = explode( ':', $part, 2 );
			$value = trim( $value );
			$imp   = false;
			if ( 1 === preg_match( '/!important\s*$/i', $value ) ) {
				$imp   = true;
				$value = trim( (string) preg_replace( '/!important\s*$/i', '', $value ) );
			}
			$out[] = array(
				'prop'  => strtolower( trim( $prop ) ),
				'value' => $value,
				'imp'   => $imp,
			);
		}
		return $out;
	}

	/**
	 * Reassemble declarations into a style attribute.
	 *
	 * @param array $decls Declarations.
	 * @return string
	 */
	private function style_serialize( $decls ) {
		$parts = array();
		foreach ( $decls as $decl ) {
			$parts[] = $decl['prop'] . ':' . $decl['value'] . ( $decl['imp'] ? ' !important' : '' );
		}
		return implode( ';', $parts );
	}

	/**
	 * A parsed colour as '#rrggbb' (the audit's label shape).
	 *
	 * @param array $c Colour { r, g, b }.
	 * @return string
	 */
	private function color_hex( $c ) {
		return sprintf( '#%02x%02x%02x', (int) round( $c['r'] ), (int) round( $c['g'] ), (int) round( $c['b'] ) );
	}

	/**
	 * Parse a CSS colour (ported from the audit's parse_color —
	 * the same accepted grammar, so the guard compares like
	 * with like).
	 *
	 * @param string $value Colour value.
	 * @return array|null { r, g, b, a } or null.
	 */
	private function parse_color( $value ) {
		$v = strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return null;
		}
		if ( 'transparent' === $v ) {
			return array( 'r' => 0.0, 'g' => 0.0, 'b' => 0.0, 'a' => 0.0 );
		}
		if ( '#' === $v[0] ) {
			$hex = substr( $v, 1 );
			if ( 1 !== preg_match( '/^[0-9a-f]+$/', $hex ) ) {
				return null;
			}
			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				$r = hexdec( $hex[0] . $hex[0] );
				$g = hexdec( $hex[1] . $hex[1] );
				$b = hexdec( $hex[2] . $hex[2] );
				$a = 4 === strlen( $hex ) ? hexdec( $hex[3] . $hex[3] ) / 255 : 1.0;
				return array( 'r' => (float) $r, 'g' => (float) $g, 'b' => (float) $b, 'a' => (float) $a );
			}
			if ( 6 === strlen( $hex ) || 8 === strlen( $hex ) ) {
				$r = hexdec( substr( $hex, 0, 2 ) );
				$g = hexdec( substr( $hex, 2, 2 ) );
				$b = hexdec( substr( $hex, 4, 2 ) );
				$a = 8 === strlen( $hex ) ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0;
				return array( 'r' => (float) $r, 'g' => (float) $g, 'b' => (float) $b, 'a' => (float) $a );
			}
			return null;
		}
		if ( 1 === preg_match( '/^rgba?\(([^)]*)\)$/', $v, $m ) ) {
			$parts = array_map( 'trim', explode( ',', $m[1] ) );
			if ( 3 !== count( $parts ) && 4 !== count( $parts ) ) {
				return null;
			}
			$channels = array();
			foreach ( array_slice( $parts, 0, 3 ) as $part ) {
				if ( '%' === substr( $part, -1 ) ) {
					$num = (float) substr( $part, 0, -1 );
					if ( $num < 0 || $num > 100 ) {
						return null;
					}
					$channels[] = $num * 255 / 100;
				} elseif ( is_numeric( $part ) ) {
					$num = (float) $part;
					if ( $num < 0 || $num > 255 ) {
						return null;
					}
					$channels[] = $num;
				} else {
					return null;
				}
			}
			$alpha = 1.0;
			if ( 4 === count( $parts ) ) {
				$ap = $parts[3];
				if ( '%' === substr( $ap, -1 ) ) {
					$alpha = (float) substr( $ap, 0, -1 ) / 100;
				} elseif ( is_numeric( $ap ) ) {
					$alpha = (float) $ap;
				} else {
					return null;
				}
				if ( $alpha < 0 || $alpha > 1 ) {
					return null;
				}
			}
			return array( 'r' => $channels[0], 'g' => $channels[1], 'b' => $channels[2], 'a' => $alpha );
		}
		$named = self::attr_named_colors();
		if ( isset( $named[ $v ] ) ) {
			$hex = $named[ $v ];
			return array(
				'r' => (float) hexdec( substr( $hex, 0, 2 ) ),
				'g' => (float) hexdec( substr( $hex, 2, 2 ) ),
				'b' => (float) hexdec( substr( $hex, 4, 2 ) ),
				'a' => 1.0,
			);
		}
		return null;
	}

	private static function attr_named_colors() {
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'aliceblue' => 'f0f8ff', 'antiquewhite' => 'faebd7', 'aqua' => '00ffff', 'aquamarine' => '7fffd4',
				'azure' => 'f0ffff', 'beige' => 'f5f5dc', 'bisque' => 'ffe4c4', 'black' => '000000',
				'blanchedalmond' => 'ffebcd', 'blue' => '0000ff', 'blueviolet' => '8a2be2', 'brown' => 'a52a2a',
				'burlywood' => 'deb887', 'cadetblue' => '5f9ea0', 'chartreuse' => '7fff00', 'chocolate' => 'd2691e',
				'coral' => 'ff7f50', 'cornflowerblue' => '6495ed', 'cornsilk' => 'fff8dc', 'crimson' => 'dc143c',
				'cyan' => '00ffff', 'darkblue' => '00008b', 'darkcyan' => '008b8b', 'darkgoldenrod' => 'b8860b',
				'darkgray' => 'a9a9a9', 'darkgrey' => 'a9a9a9', 'darkgreen' => '006400', 'darkkhaki' => 'bdb76b',
				'darkmagenta' => '8b008b', 'darkolivegreen' => '556b2f', 'darkorange' => 'ff8c00', 'darkorchid' => '9932cc',
				'darkred' => '8b0000', 'darksalmon' => 'e9967a', 'darkseagreen' => '8fbc8f', 'darkslateblue' => '483d8b',
				'darkslategray' => '2f4f4f', 'darkslategrey' => '2f4f4f', 'darkturquoise' => '00ced1', 'darkviolet' => '9400d3',
				'deeppink' => 'ff1493', 'deepskyblue' => '00bfff', 'dimgray' => '696969', 'dimgrey' => '696969',
				'dodgerblue' => '1e90ff', 'firebrick' => 'b22222', 'floralwhite' => 'fffaf0', 'forestgreen' => '228b22',
				'fuchsia' => 'ff00ff', 'gainsboro' => 'dcdcdc', 'ghostwhite' => 'f8f8ff', 'gold' => 'ffd700',
				'goldenrod' => 'daa520', 'gray' => '808080', 'grey' => '808080', 'green' => '008000',
				'greenyellow' => 'adff2f', 'honeydew' => 'f0fff0', 'hotpink' => 'ff69b4', 'indianred' => 'cd5c5c',
				'indigo' => '4b0082', 'ivory' => 'fffff0', 'khaki' => 'f0e68c', 'lavender' => 'e6e6fa',
				'lavenderblush' => 'fff0f5', 'lawngreen' => '7cfc00', 'lemonchiffon' => 'fffacd', 'lightblue' => 'add8e6',
				'lightcoral' => 'f08080', 'lightcyan' => 'e0ffff', 'lightgoldenrodyellow' => 'fafad2', 'lightgray' => 'd3d3d3',
				'lightgrey' => 'd3d3d3', 'lightgreen' => '90ee90', 'lightpink' => 'ffb6c1', 'lightsalmon' => 'ffa07a',
				'lightseagreen' => '20b2aa', 'lightskyblue' => '87cefa', 'lightslategray' => '778899', 'lightslategrey' => '778899',
				'lightsteelblue' => 'b0c4de', 'lightyellow' => 'ffffe0', 'lime' => '00ff00', 'limegreen' => '32cd32',
				'linen' => 'faf0e6', 'magenta' => 'ff00ff', 'maroon' => '800000', 'mediumaquamarine' => '66cdaa',
				'mediumblue' => '0000cd', 'mediumorchid' => 'ba55d3', 'mediumpurple' => '9370db', 'mediumseagreen' => '3cb371',
				'mediumslateblue' => '7b68ee', 'mediumspringgreen' => '00fa9a', 'mediumturquoise' => '48d1cc', 'mediumvioletred' => 'c71585',
				'midnightblue' => '191970', 'mintcream' => 'f5fffa', 'mistyrose' => 'ffe4e1', 'moccasin' => 'ffe4b5',
				'navajowhite' => 'ffdead', 'navy' => '000080', 'oldlace' => 'fdf5e6', 'olive' => '808000',
				'olivedrab' => '6b8e23', 'orange' => 'ffa500', 'orangered' => 'ff4500', 'orchid' => 'da70d6',
				'palegoldenrod' => 'eee8aa', 'palegreen' => '98fb98', 'paleturquoise' => 'afeeee', 'palevioletred' => 'db7093',
				'papayawhip' => 'ffefd5', 'peachpuff' => 'ffdab9', 'peru' => 'cd853f', 'pink' => 'ffc0cb',
				'plum' => 'dda0dd', 'powderblue' => 'b0e0e6', 'purple' => '800080', 'rebeccapurple' => '663399',
				'red' => 'ff0000', 'rosybrown' => 'bc8f8f', 'royalblue' => '4169e1', 'saddlebrown' => '8b4513',
				'salmon' => 'fa8072', 'sandybrown' => 'f4a460', 'seagreen' => '2e8b57', 'seashell' => 'fff5ee',
				'sienna' => 'a0522d', 'silver' => 'c0c0c0', 'skyblue' => '87ceeb', 'slateblue' => '6a5acd',
				'slategray' => '708090', 'slategrey' => '708090', 'snow' => 'fffafa', 'springgreen' => '00ff7f',
				'steelblue' => '4682b4', 'tan' => 'd2b48c', 'teal' => '008080', 'thistle' => 'd8bfd8',
				'tomato' => 'ff6347', 'turquoise' => '40e0d0', 'violet' => 'ee82ee', 'wheat' => 'f5deb3',
				'white' => 'ffffff', 'whitesmoke' => 'f5f5f5', 'yellow' => 'ffff00', 'yellowgreen' => '9acd32',
			);
		}
		return $map;
	}


	/**
	 * The manual alt rule covering one image on this page, if
	 * any (src-key match with the legacy bare-filename fallback,
	 * page scope enforced).
	 *
	 * @param DOMElement $img Image.
	 * @return array|null Rule { alt }.
	 */
	private function manual_alt_rule_for( $img ) {
		$rules = $this->settings['manual_alt_rules'];
		if ( empty( $rules ) ) {
			return null;
		}
		$src = $img->getAttribute( 'src' );
		if ( '' === trim( $src ) ) {
			return null;
		}
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$key  = ltrim( '' !== $path ? $path : $src, '/' );
		$candidates = array();
		if ( isset( $rules[ $key ] ) ) {
			$candidates[] = $rules[ $key ];
		} elseif ( false === strpos( $key, '/' ) ) {
			foreach ( $rules as $rule_key => $rule ) {
				if ( basename( (string) $rule_key ) === $key ) {
					$candidates[] = $rule;
				}
			}
		} else {
			// Legacy bare-filename keys match only the whole
			// filename (mirrors the Pro fixer's discipline).
			foreach ( $rules as $rule_key => $rule ) {
				if ( false === strpos( (string) $rule_key, '/' ) && basename( $key ) === (string) $rule_key ) {
					$candidates[] = $rule;
				}
			}
		}
		foreach ( $candidates as $rule ) {
			if ( ! isset( $rule['alt'] ) ) {
				continue;
			}
			if ( ! empty( $rule['pages'] ) && is_array( $rule['pages'] ) && '' !== $this->settings['page_url'] ) {
				$pattern = $this->manual_page_pattern( $this->settings['page_url'] );
				$hit     = false;
				foreach ( $rule['pages'] as $p ) {
					if ( $this->manual_pattern_normalize( $p ) === $this->manual_pattern_normalize( $pattern ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
			}
			return $rule;
		}
		return null;
	}

	/**
	 * Page pattern for scoping (path + query).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function manual_page_pattern( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}
		return $path;
	}

	/**
	 * Normalize a page pattern for comparison (leading slash,
	 * no trailing slash on the path part).
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private function manual_pattern_normalize( $pattern ) {
		$q = explode( '?', '/' . ltrim( (string) $pattern, '/' ), 2 );
		return rtrim( $q[0], '/' ) . ( isset( $q[1] ) ? '?' . $q[1] : '' );
	}

	/**
	 * Whether an element matches a recorded rule target: every
	 * recorded attribute must match exactly; class is a subset
	 * match; a recorded icon class must appear on the element
	 * or a descendant (mirrors the Pro fixer's discipline).
	 *
	 * @param DOMElement $el     Element.
	 * @param array      $target Target attributes.
	 * @return bool
	 */
	private function manual_target_matches( $el, $target ) {
		if ( empty( $target['tag'] ) || strtolower( $el->tagName ) !== $target['tag'] ) {
			return false;
		}
		foreach ( array( 'id', 'onclick', 'type', 'placeholder', 'name', 'href', 'src' ) as $attr ) {
			if ( isset( $target[ $attr ] ) && $el->getAttribute( $attr ) !== $target[ $attr ] ) {
				return false;
			}
		}
		if ( isset( $target['class'] ) ) {
			$have = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );
			foreach ( preg_split( '/\s+/', trim( (string) $target['class'] ) ) as $c ) {
				if ( '' !== $c && ! in_array( $c, $have, true ) ) {
					return false;
				}
			}
		}
		if ( isset( $target['icon'] ) ) {
			$found = false !== strpos( ' ' . $el->getAttribute( 'class' ) . ' ', ' ' . $target['icon'] . ' ' );
			if ( ! $found ) {
				foreach ( $el->getElementsByTagName( '*' ) as $desc ) {
					if ( false !== strpos( ' ' . $desc->getAttribute( 'class' ) . ' ', ' ' . $target['icon'] . ' ' ) ) {
						$found = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check whether a node or any ancestor matches an exclusion selector.
	 *
	 * Supported selector forms: tag, #id, .class, tag.class, [attr], [attr="value"].
	 *
	 * @param DOMNode $node Node to test.
	 * @return bool True when excluded.
	 */
	private function is_excluded( DOMNode $node ) {
		$selectors = $this->settings['excluded_selectors'];
		if ( empty( $selectors ) ) {
			return false;
		}
		$el = $node;
		while ( $el && XML_ELEMENT_NODE === $el->nodeType ) {
			foreach ( $selectors as $selector ) {
				if ( $this->matches_selector( $el, trim( $selector ) ) ) {
					return true;
				}
			}
			$el = $el->parentNode;
		}
		return false;
	}

	/**
	 * Test one element against one simple selector.
	 *
	 * @param DOMElement $el       Element.
	 * @param string     $selector Selector.
	 * @return bool
	 */
	private function matches_selector( DOMElement $el, $selector ) {
		if ( '' === $selector ) {
			return false;
		}
		// Attribute selector: [attr] or [attr="value"].
		if ( 0 === strpos( $selector, '[' ) && substr( $selector, -1 ) === ']' ) {
			$inner = substr( $selector, 1, -1 );
			if ( false !== strpos( $inner, '=' ) ) {
				list( $attr, $val ) = array_map( 'trim', explode( '=', $inner, 2 ) );
				$val                = trim( $val, '"\'' );
				return $el->hasAttribute( $attr ) && $el->getAttribute( $attr ) === $val;
			}
			return $el->hasAttribute( trim( $inner ) );
		}
		$tag = '';
		$rest = $selector;
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9]*)/', $selector, $m ) ) {
			$tag  = strtolower( $m[1] );
			$rest = substr( $selector, strlen( $m[1] ) );
		}
		if ( '' !== $tag && strtolower( $el->tagName ) !== $tag ) {
			return false;
		}
		if ( 0 === strpos( $rest, '#' ) ) {
			return $el->getAttribute( 'id' ) === substr( $rest, 1 );
		}
		if ( 0 === strpos( $rest, '.' ) ) {
			$class   = substr( $rest, 1 );
			$classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );
			return in_array( $class, $classes, true );
		}
		return '' !== $tag;
	}


	/**
	 * Build a safe XPath string literal for an arbitrary value.
	 *
	 * Attribute values such as element ids can contain quote characters;
	 * interpolating them raw would produce an invalid XPath expression.
	 *
	 * @param string $value Raw value.
	 * @return string XPath literal (quoted string or concat() expression).
	 */
	private function xpath_literal( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		$parts = explode( '"', $value );
		$out   = array();
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 ) {
				$out[] = "\"'\"";
			}
			if ( '' !== $part ) {
				$out[] = '"' . $part . '"';
			}
		}
		return 'concat(' . implode( ', ', $out ) . ')';
	}

	/**
	 * Apply owner-supplied insert rules (Fix-First Waves 3+4):
	 * caption tracks into targeted <video> elements and
	 * transcript blocks after targeted media elements. The
	 * plugin delivers content the OWNER supplied — it never
	 * invents a caption or a transcript. Targets match with
	 * the manual stores' discipline (recorded attrs exact,
	 * class subset) and page scope.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function apply_insert_rules( DOMDocument $dom, DOMXPath $xpath ) {
		unset( $xpath );
		$rules = $this->settings['manual_insert_rules'];
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return;
		}
		$page_pattern = '' !== $this->settings['page_url'] ? $this->manual_page_pattern( $this->settings['page_url'] ) : '';
		foreach ( $rules as $rule ) {
			if ( ! isset( $rule['target'], $rule['kind'], $rule['data'] ) || ! is_array( $rule['data'] ) ) {
				continue;
			}
			if ( ! empty( $rule['pages'] ) && is_array( $rule['pages'] ) && '' !== $page_pattern ) {
				$hit = false;
				foreach ( $rule['pages'] as $p ) {
					if ( $this->manual_pattern_normalize( $p ) === $this->manual_pattern_normalize( $page_pattern ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
			}
			$tag = isset( $rule['target']['tag'] ) ? strtolower( (string) $rule['target']['tag'] ) : '';
			if ( ! in_array( $tag, array( 'video', 'audio' ), true ) ) {
				continue;
			}
			$media_list = array();
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				$media_list[] = $node;
			}
			foreach ( $media_list as $media ) {
				if ( $this->is_excluded( $media ) ) {
					continue;
				}
				if ( ! $this->manual_target_matches( $media, $rule['target'] ) ) {
					continue;
				}
				if ( 'track' === $rule['kind'] ) {
					$this->insert_caption_track( $dom, $media, $rule );
				} elseif ( 'transcript' === $rule['kind'] ) {
					$this->insert_transcript_block( $dom, $media, $rule );
				}
			}
		}
	}

	/**
	 * Insert the owner's caption track into one video. A
	 * video that already carries a working captions or
	 * subtitles track (the audit's own test) is left alone —
	 * the rule stands down rather than stack a second track.
	 * The track is appended last, so existing <source>
	 * children keep their order.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMElement  $media Video element.
	 * @param array       $rule  Insert rule.
	 */
	private function insert_caption_track( DOMDocument $dom, DOMElement $media, $rule ) {
		if ( 'video' !== strtolower( $media->tagName ) || empty( $rule['data']['url'] ) ) {
			return;
		}
		foreach ( $media->getElementsByTagName( 'track' ) as $existing ) {
			$kind = strtolower( trim( $existing->getAttribute( 'kind' ) ) );
			if ( ( '' === $kind || 'captions' === $kind || 'subtitles' === $kind ) && '' !== trim( $existing->getAttribute( 'src' ) ) ) {
				return;
			}
		}
		$before = $dom->saveHTML( $media );
		$track  = $dom->createElement( 'track' );
		$track->setAttribute( 'kind', 'captions' );
		$track->setAttribute( 'src', (string) $rule['data']['url'] );
		$track->setAttribute( 'label', isset( $rule['data']['label'] ) && '' !== trim( (string) $rule['data']['label'] ) ? (string) $rule['data']['label'] : 'Captions' );
		$track->setAttribute( 'srclang', isset( $rule['data']['srclang'] ) && '' !== trim( (string) $rule['data']['srclang'] ) ? (string) $rule['data']['srclang'] : 'en' );
		$track->setAttribute( 'data-equalfix', 'track' );
		$media->appendChild( $track );
		$this->inserted_nodes[] = $track;
		$this->record( 'insert', $media, $before );
	}

	/**
	 * Insert the owner's transcript block immediately after
	 * one media element. A block already sitting there (ours,
	 * by class) is never duplicated.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMElement  $media Media element.
	 * @param array       $rule  Insert rule.
	 */
	private function insert_transcript_block( DOMDocument $dom, DOMElement $media, $rule ) {
		$parent = $media->parentNode;
		if ( ! $parent ) {
			return;
		}
		$next = $media->nextSibling;
		while ( $next && XML_ELEMENT_NODE !== $next->nodeType ) {
			$next = $next->nextSibling;
		}
		if ( $next && $next instanceof DOMElement && false !== strpos( ' ' . $next->getAttribute( 'class' ) . ' ', ' equalfix-transcript ' ) ) {
			return;
		}
		$data = $rule['data'];
		$div  = $dom->createElement( 'div' );
		$div->setAttribute( 'class', 'equalfix-transcript' );
		$div->setAttribute( 'data-equalfix', 'transcript' );
		$head   = $dom->createElement( 'p' );
		$head->setAttribute( 'class', 'equalfix-transcript-heading' );
		$strong = $dom->createElement( 'strong' );
		$strong->appendChild( $dom->createTextNode( 'Transcript' ) );
		$head->appendChild( $strong );
		$div->appendChild( $head );
		if ( isset( $data['mode'] ) && 'link' === $data['mode'] ) {
			if ( empty( $data['url'] ) ) {
				return;
			}
			$p = $dom->createElement( 'p' );
			$a = $dom->createElement( 'a' );
			$a->setAttribute( 'href', (string) $data['url'] );
			$a->appendChild( $dom->createTextNode( 'Read the transcript' ) );
			$p->appendChild( $a );
			$div->appendChild( $p );
		} else {
			if ( empty( $data['html'] ) ) {
				return;
			}
			$body = $dom->createElement( 'div' );
			$body->setAttribute( 'class', 'equalfix-transcript-body' );
			$this->append_owner_html( $dom, $body, (string) $data['html'] );
			$div->appendChild( $body );
		}
		$before = $dom->saveHTML( $media );
		if ( $media->nextSibling ) {
			$parent->insertBefore( $div, $media->nextSibling );
		} else {
			$parent->appendChild( $div );
		}
		$this->inserted_nodes[] = $div;
		$this->record( 'insert', $media, $before );
	}

	/**
	 * Fill a container with owner-supplied transcript HTML
	 * (sanitised with wp_kses_post at save time; parsed here
	 * only to move nodes between documents).
	 *
	 * @param DOMDocument $dom       Target document.
	 * @param DOMElement  $container Container to fill.
	 * @param string      $html      Sanitised HTML.
	 */
	private function append_owner_html( DOMDocument $dom, DOMElement $container, $html ) {
		$parsed = $this->parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
		if ( ! $parsed ) {
			$container->appendChild( $dom->createTextNode( wp_strip_all_tags( $html ) ) );
			return;
		}
		$bodies = $parsed->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return;
		}
		$children = array();
		foreach ( $bodies->item( 0 )->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			$container->appendChild( $dom->importNode( $child, true ) );
		}
	}

	/**
	 * The interactive kit's one delegated key handler
	 * (Fix-First Waves 3+4): printed once per page, only when
	 * a kit rule actually stamped an element on it. Enter
	 * fires the element's click on keydown; Space is held on
	 * keydown (so the page does not scroll) and fires the
	 * click on keyup — the native button behaviour.
	 *
	 * @param DOMDocument $dom Document.
	 */
	private function insert_interactive_script( DOMDocument $dom ) {
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return;
		}
		foreach ( $dom->getElementsByTagName( 'script' ) as $existing ) {
			if ( 'interactive-script' === $existing->getAttribute( 'data-equalfix' ) ) {
				return;
			}
		}
		$script = $dom->createElement( 'script' );
		$script->setAttribute( 'data-equalfix', 'interactive-script' );
		$script->appendChild( $dom->createTextNode( '(function(){var sp=null;function marked(t){return t&&1===t.nodeType&&t.getAttribute&&null!==t.getAttribute("data-equalfix-interactive");}document.addEventListener("keydown",function(e){var t=e.target;if(!marked(t)){return;}if("Enter"===e.key){e.preventDefault();t.click();}else if(" "===e.key||"Spacebar"===e.key){e.preventDefault();sp=t;}});document.addEventListener("keyup",function(e){if(!sp||e.target!==sp){return;}var t=sp;sp=null;if(" "===e.key||"Spacebar"===e.key){e.preventDefault();t.click();}});})();' ) );
		$bodies->item( 0 )->appendChild( $script );
		$this->inserted_nodes[] = $script;
	}

	/**
	 * Record an applied fix.
	 *
	 * @param string     $type   Fix type.
	 * @param DOMElement  $el     Element fixed.
	 * @param string     $before HTML before the fix.
	 */
	private function record( $type, DOMElement $el, $before ) {
		$this->applied[] = array(
			'type'    => $type,
			'element' => strtolower( $el->tagName ),
			'before'  => $this->snippet( $before ),
			'after'   => $this->snippet( $el->ownerDocument->saveHTML( $el ) ),
		);
	}

	/**
	 * Trim a snippet for display.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function snippet( $html ) {
		$html = trim( (string) $html );
		if ( strlen( $html ) > 500 ) {
			// Cut on a character boundary: a byte cut can split a
			// multibyte UTF-8 character and mojibake the stored finding.
			$html = function_exists( 'mb_substr' ) ? mb_substr( $html, 0, 500 ) : substr( $html, 0, 500 );
			$html .= '…';
		}
		return $html;
	}

	/**
	 * Fix 1: add a missing lang attribute to <html>, or replace
	 * a malformed one with the site language (Fix-First Wave 2,
	 * 2026-11-07 — the audit's invalid-language finding rides
	 * this same fix type; validity is the audit's own BCP47
	 * shape test). A well-formed value is never touched.
	 *
	 * @param DOMDocument $dom Document.
	 */
	private function fix_lang( DOMDocument $dom ) {
		$htmls = $dom->getElementsByTagName( 'html' );
		if ( 0 === $htmls->length ) {
			return;
		}
		$html_el = $htmls->item( 0 );
		if ( $html_el->hasAttribute( 'lang' ) && '' !== trim( $html_el->getAttribute( 'lang' ) ) && 1 === preg_match( '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', trim( $html_el->getAttribute( 'lang' ) ) ) ) {
			return;
		}
		if ( $this->is_excluded( $html_el ) ) {
			return;
		}
		$before = $dom->saveHTML( $html_el );
		// Keep only the opening tag for a readable before/after.
		$before = preg_replace( '/>.*$/s', '>', $before );
		$html_el->setAttribute( 'lang', $this->settings['locale'] );
		$this->record( self::FIX_LANG, $html_el, $before );
	}

	/**
	 * Fix 2: add alt="" to decorative images missing alt.
	 *
	 * Decorative means: role="presentation"/"none"/aria-hidden, or the image sits inside
	 * a link or button that already has an accessible name. Descriptive alt text is
	 * never invented — non-decorative images without alt are left for human review.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function fix_images( DOMDocument $dom, DOMXPath $xpath ) {
		$imgs = $dom->getElementsByTagName( 'img' );
		// Snapshot: the live list changes as we edit attributes (it does not, but be safe).
		$list = array();
		foreach ( $imgs as $img ) {
			$list[] = $img;
		}
		foreach ( $list as $img ) {
			if ( $img->hasAttribute( 'alt' ) ) {
				continue;
			}
			if ( $this->is_excluded( $img ) ) {
				continue;
			}
			$decorative = false;
			if ( 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'none' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) ) ) {
				$decorative = true;
			} else {
				// Inside a link or button that already has an accessible name.
				$ancestor = $img->parentNode;
				while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
					$tag = strtolower( $ancestor->tagName );
					if ( ( 'a' === $tag && $ancestor->hasAttribute( 'href' ) ) || 'button' === $tag ) {
						if ( '' !== trim( $this->accessible_name( $ancestor ) ) ) {
							$decorative = true;
						}
						break;
					}
					$ancestor = $ancestor->parentNode;
				}
			}
			if ( ! $decorative ) {
				// Not decorative — but the page itself may
				// already describe this image: its own title
				// attribute, or the figcaption of its figure.
				// Using the page's own words as the alt text is
				// derivation, not invention (1.0.2; the audit
				// classifies these identically).
				$derived = $this->img_derived_alt( $img );
				if ( '' !== $derived ) {
					$before = $dom->saveHTML( $img );
					$img->setAttribute( 'alt', $derived );
					$this->record( self::FIX_ALT, $img, $before );
				}
				continue;
			}
			$before = $dom->saveHTML( $img );
			$img->setAttribute( 'alt', '' );
			$this->record( self::FIX_ALT, $img, $before );
		}
	}

	/**
	 * Alt text derivable from the page itself for an image with no
	 * alt attribute: the image's own title (when it is real text,
	 * not a file name), else the text of its figure's figcaption.
	 * '' when the page offers nothing.
	 *
	 * @param DOMElement $img Image.
	 * @return string
	 */
	private function img_derived_alt( DOMElement $img ) {
		$title = trim( preg_replace( '/\s+/', ' ', $img->getAttribute( 'title' ) ) );
		if ( '' !== $title ) {
			$src_file = strtolower( basename( (string) wp_parse_url( $img->getAttribute( 'src' ), PHP_URL_PATH ) ) );
			$stem     = strtolower( pathinfo( $src_file, PATHINFO_FILENAME ) );
			if ( strtolower( $title ) !== $src_file && strtolower( $title ) !== $stem && ! preg_match( '/\.(png|jpe?g|gif|webp|svg|avif)$/i', $title ) ) {
				return $title;
			}
		}
		$parent = $img->parentNode;
		if ( $parent && XML_ELEMENT_NODE === $parent->nodeType && 'figure' === strtolower( $parent->tagName ) ) {
			foreach ( $parent->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && 'figcaption' === strtolower( $child->tagName ) ) {
					$caption = trim( preg_replace( '/\s+/', ' ', $this->visible_text( $child ) ) );
					if ( '' !== $caption && strlen( $caption ) <= 200 ) {
						return $caption;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Text an element's aria-labelledby resolves to ('' when every
	 * referenced id is missing or carries no text).
	 *
	 * @param DOMElement $el    Element.
	 * @param DOMXPath   $xpath XPath.
	 * @return string
	 */
	private function labelledby_text( DOMElement $el, DOMXPath $xpath ) {
		$text = '';
		foreach ( preg_split( '/\s+/', trim( $el->getAttribute( 'aria-labelledby' ) ) ) as $id ) {
			if ( '' === $id ) {
				continue;
			}
			$ref = $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 );
			if ( $ref ) {
				$text .= ' ' . $ref->textContent;
			}
		}
		// The normalization is the audit's, NBSP term included
		// (round 4, R4-01): a labelledby target whose text is
		// only &nbsp; is as empty to the audit as it must be
		// here — with a plain \s this port counted it as a
		// name and the flagged control could never be fixed.
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Name-bearing text of a subtree, excluding subtrees that
	 * are not presented at all — aria-hidden="true", [hidden],
	 * and inline display:none / visibility:hidden (mirrors the
	 * audit's computation exactly, so a name the audit counts
	 * is a name the fixer counts; the inline-style channels
	 * joined in loop round 2, R2-03, on both sides together).
	 *
	 * @param DOMNode $node Subtree root.
	 * @return string
	 */
	private function visible_text( $node ) {
		$parts = array();
		// The walk carries the EFFECTIVE visibility state,
		// in lockstep with the audit's (T1-08): a descendant
		// that re-declares visibility:visible inside a hidden
		// wrapper presents its text again (round 3, R3-01 —
		// before this, the fixer derived an aria-label from a
		// link's title over text any sighted user could read).
		// The terminal channels (aria-hidden, [hidden],
		// resolved display:none) prune outright, and hiding
		// is judged from RESOLVED declarations — comments,
		// custom properties and content strings that merely
		// mention the keywords hide nothing.
		$root_vis = $node instanceof DOMElement ? $this->node_own_visibility( $node ) : null;
		$walk     = function ( $n, $vis ) use ( &$walk, &$parts ) {
			foreach ( $n->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					if ( 'hidden' !== $vis && 'collapse' !== $vis ) {
						$parts[] = $child->nodeValue;
					}
					continue;
				}
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				if ( $this->node_terminally_hidden( $child ) ) {
					continue;
				}
				$own       = $this->node_own_visibility( $child );
				$child_vis = null !== $own ? $own : $vis;
				if ( 'img' === strtolower( $child->tagName ) ) {
					if ( 'hidden' !== $child_vis && 'collapse' !== $child_vis && '' !== trim( $child->getAttribute( 'alt' ) ) ) {
						$parts[] = $child->getAttribute( 'alt' );
					}
					continue;
				}
				if ( 'svg' === strtolower( $child->tagName ) ) {
					// An embedded svg names its context the way
					// an image does: its own aria-label is its
					// text alternative and wins when present
					// (the audit's B5c rule — T1-08 keeps this
					// port in lockstep with the audit's
					// visible_text(), which this method mirrors).
					$svg_label = trim( $child->getAttribute( 'aria-label' ) );
					if ( '' !== $svg_label ) {
						if ( 'hidden' !== $child_vis && 'collapse' !== $child_vis ) {
							$parts[] = $svg_label;
						}
						continue;
					}
				}
				$walk( $child, $child_vis );
			}
		};
		$walk( $node, $root_vis );
		// The audit's exact normalization, NBSP term included
		// (round 4, R4-01): PHP's \s and trim() do not treat
		// U+00A0 as whitespace, so a link whose only text is
		// &nbsp; read empty to the audit but non-empty here —
		// the finding was flagged forever and could never be
		// fixed. wp48's cross-port corpus locks the parity.
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', implode( ' ', $parts ) ) );
	}

	/**
	 * Tokenize a declaration block into [ property, value ]
	 * pairs — a verbatim port of the audit's tokenizer
	 * (T1-08 lockstep): CSS comments are stripped, and a
	 * ';' or ':' inside a quoted string or parentheses is
	 * part of the value, never a separator.
	 *
	 * @param string $text Declaration text.
	 * @return array List of [ lowercase property, raw value ].
	 */
	private function style_declaration_tokens( $text ) {
		$text  = (string) $text;
		$len   = strlen( $text );
		$parts = array();
		$buf   = '';
		$par   = 0;
		$quote = '';
		$i     = 0;
		while ( $i < $len ) {
			$c = $text[ $i ];
			if ( '' === $quote && 0 === $par && '/' === $c && $i + 1 < $len && '*' === $text[ $i + 1 ] ) {
				$end = strpos( $text, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 2;
				continue;
			}
			if ( '' !== $quote ) {
				if ( '\\' === $c && $i + 1 < $len ) {
					$buf .= $c . $text[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( $c === $quote ) {
					$quote = '';
				}
				$buf .= $c;
				$i++;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
				$buf  .= $c;
				$i++;
				continue;
			}
			if ( '(' === $c ) {
				$par++;
			} elseif ( ')' === $c && $par > 0 ) {
				$par--;
			}
			if ( ';' === $c && 0 === $par ) {
				$parts[] = $buf;
				$buf     = '';
				$i++;
				continue;
			}
			$buf .= $c;
			$i++;
		}
		if ( '' !== trim( $buf ) ) {
			$parts[] = $buf;
		}
		$out = array();
		foreach ( $parts as $part ) {
			$plen  = strlen( $part );
			$par   = 0;
			$quote = '';
			$colon = -1;
			for ( $j = 0; $j < $plen; $j++ ) {
				$c = $part[ $j ];
				if ( '' !== $quote ) {
					if ( '\\' === $c ) {
						$j++;
					} elseif ( $c === $quote ) {
						$quote = '';
					}
					continue;
				}
				if ( '"' === $c || "'" === $c ) {
					$quote = $c;
					continue;
				}
				if ( '(' === $c ) {
					$par++;
					continue;
				}
				if ( ')' === $c && $par > 0 ) {
					$par--;
					continue;
				}
				if ( ':' === $c && 0 === $par ) {
					$colon = $j;
					break;
				}
			}
			if ( $colon < 0 ) {
				continue;
			}
			$prop = strtolower( trim( substr( $part, 0, $colon ) ) );
			if ( '' === $prop ) {
				continue;
			}
			$out[] = array( $prop, trim( substr( $part, $colon + 1 ) ) );
		}
		return $out;
	}

	/**
	 * Resolve one block's declarations to the winner per
	 * property (verbatim port of the audit's resolver): an
	 * !important declaration beats a normal one wherever
	 * each appears; among equals the last wins.
	 *
	 * @param string $text Declaration text.
	 * @param array  $keep Property names to keep.
	 * @return array prop => { value, important, pos }
	 */
	private function resolve_style_declarations( $text, $keep ) {
		$decls = array();
		$pos   = 0;
		foreach ( $this->style_declaration_tokens( $text ) as $pair ) {
			list( $prop, $value ) = $pair;
			if ( ! in_array( $prop, $keep, true ) ) {
				continue;
			}
			$important = false;
			if ( 1 === preg_match( '/!\s*important\s*$/i', $value ) ) {
				$important = true;
				$value     = trim( preg_replace( '/!\s*important\s*$/i', '', $value ) );
			}
			if ( isset( $decls[ $prop ] ) && $decls[ $prop ]['important'] && ! $important ) {
				continue;
			}
			$decls[ $prop ] = array( 'value' => $value, 'important' => $important, 'pos' => $pos++ );
		}
		return $decls;
	}

	/**
	 * Does this ONE node remove itself and its whole subtree
	 * (verbatim port of the audit's predicate): aria-hidden,
	 * [hidden], or a resolved inline display of none.
	 *
	 * @param DOMElement $node Node.
	 * @return bool
	 */
	private function node_terminally_hidden( $node ) {
		if ( 'true' === strtolower( trim( $node->getAttribute( 'aria-hidden' ) ) ) || $node->hasAttribute( 'hidden' ) ) {
			return true;
		}
		$decls = $this->resolve_style_declarations( (string) $node->getAttribute( 'style' ), array( 'display' ) );
		return isset( $decls['display'] ) && 'none' === strtolower( trim( $decls['display']['value'] ) );
	}

	/**
	 * The visibility this ONE node declares for itself, if
	 * any (verbatim port of the audit's reader).
	 *
	 * @param DOMElement $node Node.
	 * @return string|null Lowercased value or null.
	 */
	private function node_own_visibility( $node ) {
		$decls = $this->resolve_style_declarations( (string) $node->getAttribute( 'style' ), array( 'visibility' ) );
		return isset( $decls['visibility'] ) ? strtolower( trim( $decls['visibility']['value'] ) ) : null;
	}

	/**
	 * Compute a simple accessible name for an element.
	 *
	 * Considers aria-labelledby (text of referenced elements), aria-label,
	 * text content, title attribute, and value (for inputs).
	 *
	 * @param DOMElement $el Element.
	 * @return string Accessible name (may be empty).
	 */
	private function accessible_name( DOMElement $el ) {
		if ( $el->hasAttribute( 'aria-labelledby' ) ) {
			$dom   = $el->ownerDocument;
			$xpath = new DOMXPath( $dom );
			$name  = '';
			foreach ( preg_split( '/\s+/', trim( $el->getAttribute( 'aria-labelledby' ) ) ) as $id ) {
				if ( '' === $id ) {
					continue;
				}
				$ref = $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 );
				if ( $ref ) {
					$name .= ' ' . $ref->textContent;
				}
			}
			$name = trim( $name );
			if ( '' !== $name ) {
				return $name;
			}
		}
		if ( $el->hasAttribute( 'aria-label' ) && '' !== trim( $el->getAttribute( 'aria-label' ) ) ) {
			return trim( $el->getAttribute( 'aria-label' ) );
		}
		$text = $this->visible_text( $el );
		if ( '' !== $text ) {
			return $text;
		}
		if ( 'input' === strtolower( $el->tagName ) && $el->hasAttribute( 'value' ) && '' !== trim( $el->getAttribute( 'value' ) ) ) {
			return trim( $el->getAttribute( 'value' ) );
		}
		if ( $el->hasAttribute( 'title' ) && '' !== trim( $el->getAttribute( 'title' ) ) ) {
			return trim( $el->getAttribute( 'title' ) );
		}
		return '';
	}

	/**
	 * Fix 3: add aria-label to icon-only buttons/links with no accessible name.
	 *
	 * The label is derived from the title attribute or an inner SVG <title>/<desc>.
	 * Labels are never invented — elements with no derivable label are left for
	 * human review.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function fix_names( DOMDocument $dom, DOMXPath $xpath ) {
		// role="link" is in the set (T1-04): the audit has
		// checked it since B5b, so the fixer must be able to
		// reach what the audit flags — before this, a
		// role="link" finding was counted fixable but no
		// render path could ever name the element.
		$nodes = $xpath->query( '//button | //a[@href] | //input[@type="button" or @type="submit" or @type="reset" or @type="image"] | //*[@role="button"] | //*[@role="link"]' );
		$list  = array();
		foreach ( $nodes as $node ) {
			$list[] = $node;
		}
		foreach ( $list as $el ) {
			if ( '' !== trim( $el->getAttribute( 'aria-label' ) ) ) {
				continue;
			}
			// A dead aria-labelledby (missing or empty references)
			// provides no name; the fix below can still rescue the
			// control, because name computation falls through to
			// aria-label when every reference is missing.
			if ( $el->hasAttribute( 'aria-labelledby' ) && '' !== $this->labelledby_text( $el, $xpath ) ) {
				continue;
			}
			if ( $this->is_excluded( $el ) ) {
				continue;
			}
			// Hidden from assistive technology: never label it.
			if ( 'true' === strtolower( $el->getAttribute( 'aria-hidden' ) ) ) {
				continue;
			}
			// Already named by text content or input value? Skip.
			// (A title attribute is a *source* for the derived label, not a
			// reason to skip — we upgrade it to a real aria-label.)
			// The text test is the audit's visible-text rule
			// (T1-08): text inside aria-hidden or [hidden]
			// subtrees never reaches assistive technology, so a
			// control "named" only by such text is NOT named —
			// raw textContent counted it and the fixer skipped
			// a control the audit had flagged.
			$text = $this->visible_text( $el );
			if ( '' !== $text ) {
				continue;
			}
			if ( 'input' === strtolower( $el->tagName ) && '' !== trim( $el->getAttribute( 'value' ) ) ) {
				continue;
			}
			// Already named by a contained image's alt text? Skip — a
			// derived aria-label would override that name, not improve it.
			foreach ( $xpath->query( './/img[@alt]', $el ) as $img ) {
				if ( '' !== trim( $img->getAttribute( 'alt' ) ) ) {
					continue 2;
				}
			}
			$label = '';
			if ( $el->hasAttribute( 'title' ) && '' !== trim( $el->getAttribute( 'title' ) ) ) {
				$label = trim( $el->getAttribute( 'title' ) );
			} else {
				// Inner SVG <title> or <desc>.
				$titles = $xpath->query( './/*[local-name()="title" or local-name()="desc"]', $el );
				foreach ( $titles as $t ) {
					$candidate = trim( $t->textContent );
					if ( '' !== $candidate ) {
						$label = $candidate;
						break;
					}
				}
			}
			if ( 'input' === strtolower( $el->tagName ) && 'image' === strtolower( $el->getAttribute( 'type' ) ) ) {
				// input[type=image] needs alt, not aria-label.
				if ( '' !== $label && ! $el->hasAttribute( 'alt' ) ) {
					$before = $dom->saveHTML( $el );
					$el->setAttribute( 'alt', $label );
					$this->record( self::FIX_ARIA_LABEL, $el, $before );
				}
				continue;
			}
			if ( '' === $label ) {
				continue;
			}
			$before = $dom->saveHTML( $el );
			$el->setAttribute( 'aria-label', $label );
			$this->record( self::FIX_ARIA_LABEL, $el, $before );
		}
	}

	/**
	 * Fix 4: associate form inputs with visible labels where a unique match exists.
	 *
	 * A unique match is a single preceding-sibling <label> without a for attribute
	 * in the same parent. The input gets a generated id and the label gets for="".
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function fix_labels( DOMDocument $dom, DOMXPath $xpath ) {
		$nodes = $xpath->query( '//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")] | //select | //textarea' );
		$list  = array();
		foreach ( $nodes as $node ) {
			$list[] = $node;
		}
		$counter = 0;
		foreach ( $list as $el ) {
			if ( $this->is_excluded( $el ) ) {
				continue;
			}
			if ( $this->has_label( $el, $xpath ) ) {
				continue;
			}
			// Candidate: preceding sibling <label> elements without a for attribute.
			$candidates = array();
			$sibling    = $el->previousSibling;
			while ( $sibling ) {
				if ( XML_ELEMENT_NODE === $sibling->nodeType && 'label' === strtolower( $sibling->tagName ) && ! $sibling->hasAttribute( 'for' ) ) {
					$candidates[] = $sibling;
				}
				$sibling = $sibling->previousSibling;
			}
			// No unique label to associate: fall back to the
			// field's own placeholder text as its accessible
			// label. The words are the site's own (never
			// invented), and a placeholder-only field otherwise
			// has NO name at all for assistive technology. A
			// visible <label> remains the better fix; this is
			// the safe floor, and the audit classifies it
			// identically (1.0.2).
			if ( 1 !== count( $candidates ) ) {
				$placeholder = trim( preg_replace( '/\s+/', ' ', $el->getAttribute( 'placeholder' ) ) );
				$placeholder = trim( preg_replace( '/(\.\.\.|…)+$/', '', $placeholder ) );
				if ( '' === $placeholder ) {
					continue;
				}
				$before = $dom->saveHTML( $el );
				$el->setAttribute( 'aria-label', $placeholder );
				$this->record( self::FIX_LABEL_ASSOC, $el, $before );
				continue;
			}
			$label  = $candidates[0];
			$before = $dom->saveHTML( $el );
			// Reuse the element's existing id when it has one — overwriting it
			// would break theme/plugin JS (getElementById) and CSS (#id) that
			// target the original id. Only generate when the id is absent.
			if ( $el->hasAttribute( 'id' ) && '' !== trim( $el->getAttribute( 'id' ) ) ) {
				$id = $el->getAttribute( 'id' );
			} else {
				$counter++;
				$id = 'equalfix-field-' . $counter;
				// Ensure uniqueness against existing ids.
				while ( null !== $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 ) ) {
					$counter++;
					$id = 'equalfix-field-' . $counter;
				}
				$el->setAttribute( 'id', $id );
			}
			$label->setAttribute( 'for', $id );
			$this->record( self::FIX_LABEL_ASSOC, $el, $before );
		}
	}

	/**
	 * Check whether a form control already has a label association.
	 *
	 * @param DOMElement $el    Control.
	 * @param DOMXPath   $xpath XPath.
	 * @return bool
	 */
	private function has_label( DOMElement $el, DOMXPath $xpath ) {
		// Wrapped in a label — which counts only when it has
		// text of its own (T1-03): an empty <label> names
		// nothing, exactly as the audit's B3 test judges it.
		// Before this, the mere existence of a label element
		// blocked the fix while the dashboard counted the
		// field labelled.
		$ancestor = $el->parentNode;
		while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
			if ( 'label' === strtolower( $ancestor->tagName ) && $this->label_has_text( $ancestor ) ) {
				return true;
			}
			$ancestor = $ancestor->parentNode;
		}
		if ( '' !== trim( $el->getAttribute( 'aria-label' ) ) ) {
			return true;
		}
		if ( $el->hasAttribute( 'aria-labelledby' ) && '' !== $this->labelledby_text( $el, $xpath ) ) {
			return true;
		}
		if ( $el->hasAttribute( 'id' ) && '' !== $el->getAttribute( 'id' ) ) {
			$id     = $el->getAttribute( 'id' );
			$labels = $xpath->query( '//label[@for=' . $this->xpath_literal( $id ) . ']' );
			foreach ( $labels as $label ) {
				if ( $this->label_has_text( $label ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Does this <label> element actually say anything? The
	 * audit's label_has_text() test, ported verbatim (T1-03 —
	 * one definition of a label on both sides of the pair):
	 * normalized text, or a contained image whose alt text
	 * names it. Screen-reader-only text counts.
	 *
	 * @param DOMElement $label Label element.
	 * @return bool
	 */
	private function label_has_text( $label ) {
		$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $label->textContent ) );
		if ( '' !== $text ) {
			return true;
		}
		foreach ( $label->getElementsByTagName( 'img' ) as $img ) {
			if ( '' !== trim( $img->getAttribute( 'alt' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fix 5: inject a skip-to-content link where the theme lacks one.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function fix_skip_link( DOMDocument $dom, DOMXPath $xpath ) {
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return;
		}
		$body = $bodies->item( 0 );
		if ( $this->is_excluded( $body ) ) {
			return;
		}
		// Already has a skip link? The recognition here is the
		// AUDIT's definition (T1-02) — one shared rule for what
		// counts as a skip link, so a page the audit passes never
		// gets a second link injected: the link's text, class or
		// aria-label may say "skip", or use jump phrasing ("Jump
		// to main content"), and the href may be fragment-only,
		// root-relative or absolute (see skip_fragment()). It
		// counts only if its target exists — a skip link to a
		// missing id leads nowhere, so a working one is still
		// injected below (the audit reports the same condition).
		$links = $body->getElementsByTagName( 'a' );
		foreach ( $links as $a ) {
			$fragment = $this->skip_fragment( $a->getAttribute( 'href' ) );
			if ( null === $fragment ) {
				continue;
			}
			$text  = strtolower( trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $a->textContent ) ) );
			$class = strtolower( $a->getAttribute( 'class' ) );
			$aria  = strtolower( trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $a->getAttribute( 'aria-label' ) ) ) );
			$named = false;
			foreach ( array( $text, $class, $aria ) as $haystack ) {
				if ( false !== strpos( $haystack, 'skip' ) || false !== strpos( $haystack, 'jump' ) || false !== strpos( $haystack, 'main content' ) ) {
					$named = true;
					break;
				}
			}
			if ( ! $named ) {
				continue;
			}
			if ( 'other-page' === $fragment ) {
				// The skip link points at another page's fragment;
				// its target cannot be judged from this document,
				// and a skip link demonstrably exists.
				return;
			}
			if ( $xpath->query( '//*[@id=' . $this->xpath_literal( $fragment ) . ']' )->length > 0 ) {
				return;
			}
			// Recognised but its target is missing: fall through
			// and inject a working one, exactly as before.
		}
		// Find a main-content target.
		$target = '';
		foreach ( array( 'main', '#main', '#content', '#primary', '#main-content' ) as $sel ) {
			if ( 0 === strpos( $sel, '#' ) ) {
				$id  = substr( $sel, 1 );
				$hit = $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 );
				if ( $hit ) {
					$target = $id;
					break;
				}
			} else {
				$hit = $dom->getElementsByTagName( $sel )->item( 0 );
				if ( $hit ) {
					if ( ! $hit->hasAttribute( 'id' ) ) {
						$hit->setAttribute( 'id', 'equalfix-main' );
					}
					$target = $hit->getAttribute( 'id' );
					break;
				}
			}
		}
		if ( '' === $target ) {
			return;
		}
		$before = '<body>';
		$link   = $dom->createElement( 'a', $this->settings['skip_link_text'] );
		$link->setAttribute( 'href', '#' . $target );
		$link->setAttribute( 'class', 'equalfix-skip-link' );
		$style = $dom->createElement(
			'style',
			'.equalfix-skip-link{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;z-index:100000;background:#fff;color:#000;padding:8px 16px;}' .
			'.equalfix-skip-link:focus,.equalfix-skip-link:active{left:8px;top:8px;width:auto;height:auto;overflow:visible;}'
		);
		$style->setAttribute( 'data-equalfix', 'skip-link-style' );
		$first = $body->firstChild;
		$body->insertBefore( $link, $first );
		$body->insertBefore( $style, $first );
		$this->applied[] = array(
			'type'    => self::FIX_SKIP_LINK,
			'element' => 'a',
			'before'  => $before,
			'after'   => $this->snippet( $dom->saveHTML( $link ) ),
		);
	}

	/**
	 * Extract the fragment a link's href points at, when it targets
	 * this page. A verbatim port of the audit's skip_fragment()
	 * (T1-02: the two classes share ONE definition of a skip
	 * link's href, judged against the URL of the page being
	 * rendered, which the buffer passes in as 'page_url').
	 *
	 * @param string $href The link's href attribute.
	 * @return string|null The fragment id; 'other-page' when the href
	 *                     targets a fragment on a different page;
	 *                     null when the href has no usable fragment.
	 */
	private function skip_fragment( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href ) {
			return null;
		}
		if ( 0 === strpos( $href, '#' ) ) {
			$frag = substr( $href, 1 );
			return '' === $frag ? null : $frag;
		}
		$hash = strpos( $href, '#' );
		if ( false === $hash ) {
			return null;
		}
		$frag = substr( $href, $hash + 1 );
		if ( '' === $frag ) {
			return null;
		}
		$page_url    = isset( $this->settings['page_url'] ) ? (string) $this->settings['page_url'] : '';
		$target_path = wp_parse_url( substr( $href, 0, $hash ), PHP_URL_PATH );
		$page_path   = wp_parse_url( $page_url, PHP_URL_PATH );
		$norm        = function ( $p ) {
			$p = '/' . trim( (string) $p, '/' );
			return '' === $p ? '/' : $p;
		};
		if ( null !== $target_path && $norm( $target_path ) !== $norm( $page_path ) ) {
			return 'other-page';
		}
		return $frag;
	}
}
