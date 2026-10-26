<?php
/**
 * EqualFix Accessibility — Local Audit Engine
 *
 * Runs deterministic accessibility checks against rendered HTML. Every finding
 * carries: severity, WCAG reference, page URL, exact HTML snippet, plain-English
 * explanation, and one of four categories:
 *   - auto-fixable by Fix Set A (the free plugin's built-in fixes)
 *   - auto-fixable by Pro (Fix Set B, separate add-on)
 *   - needs human review
 *   - third-party embed — cannot auto-fix
 *
 * Findings are data. Nothing here invents results: the same HTML always
 * produces the same findings.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

class EqualFix_Audit {

	const CAT_FIX_A = 'fix-a';
	const CAT_FIX_B = 'fix-b';
	const CAT_REVIEW = 'review';
	const CAT_EMBED  = 'embed';

	/**
	 * Run all checks against one page's HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $url  Page URL (for reporting).
	 * @return array Findings.
	 */
	public function audit_page( $html, $url ) {
		$findings = array();
		$dom      = $this->parse( $html );
		if ( ! $dom ) {
			return array(
				$this->finding(
					'review',
					'serious',
					'',
					'Page could not be parsed',
					'The page HTML could not be parsed, so it was not fully checked. This page is reported as-is rather than skipped silently.',
					$url,
					''
				),
			);
		}
		$xpath = new DOMXPath( $dom );

		try {
			$this->check_images( $dom, $xpath, $url, $findings );
			$this->check_names( $dom, $xpath, $url, $findings );
			$this->check_labels( $dom, $xpath, $url, $findings );
			$this->check_lang( $dom, $url, $findings );
			$this->check_skip_link( $dom, $url, $findings );
			$this->check_duplicate_ids( $dom, $xpath, $url, $findings );
			$this->check_headings( $dom, $url, $findings );
			$this->check_embeds( $dom, $xpath, $url, $findings );
		} catch ( \Throwable $e ) {
			$findings[] = $this->finding(
				'review',
				'serious',
				'',
				'Page could not be fully checked',
				'Some checks could not be completed on this page because of an unexpected page structure, so its results may be incomplete. This page is reported as-is rather than skipped silently.',
				$url,
				''
			);
		}

		return $findings;
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
	 * Build a finding record.
	 */
	private function finding( $category, $severity, $wcag, $title, $explanation, $url, $snippet ) {
		return array(
			'category'    => $category,
			'severity'    => $severity,
			'wcag'        => $wcag,
			'title'       => $title,
			'explanation' => $explanation,
			'url'         => $url,
			'snippet'     => $this->snippet( $snippet ),
		);
	}

	private function snippet( $html ) {
		$html = trim( (string) $html );
		if ( strlen( $html ) > 500 ) {
			$html = substr( $html, 0, 500 ) . '…';
		}
		return $html;
	}

	private function parse( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || ! is_string( $html ) || '' === trim( $html ) ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || 0 === $dom->getElementsByTagName( 'html' )->length ) {
			return false;
		}
		return $dom;
	}

	/**
	 * Image checks: missing alt.
	 */
	private function check_images( $dom, $xpath, $url, &$findings ) {
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			if ( $img->hasAttribute( 'alt' ) ) {
				continue;
			}
			$decorative = 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) );
			if ( ! $decorative ) {
				$ancestor = $img->parentNode;
				while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
					$tag = strtolower( $ancestor->tagName );
					if ( ( 'a' === $tag && $ancestor->hasAttribute( 'href' ) ) || 'button' === $tag ) {
						$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $ancestor->textContent ) );
						if ( '' !== $text || $ancestor->hasAttribute( 'aria-label' ) ) {
							$decorative = true;
						}
						break;
					}
					$ancestor = $ancestor->parentNode;
				}
			}
			if ( $decorative ) {
				$findings[] = $this->finding(
					self::CAT_FIX_A, 'serious', '1.1.1',
					'Decorative image missing empty alt text',
					'This image is decorative (it carries no information), but it has no alt attribute, so screen readers may announce its file name. The free plugin adds alt="" automatically.',
					$url, $dom->saveHTML( $img )
				);
			} else {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'critical', '1.1.1',
					'Image missing alt text',
					'This image has no alt text and does not look decorative, so its meaning is lost to screen reader users. Descriptive alt text needs a human decision — the plugin will not invent a description.',
					$url, $dom->saveHTML( $img )
				);
			}
		}
	}

	/**
	 * Accessible-name checks for buttons and links.
	 */
	private function check_names( $dom, $xpath, $url, &$findings ) {
		$nodes = $xpath->query( '//button | //a[@href] | //input[@type="button" or @type="submit" or @type="reset" or @type="image"] | //*[@role="button"]' );
		foreach ( $nodes as $el ) {
			if ( $el->hasAttribute( 'aria-label' ) || $el->hasAttribute( 'aria-labelledby' ) ) {
				continue;
			}
			// Hidden from assistive technology: not in the accessibility
			// tree, so a missing name there is not a finding.
			if ( 'true' === strtolower( $el->getAttribute( 'aria-hidden' ) ) ) {
				continue;
			}
			$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $el->textContent ) );
			if ( '' !== $text ) {
				continue;
			}
			if ( 'input' === strtolower( $el->tagName ) && '' !== trim( $el->getAttribute( 'value' ) ) ) {
				continue;
			}
			// The accessible name is also computed from the alt text of a
			// contained image, or from an image input's own alt attribute.
			if ( 'input' === strtolower( $el->tagName ) && '' !== trim( $el->getAttribute( 'alt' ) ) ) {
				continue;
			}
			foreach ( $xpath->query( './/img[@alt]', $el ) as $img ) {
				if ( '' !== trim( $img->getAttribute( 'alt' ) ) ) {
					continue 2;
				}
			}
			$tag        = strtolower( $el->tagName );
			$derivable  = $el->hasAttribute( 'title' ) && '' !== trim( $el->getAttribute( 'title' ) );
			$category   = $derivable ? self::CAT_FIX_A : self::CAT_REVIEW;
			$fix_note   = $derivable
				? 'The free plugin can derive a label from its title attribute automatically.'
				: 'No label can be derived safely, so this needs a human to name the control. The plugin will not invent a label.';
			$findings[] = $this->finding(
				$category, 'serious', '4.1.2',
				ucfirst( 'button' === $tag ? 'button' : ( 'a' === $tag ? 'link' : 'control' ) ) . ' has no accessible name',
				'This ' . ( 'a' === $tag ? 'link' : 'control' ) . ' contains no text and no label, so screen reader users hear only "button" or "link" with no purpose. ' . $fix_note,
				$url, $dom->saveHTML( $el )
			);
		}
	}

	/**
	 * Form label association checks.
	 */
	private function check_labels( $dom, $xpath, $url, &$findings ) {
		$nodes = $xpath->query( '//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")] | //select | //textarea' );
		foreach ( $nodes as $el ) {
			if ( $this->has_label( $el, $xpath ) ) {
				continue;
			}
			$candidates = 0;
			$sibling    = $el->previousSibling;
			while ( $sibling ) {
				if ( XML_ELEMENT_NODE === $sibling->nodeType && 'label' === strtolower( $sibling->tagName ) && ! $sibling->hasAttribute( 'for' ) ) {
					$candidates++;
				}
				$sibling = $sibling->previousSibling;
			}
			$category = 1 === $candidates ? self::CAT_FIX_A : self::CAT_REVIEW;
			$note     = 1 === $candidates
				? 'A single nearby label was found, so the free plugin can associate them automatically.'
				: 'No unique nearby label was found, so a human should associate this field with its label.';
			$findings[] = $this->finding(
				$category, 'serious', '3.3.2',
				'Form field has no associated label',
				'This form field is not programmatically associated with a label, so screen reader users cannot tell what it is for. ' . $note,
				$url, $dom->saveHTML( $el )
			);
		}
	}

	private function has_label( $el, $xpath ) {
		$ancestor = $el->parentNode;
		while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
			if ( 'label' === strtolower( $ancestor->tagName ) ) {
				return true;
			}
			$ancestor = $ancestor->parentNode;
		}
		if ( $el->hasAttribute( 'aria-label' ) || $el->hasAttribute( 'aria-labelledby' ) ) {
			return true;
		}
		if ( $el->hasAttribute( 'id' ) && '' !== $el->getAttribute( 'id' ) ) {
			if ( $xpath->query( '//label[@for=' . $this->xpath_literal( $el->getAttribute( 'id' ) ) . ']' )->item( 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lang attribute check.
	 */
	private function check_lang( $dom, $url, &$findings ) {
		$htmls = $dom->getElementsByTagName( 'html' );
		if ( 0 === $htmls->length ) {
			return;
		}
		$html_el = $htmls->item( 0 );
		if ( $html_el->hasAttribute( 'lang' ) && '' !== trim( $html_el->getAttribute( 'lang' ) ) ) {
			return;
		}
		$findings[] = $this->finding(
			self::CAT_FIX_A, 'serious', '3.1.1',
			'Page is missing a language attribute',
			'The <html> element has no lang attribute, so screen readers cannot select the right pronunciation rules. The free plugin adds it automatically.',
			$url, '<html>'
		);
	}

	/**
	 * Skip-link check.
	 */
	private function check_skip_link( $dom, $url, &$findings ) {
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return;
		}
		foreach ( $bodies->item( 0 )->getElementsByTagName( 'a' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( 0 === strpos( $href, '#' ) && strlen( $href ) > 1 ) {
				$text  = strtolower( trim( $a->textContent ) );
				$class = strtolower( $a->getAttribute( 'class' ) );
				if ( false !== strpos( $text, 'skip' ) || false !== strpos( $class, 'skip' ) ) {
					return;
				}
			}
		}
		$findings[] = $this->finding(
			self::CAT_FIX_A, 'moderate', '2.4.1',
			'No skip-to-content link',
			'Keyboard and screen reader users must tab through the entire header and navigation on every page. A "Skip to content" link lets them jump straight to the main content. The free plugin adds one automatically where the theme lacks it.',
			$url, '<body>'
		);
	}

	/**
	 * Duplicate id check (needs human review — auto-fixing ids is unsafe).
	 */
	private function check_duplicate_ids( $dom, $xpath, $url, &$findings ) {
		$seen     = array();
		$reported = array();
		$nodes    = $xpath->query( '//*[@id]' );
		foreach ( $nodes as $el ) {
			$id = $el->getAttribute( 'id' );
			if ( isset( $seen[ $id ] ) && ! isset( $reported[ $id ] ) ) {
				$reported[ $id ] = true;
				$findings[]      = $this->finding(
					self::CAT_REVIEW, 'moderate', '4.1.1',
					'Duplicate id attribute: "' . $id . '"',
					'Two elements share the same id, which breaks label associations and scripting that targets the id. Renaming ids automatically is unsafe, so this needs a human fix.',
					$url, $dom->saveHTML( $el )
				);
			}
			$seen[ $id ] = true;
		}
	}

	/**
	 * Heading-order check (Fix Set B territory — reported as Pro category).
	 */
	private function check_headings( $dom, $url, &$findings ) {
		$prev = 0;
		$tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		foreach ( $tags as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			foreach ( $nodes as $el ) {
				$level = (int) substr( $tag, 1 );
				if ( $prev > 0 && $level > $prev + 1 ) {
					$findings[] = $this->finding(
						self::CAT_FIX_B, 'moderate', '1.3.1',
						'Skipped heading level',
						'A heading jumps from h' . $prev . ' to h' . $level . ', which breaks the document outline for screen reader users. Heading correction is part of the Pro add-on’s fix library.',
						$url, $dom->saveHTML( $el )
					);
					break 2;
				}
				$prev = max( $prev, $level );
			}
		}
	}

	/**
	 * Third-party embed check — cross-origin iframes cannot be auto-fixed.
	 */
	private function check_embeds( $dom, $xpath, $url, &$findings ) {
		$page_host = $this->host_of( $url );
		foreach ( $dom->getElementsByTagName( 'iframe' ) as $iframe ) {
			$src = $iframe->getAttribute( 'src' );
			if ( '' === $src ) {
				continue;
			}
			$src_host = $this->host_of( $src );
			if ( $src_host && $page_host && strtolower( $src_host ) !== strtolower( $page_host ) ) {
				$provider    = $this->name_provider( $src_host );
				$findings[] = $this->finding(
					self::CAT_EMBED, 'moderate', '',
					'Third-party embed — cannot auto-fix' . ( '' !== $provider ? ' (' . $provider . ')' : '' ),
					'This page embeds content from ' . $src_host . ', which loads from another provider’s servers. Cross-origin content cannot be patched by this tool. Raise accessibility issues with the embed provider, or move that step onto your own site.',
					$url, $dom->saveHTML( $iframe )
				);
			}
		}
	}

	/**
	 * Extract the host from a URL without depending on WordPress.
	 *
	 * @param string $url URL.
	 * @return string|null Host or null.
	 */
	private function host_of( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url, PHP_URL_HOST );
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return isset( $parts['host'] ) ? $parts['host'] : null;
	}

	/**
	 * Name a known embed provider from its host.
	 */
	private function name_provider( $host ) {
		$host = strtolower( $host );
		$map  = array(
			'donorbox'  => 'Donorbox',
			'classy'    => 'Classy',
			'stripe'    => 'Stripe',
			'paypal'    => 'PayPal',
			'youtube'   => 'YouTube',
			'vimeo'     => 'Vimeo',
			'google'    => 'Google',
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'twitter'   => 'X (Twitter)',
			'tiktok'    => 'TikTok',
		);
		foreach ( $map as $needle => $name ) {
			if ( false !== strpos( $host, $needle ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Human-readable category labels.
	 */
	public static function category_label( $category ) {
		$labels = array(
			self::CAT_FIX_A  => 'Auto-fixable by free plugin',
			self::CAT_FIX_B  => 'Auto-fixable by Pro add-on',
			self::CAT_REVIEW => 'Needs human review',
			self::CAT_EMBED  => 'Third-party embed — cannot auto-fix',
		);
		return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
	}
}
