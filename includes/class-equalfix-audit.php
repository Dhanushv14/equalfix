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
		// Shield script/style contents before parsing: the parser
		// otherwise misreads tag-shaped text inside scripts, which
		// can shift the element tree the checks walk. The audit
		// never reports script/style contents, so no restore.
		list( $work, ) = self::shield_raw_text( $html );
		$dom = $this->parse( $work );
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
		// Template contents are inert — not rendered and not in the
		// accessibility tree until script clones them — so findings
		// inside <template> would be phantoms. Remove the subtrees
		// from the audit's own copy before checking.
		$templates = array();
		foreach ( $dom->getElementsByTagName( 'template' ) as $tpl ) {
			$templates[] = $tpl;
		}
		foreach ( $templates as $tpl ) {
			if ( $tpl->parentNode ) {
				$tpl->parentNode->removeChild( $tpl );
			}
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
			// Cut on a character boundary: a byte cut can split a
			// multibyte UTF-8 character and mojibake the stored finding.
			$html = function_exists( 'mb_substr' ) ? mb_substr( $html, 0, 500 ) : substr( $html, 0, 500 );
			$html .= '…';
		}
		return $html;
	}

	/**
	 * Shield script/style contents from the HTML parser (the
	 * audit's checks never inspect those contents; see the free
	 * fixer's note for why the parser cannot be trusted with
	 * tag-shaped text inside raw-text elements).
	 *
	 * @param string $html HTML.
	 * @return array [ shielded HTML, token => original contents ]
	 */
	private static function shield_raw_text( $html ) {
		$map = array();
		$out = preg_replace_callback(
			'#<(script|style)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</\1\s*>#is',
			function ( $m ) use ( &$map ) {
				$token         = 'EQXRAW' . count( $map ) . 'X';
				$map[ $token ] = $m[3];
				return '<' . $m[1] . $m[2] . '>' . $token . '</' . $m[1] . '>';
			},
			$html
		);
		return array( null === $out ? $html : $out, $map );
	}

	private function parse( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || ! is_string( $html ) || '' === trim( $html ) ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE );
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
				// Hidden from assistive technology (the same rule the
				// missing-alt branch applies): AT never encounters it.
				if ( 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'none' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) ) ) {
					continue;
				}
				// An alt that is just the image's file name describes the
				// file, not the image — screen readers announce it
				// verbatim. Flag it for a human-written description.
				$alt_text = trim( $img->getAttribute( 'alt' ) );
				if ( '' !== $alt_text && self::alt_looks_like_filename( $alt_text, $img->getAttribute( 'src' ) ) ) {
					$findings[] = $this->finding(
						self::CAT_REVIEW, 'moderate', '1.1.1',
						'Alt text is just a file name',
						'This image\'s alt text is a file name, which screen readers announce verbatim — it describes the file, not what the image shows. A human should replace it with a short description of the image, or with alt="" if the image is purely decorative.',
						$url, $dom->saveHTML( $img )
					);
				}
				continue;
			}
			// Already removed from the accessibility tree by the author
			// (role="presentation"/"none" or aria-hidden): assistive
			// technology never encounters this image, so a missing alt
			// attribute there is not a finding.
			if ( 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'none' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) ) ) {
				continue;
			}
			$decorative = false;
			if ( ! $decorative ) {
				$ancestor = $img->parentNode;
				while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
					$tag = strtolower( $ancestor->tagName );
					if ( ( 'a' === $tag && $ancestor->hasAttribute( 'href' ) ) || 'button' === $tag ) {
						$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $ancestor->textContent ) );
						// The control names the image's context when it
						// has ANY real name source — the same test the
						// fixer applies before adding alt="". (Presence
						// of aria-label alone is not a name: aria-label=""
						// names nothing, while a title or a resolving
						// aria-labelledby DOES name the control.)
						$named = '' !== $text
							|| '' !== trim( $ancestor->getAttribute( 'aria-label' ) )
							|| '' !== trim( $ancestor->getAttribute( 'title' ) )
							|| ( '' !== trim( $ancestor->getAttribute( 'aria-labelledby' ) ) && $this->labelledby_resolves( $ancestor, $xpath ) );
						if ( $named ) {
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
	 * Whether an alt text is a file name rather than a description.
	 *
	 * Conservative signals: it ends in an image-file extension; it is
	 * the src file's name or stem; it follows a camera/app file-name
	 * pattern (IMG_…, DSC-…, Screenshot …); or it is a spaceless token
	 * mixing separators and digits the way generated file names do.
	 *
	 * @param string $alt Alt text (trimmed, non-empty).
	 * @param string $src Image src attribute.
	 * @return bool
	 */
	private static function alt_looks_like_filename( $alt, $src ) {
		if ( 1 === preg_match( '/\.(png|jpe?g|gif|webp|svg|bmp|avif|ico)$/i', $alt ) ) {
			return true;
		}
		$base = $src ? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) : '';
		if ( '' !== $base && 0 === strcasecmp( $alt, $base ) ) {
			return true;
		}
		// Alt equal to the file's stem counts only when the stem itself
		// is file-name-shaped (contains a digit or underscore):
		// alt="logo" on logo.png is a description; "IMG_9921" is not.
		if ( '' !== $base && 0 === strcasecmp( $alt, pathinfo( $base, PATHINFO_FILENAME ) ) && 1 === preg_match( '/[\d_]/', pathinfo( $base, PATHINFO_FILENAME ) ) ) {
			return true;
		}
		// The camera/app pattern must cover the WHOLE alt: spaces after
		// the number part mean a sentence ("Photo 2024 company picnic",
		// "Image 3 shows the team"), and sentences are descriptions.
		if ( 1 === preg_match( '/^(img|dsc|dscn|pxl|photo|image|screenshot|untitled)[-_ ]?\d[\w.-]*$/i', $alt ) ) {
			return true;
		}
		if ( false === strpos( $alt, ' ' ) && 1 === preg_match( '/\d/', $alt ) && 1 === preg_match( '/[_-]/', $alt ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Accessible-name checks for buttons and links.
	 */
	private function check_names( $dom, $xpath, $url, &$findings ) {
		$nodes = $xpath->query( '//button | //a[@href] | //input[@type="button" or @type="submit" or @type="reset" or @type="image"] | //*[@role="button"]' );
		foreach ( $nodes as $el ) {
			if ( '' !== trim( $el->getAttribute( 'aria-label' ) ) ) {
				continue;
			}
			// An aria-labelledby whose references are missing or empty
			// provides no name — it must not mask a finding.
			if ( $el->hasAttribute( 'aria-labelledby' ) && $this->labelledby_resolves( $el, $xpath ) ) {
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
			if ( 1 !== $candidates && $el->hasAttribute( 'placeholder' ) && '' !== trim( $el->getAttribute( 'placeholder' ) ) ) {
				$note .= ' Its placeholder is currently its only name source — and a placeholder is not a label: it disappears while typing and is not announced reliably.';
			}
			$findings[] = $this->finding(
				$category, 'serious', '3.3.2',
				'Form field has no associated label',
				'This form field is not programmatically associated with a label, so screen reader users cannot tell what it is for. ' . $note,
				$url, $dom->saveHTML( $el )
			);
		}
	}

	/**
	 * Does this element's aria-labelledby resolve to real text?
	 * True when at least one referenced id exists and carries text —
	 * matching the accessible-name computation, which uses whatever
	 * referenced text resolves and ignores dangling references.
	 *
	 * @param DOMElement $el    Element.
	 * @param DOMXPath   $xpath XPath.
	 * @return bool
	 */
	private function labelledby_resolves( $el, $xpath ) {
		foreach ( preg_split( '/\s+/', trim( $el->getAttribute( 'aria-labelledby' ) ) ) as $id ) {
			if ( '' === $id ) {
				continue;
			}
			$ref = $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 );
			if ( $ref && '' !== trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $ref->textContent ) ) ) {
				return true;
			}
		}
		return false;
	}
	private function has_label( $el, $xpath ) {
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
		if ( $el->hasAttribute( 'aria-labelledby' ) && $this->labelledby_resolves( $el, $xpath ) ) {
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
		$dead_target = false;
		foreach ( $bodies->item( 0 )->getElementsByTagName( 'a' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( 0 === strpos( $href, '#' ) && strlen( $href ) > 1 ) {
				$text  = strtolower( trim( $a->textContent ) );
				$class = strtolower( $a->getAttribute( 'class' ) );
				if ( false !== strpos( $text, 'skip' ) || false !== strpos( $class, 'skip' ) ) {
					// A skip link counts only if its target exists — a
					// link to a missing id leads nowhere.
					if ( null !== $dom->getElementById( substr( $href, 1 ) ) ) {
						return;
					}
					$dead_target = true;
				}
			}
		}
		if ( $dead_target ) {
			$findings[] = $this->finding(
				self::CAT_FIX_A, 'moderate', '2.4.1',
				'Skip link target does not exist',
				'This page has a skip link, but the element it points to does not exist, so the link leads nowhere for keyboard and screen reader users. The free plugin adds a working skip link automatically.',
				$url, '<body>'
			);
			return;
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
			if ( '' === $id ) {
				continue; // An empty id attribute is not an id.
			}
			if ( isset( $seen[ $id ] ) && ! isset( $reported[ $id ] ) ) {
				$reported[ $id ] = true;
				$findings[]      = $this->finding(
					self::CAT_REVIEW, 'moderate', '',
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
		// Headings must be walked in DOCUMENT order (an XPath union
		// returns document order): grouping by tag misreads real pages —
		// h1,h3,h2 hides a genuine h1->h3 skip, and h3,h1 invents one.
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' );
		$prev  = 0;
		foreach ( $nodes as $el ) {
			$level = (int) substr( strtolower( $el->tagName ), 1 );
			if ( $prev > 0 && $level > $prev + 1 ) {
				$findings[] = $this->finding(
					self::CAT_FIX_B, 'moderate', '1.3.1',
					'Skipped heading level',
					'A heading jumps from h' . $prev . ' to h' . $level . ', which breaks the document outline for screen reader users. Heading correction is part of the Pro add-on’s fix library.',
					$url, $dom->saveHTML( $el )
				);
				// Every skip is reported (matching axe-core and every
				// other check in this audit, which report per element);
				// an earlier version stopped at the first skip per page.
			}
			$prev = $level;
		}
	}

	/**
	 * Third-party embed check — an untitled cross-origin iframe is a real
	 * finding (frames need titles) that the plugin cannot auto-fix. A
	 * titled embed is already named for assistive technology, so it is
	 * not reported: inventory is not an issue.
	 */
	private function check_embeds( $dom, $xpath, $url, &$findings ) {
		$page_host = $this->host_of( $url );
		foreach ( $dom->getElementsByTagName( 'iframe' ) as $iframe ) {
			$src = $iframe->getAttribute( 'src' );
			if ( '' === $src && ! $iframe->hasAttribute( 'srcdoc' ) ) {
				continue;
			}
			if ( '' !== trim( $iframe->getAttribute( 'title' ) ) || '' !== trim( $iframe->getAttribute( 'aria-label' ) ) ) {
				continue;
			}
			if ( '' !== trim( $iframe->getAttribute( 'aria-labelledby' ) ) && $this->labelledby_resolves( $iframe, $xpath ) ) {
				continue;
			}
			$src_host = $this->host_of( $src );
			if ( '' === $src || ! $src_host || ! $page_host || strtolower( (string) $src_host ) === strtolower( (string) $page_host ) ) {
				// Same-origin (or relative) frame with no title: a real
				// failure the owner CAN fix, so it is a human-decision
				// finding — the third-party branch below must not be
				// the only way an untitled frame is ever reported.
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '2.4.1',
					'Embedded frame missing a title',
					'This frame has no title, so screen-reader users cannot tell what it contains. Add a title attribute to the iframe describing its content.',
					$url, $dom->saveHTML( $iframe )
				);
				continue;
			}
			if ( $src_host && $page_host && strtolower( $src_host ) !== strtolower( $page_host ) ) {
				$provider    = $this->name_provider( $src_host );
				$findings[] = $this->finding(
					self::CAT_EMBED, 'moderate', '2.4.1',
					'Third-party embed — cannot auto-fix' . ( '' !== $provider ? ' (' . $provider . ')' : '' ),
					'This page embeds content from ' . $src_host . ' in a frame with no title, so screen-reader users cannot tell what the frame contains. Add a title to the iframe where it is embedded on your page, describing the content. The content inside the frame loads from the provider and remains their responsibility.',
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
