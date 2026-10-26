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
		$this->applied = array();
		$this->bailed  = false;

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			$this->bailed = true;
			return $html;
		}

		$trimmed = ltrim( $html );
		if ( '' === $trimmed || '<' !== $trimmed[0] ) {
			// Not markup at all (JSON, plain text, ...). Leave untouched.
			// (XML/RSS without an <html> element bails in parse().)
			$this->bailed = true;
			return $html;
		}

		try {
			$dom = $this->parse( $html );
			if ( ! $dom ) {
				$this->bailed = true;
				return $html;
			}

			$this->apply_fixes( $dom );

			// No fixes applied: serve the original buffer byte-for-byte.
			// The page is untouched unless we actually changed something.
			if ( empty( $this->applied ) ) {
				return $html;
			}

			$fixed = $dom->saveHTML();
			if ( false === $fixed || '' === trim( $fixed ) ) {
				$this->bailed = true;
				return $html;
			}
			// Strip the encoding PI we prepended for parsing. DOMDocument emits it
			// after the doctype, not at string start, so the pattern is unanchored.
			$fixed = preg_replace( '/<\?xml[^?]*\?>\s*/', '', $fixed, 1 );
			return $fixed;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			$this->bailed = true;
			return $html;
		}
	}

	/**
	 * Apply enabled fixes to an HTML fragment (used for before/after previews).
	 *
	 * @param string $fragment HTML fragment.
	 * @return string Fixed fragment.
	 */
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
		return '' !== $out ? $out : $fragment;
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
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		// Prefix keeps UTF-8 intact through loadHTML.
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
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
			$html = substr( $html, 0, 500 ) . '…';
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
	 * Decorative means: role="presentation"/aria-hidden, or the image sits inside
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
			if ( 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) ) ) {
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
		$text = trim( preg_replace( '/\s+/', ' ', $el->textContent ) );
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
		// Already has a skip link?
		$links = $body->getElementsByTagName( 'a' );
		foreach ( $links as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( 0 === strpos( $href, '#' ) && strlen( $href ) > 1 ) {
				$text  = strtolower( trim( $a->textContent ) );
				$class = strtolower( $a->getAttribute( 'class' ) );
				if ( false !== strpos( $text, 'skip' ) || false !== strpos( $class, 'skip' ) ) {
					return;
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
