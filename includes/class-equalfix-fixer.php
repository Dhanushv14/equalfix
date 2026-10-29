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
		$this->applied = array();
		$this->bailed  = false;

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
			// The only node-inserting fix is the skip link (anchor +
			// style, both marked) at the start of <body>. Reconcile
			// by removing marked insertions from the mutated list.
			$kept = array();
			$inserted = array();
			foreach ( $cb as $child ) {
				if ( 'body' === strtolower( $a->tagName ) && $this->is_skip_insert( $child ) ) {
					$inserted[] = $child;
					continue;
				}
				$kept[] = $child;
			}
			if ( ! empty( $inserted ) && count( $kept ) === count( $ca ) ) {
				$html = '';
				foreach ( $inserted as $node ) {
					$html .= $b->ownerDocument->saveHTML( $node );
				}
				$ops[] = array(
					'kind' => 'insert_body_start',
					'html' => $html,
				);
				$cb = $kept;
			} else {
				return false;
			}
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

	public function fix_fragment( $fragment ) {
		$wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $fragment . '</body></html>';
		$fixed   = $this->fix( $wrapped );
		if ( $this->did_bail() ) {
			return $fragment;
		}
		$dom = $this->parse( $fixed );
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
		return '' !== $out ? $this->restore_utf8( $out ) : $fragment;
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
		$this->applied = array();
		$this->bailed  = false;
		if ( ! is_string( $fragment ) || '' === trim( $fragment ) ) {
			return $fragment;
		}
		try {
			$wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $fragment . '</body></html>';
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
			return '' !== $out ? $this->restore_utf8( $out ) : $fragment;
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
	 * Record an applied fix.
	 *
	 * @param string     $type   Fix type.
	 * @param DOMElement $el     Element fixed.
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
	 * Fix 1: add missing lang attribute to <html>.
	 *
	 * @param DOMDocument $dom Document.
	 */
	private function fix_lang( DOMDocument $dom ) {
		$htmls = $dom->getElementsByTagName( 'html' );
		if ( 0 === $htmls->length ) {
			return;
		}
		$html_el = $htmls->item( 0 );
		if ( $html_el->hasAttribute( 'lang' ) && '' !== trim( $html_el->getAttribute( 'lang' ) ) ) {
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
				continue;
			}
			$before = $dom->saveHTML( $img );
			$img->setAttribute( 'alt', '' );
			$this->record( self::FIX_ALT, $img, $before );
		}
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
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Name-bearing text of a subtree, excluding aria-hidden="true"
	 * and [hidden] subtrees (mirrors the audit's computation, so a
	 * name the audit counts is a name the fixer counts).
	 *
	 * @param DOMNode $node Subtree root.
	 * @return string
	 */
	private function visible_text( $node ) {
		$parts = array();
		$walk  = function ( $n ) use ( &$walk, &$parts ) {
			foreach ( $n->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$parts[] = $child->nodeValue;
					continue;
				}
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				if ( 'true' === strtolower( $child->getAttribute( 'aria-hidden' ) ) || $child->hasAttribute( 'hidden' ) ) {
					continue;
				}
				if ( 'img' === strtolower( $child->tagName ) ) {
					if ( '' !== trim( $child->getAttribute( 'alt' ) ) ) {
						$parts[] = $child->getAttribute( 'alt' );
					}
					continue;
				}
				$walk( $child );
			}
		};
		$walk( $node );
		return trim( preg_replace( '/\\s+/', ' ', implode( ' ', $parts ) ) );
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
		$nodes = $xpath->query( '//button | //a[@href] | //input[@type="button" or @type="submit" or @type="reset" or @type="image"] | //*[@role="button"]' );
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
			$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $el->textContent ) );
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
			// Unique match: exactly one candidate label.
			if ( 1 !== count( $candidates ) ) {
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
		// Wrapped in a label.
		$ancestor = $el->parentNode;
		while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
			if ( 'label' === strtolower( $ancestor->tagName ) ) {
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
			$id  = $el->getAttribute( 'id' );
			$hit = $xpath->query( '//label[@for=' . $this->xpath_literal( $id ) . ']' )->item( 0 );
			if ( $hit ) {
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
		// Already has a skip link? It counts only if its target exists —
		// a skip link to a missing id leads nowhere, so a working one is
		// still injected below (the audit reports the same condition).
		$links = $body->getElementsByTagName( 'a' );
		foreach ( $links as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( 0 === strpos( $href, '#' ) && strlen( $href ) > 1 ) {
				$text  = strtolower( trim( $a->textContent ) );
				$class = strtolower( $a->getAttribute( 'class' ) );
				if ( false !== strpos( $text, 'skip' ) || false !== strpos( $class, 'skip' ) ) {
					$frag = substr( $href, 1 );
					if ( $xpath->query( '//*[@id=' . $this->xpath_literal( $frag ) . ']' )->length > 0 ) {
						return;
					}
				}
			}
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
}
