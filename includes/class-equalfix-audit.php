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
	 * Per-check outcome of the most recent audit_page() call:
	 * slug => number of findings that check produced on that
	 * page (Tier 2, T2-02). A check that ran and produced zero
	 * findings PASSED on that page — the settings layer
	 * aggregates these across a run so the results screens can
	 * show the full checklist, passes included, instead of only
	 * ever showing failures. Empty when the page could not be
	 * parsed (no check ran at all).
	 *
	 * @var array
	 */
	private $run_checks = array();

	/**
	 * The named checks this engine runs, in run order, with the
	 * plain-words name the results screens show. This is the
	 * single catalogue T2-02 renders from: a check that exists
	 * here but never runs is a lie on the checklist, and a check
	 * that runs but is missing here never gets its pass shown —
	 * the wp44 census asserts the two stay in step.
	 *
	 * @return array slug => plain name.
	 */
	public static function check_catalogue() {
		return array(
			'images'            => 'Images have descriptions (alt text)',
			'names'             => 'Buttons, links and controls have names',
			'labels'            => 'Form fields have labels',
			'lang'              => 'Page language is set and well-formed',
			'title'             => 'Page has a title',
			'skip_link'         => 'A skip link leads to the main content',
			'duplicate_ids'     => 'Element IDs are unique',
			'headings'          => 'Headings are in order and not empty',
			'embeds'            => 'Embedded frames are titled',
			'viewport'          => 'Zoom is not disabled',
			'aria_refs'         => 'ARIA references point at elements that exist',
			'aria_hidden_focus' => 'Nothing focusable is hidden from screen readers',
			'interactive'       => 'Clickable things are real buttons or links',
			'tabindex'          => 'Tab order follows the page order',
			'autocomplete'      => 'Personal-data fields offer autocomplete',
			'tables'            => 'Data tables have header cells',
			'media'             => 'Videos and audio offer captions or a transcript',
			'contrast'          => 'Text contrast, from styles written in the page',
		);
	}

	/**
	 * Per-check finding counts for the most recent audit_page()
	 * call (slug => findings produced; a slug is present only
	 * when the check actually ran on that page).
	 *
	 * @return array
	 */
	public function last_page_checks() {
		return $this->run_checks;
	}

	/**
	 * Run all checks against one page's HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $url  Page URL (for reporting).
	 * @return array Findings.
	 */
	public function audit_page( $html, $url ) {
		$findings = array();
		// 1.0: no token-shielding — the HTML5 parser treats script and
		// style as raw text by spec, so tag-shaped text inside them
		// can no longer shift the element tree the checks walk.
		$this->run_checks = array();
		$dom = $this->parse( $html );
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

		// Each check runs tracked (T2-02): the delta in the
		// findings count is that check's outcome on this page,
		// recorded against its catalogue slug. A check that
		// throws is never recorded — the catch below reports
		// the page as not fully checked, and the checklist
		// aggregation only credits checks that completed.
		$track = function ( $slug, $run ) use ( &$findings ) {
			$before = count( $findings );
			$run();
			$this->run_checks[ $slug ] = count( $findings ) - $before;
		};
		try {
			$track( 'images', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_images( $dom, $xpath, $url, $findings ); } );
			$track( 'names', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_names( $dom, $xpath, $url, $findings ); } );
			$track( 'labels', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_labels( $dom, $xpath, $url, $findings ); } );
			$track( 'lang', function () use ( $dom, $url, &$findings ) { $this->check_lang( $dom, $url, $findings ); } );
			$track( 'title', function () use ( $dom, $url, &$findings ) { $this->check_title( $dom, $url, $findings ); } );
			$track( 'skip_link', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_skip_link( $dom, $xpath, $url, $findings ); } );
			$track( 'duplicate_ids', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_duplicate_ids( $dom, $xpath, $url, $findings ); } );
			$track( 'headings', function () use ( $dom, $url, &$findings ) { $this->check_headings( $dom, $url, $findings ); } );
			$track( 'embeds', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_embeds( $dom, $xpath, $url, $findings ); } );
			$track( 'viewport', function () use ( $dom, $url, &$findings ) { $this->check_viewport( $dom, $url, $findings ); } );
			$track( 'aria_refs', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_aria_refs( $dom, $xpath, $url, $findings ); } );
			$track( 'aria_hidden_focus', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_aria_hidden_focus( $dom, $xpath, $url, $findings ); } );
			$track( 'interactive', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_interactive( $dom, $xpath, $url, $findings ); } );
			$track( 'tabindex', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_tabindex( $dom, $xpath, $url, $findings ); } );
			$track( 'autocomplete', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_autocomplete( $dom, $xpath, $url, $findings ); } );
			$track( 'tables', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_tables( $dom, $xpath, $url, $findings ); } );
			$track( 'media', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_media( $dom, $xpath, $url, $findings ); } );
			// Contrast runs last: it is the newest and most
			// bounded check, and keeping it last means its
			// per-page cap can never crowd earlier checks'
			// findings out of the stored set.
			$track( 'contrast', function () use ( $dom, $xpath, $url, &$findings ) { $this->check_contrast( $dom, $xpath, $url, $findings ); } );
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
		// 1.0: all analysis parsing goes through the HTML5 parse layer
		// (EqualFix_Parser). A false here means the page could not be
		// analysed — audit_page() treats it as an empty page body,
		// which its checks report on honestly; it is never a clean
		// result for a page that has content (see the guard layer).
		if ( class_exists( 'EqualFix_Parser' ) ) {
			return EqualFix_Parser::parse( $html );
		}
		// Load-order safety only (the parser class is required before
		// this file by the main plugin file): legacy libxml2 parse.
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
			// Not presented at all (loop round 2, R2-02): an
			// image inside a subtree nobody is shown — a
			// display:none drawer, a [hidden] template block,
			// an aria-hidden wrapper — draws no finding of any
			// kind, the same rule media, tables and embeds
			// already apply. This check had no ancestor test
			// at all, so it was the widest hole in the family.
			if ( $this->element_not_presented( $img ) ) {
				continue;
			}
			if ( $img->hasAttribute( 'alt' ) ) {
				// Hidden from assistive technology (the same rule the
				// missing-alt branch applies): AT never encounters it.
				if ( 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'none' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) ) ) {
					continue;
				}
				// An alt that is a file name, leads with one, or
				// leans on a redundant phrase ("image of…") describes
				// the file or the medium, not the image — screen
				// readers announce it verbatim. Flag it for a
				// human-written description.
				$alt_text = trim( $img->getAttribute( 'alt' ) );
				if ( '' !== $alt_text && self::alt_is_filename_or_redundant( $alt_text, $img->getAttribute( 'src' ) ) ) {
					$findings[] = $this->finding(
						self::CAT_REVIEW, 'moderate', '1.1.1',
						'Alt text contains a file name or redundant phrase',
						'This image\'s alt text is or starts with a file name, or contains a redundant phrase like "image of" — screen readers announce it verbatim, and it describes the file rather than what the image shows. A human should replace it with a short description of the image, or with alt="" if the image is purely decorative.',
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
						// Presented text only (loop round 2,
						// R2-03): text inside a display:none
						// span does not name the control for
						// anyone, so it cannot make the image
						// decorative-by-context either — the
						// same visible_text() computation
						// check_names() applies to the control
						// itself.
						$text = $this->visible_text( $ancestor );
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
			} elseif ( '' !== $this->img_derived_alt( $img ) ) {
				// The page describes this image itself (its
				// title attribute or its figure's caption) —
				// the free plugin uses those words as the alt
				// text. Mirrors the fixer exactly (1.0.2).
				$findings[] = $this->finding(
					self::CAT_FIX_A, 'serious', '1.1.1',
					'Image missing alt text',
					'This image has no alt text, but the page already describes it — in its title attribute or its figure caption. The free plugin uses the page\'s own words as the alt text automatically.',
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
	 * Alt text derivable from the page itself for an image with no
	 * alt attribute: the image's own title (when it is real text,
	 * not a file name), else the text of its figure's figcaption.
	 * '' when the page offers nothing. Mirrors the fixer's
	 * img_derived_alt() exactly, so classification and application
	 * can never disagree (1.0.2).
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
	 * Whether an alt text carries a file name or a redundant
	 * phrase: everything alt_looks_like_filename() catches, plus
	 * an alt that BEGINS with a file-name token and then goes on
	 * ("product_photo_1080.jpg image of shoes" — the whole-alt
	 * patterns above deliberately let sentences through, and this
	 * is not a sentence, it is a file name with words after it),
	 * plus a redundant OPENER ("image of", "picture of",
	 * "photo of", "graphic of" at the very start of the alt,
	 * after an optional article — the reader already announces
	 * that it is an image, so the opener is pure noise). The
	 * phrase is only an opener: mid-sentence it is ordinary
	 * description, and flagging it was a proven false positive —
	 * the Twenty Twenty-Four homepage alt "Tourist taking photo
	 * of a building" is a model description, not a redundant
	 * alt (CV-10 gate find, probe-locked in wp43). The sentence
	 * guardrails hold: "Photo 2024 company picnic" has no
	 * extension token and no "photo of"; "Image 3 shows the
	 * team" likewise stays clean.
	 *
	 * @param string $alt Alt text (trimmed, non-empty).
	 * @param string $src Image src attribute.
	 * @return bool
	 */
	private static function alt_is_filename_or_redundant( $alt, $src ) {
		if ( self::alt_looks_like_filename( $alt, $src ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^\S+\.(png|jpe?g|gif|webp|svg|bmp|avif|ico)\s+\S/i', $alt ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^(an? |the )?(image|picture|photo|graphic) of\b/i', $alt ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Accessible-name checks for buttons and links.
	 */
	private function check_names( $dom, $xpath, $url, &$findings ) {
		$nodes = $xpath->query( '//button | //a[@href] | //input[@type="button" or @type="submit" or @type="reset" or @type="image"] | //*[@role="button"] | //*[@role="link"]' );
		foreach ( $nodes as $el ) {
			// Inside a not-presented subtree the element is out
			// of the accessibility tree entirely, so NO name
			// finding of any kind applies to it (B5a; widened
			// in loop round 2, R2-02, from the aria-hidden-only
			// ancestor test to the shared element_not_presented
			// guard: [hidden] ancestors and inline display:none
			// / visibility:hidden hide a control from everyone,
			// not just from AT). check_aria_hidden_focus is the
			// check that reports the focusable ones inside
			// aria-hidden — that is the correct finding for
			// that pattern.
			if ( $this->element_not_presented( $el ) ) {
				continue;
			}
			$tag     = strtolower( $el->tagName );
			$is_link = 'a' === $tag || 'link' === strtolower( $el->getAttribute( 'role' ) );
			$kind    = 'button' === $tag ? 'button' : ( $is_link ? 'link' : 'control' );
			if ( '' !== trim( $el->getAttribute( 'aria-label' ) ) ) {
				if ( $is_link ) {
					$this->maybe_generic_link_text( $el, $el->getAttribute( 'aria-label' ), $dom, $url, $findings );
				}
				continue;
			}
			// An aria-labelledby whose references are missing or empty
			// provides no name — it must not mask a finding.
			if ( $el->hasAttribute( 'aria-labelledby' ) && $this->labelledby_resolves( $el, $xpath ) ) {
				if ( $is_link ) {
					$this->maybe_generic_link_text( $el, $this->labelledby_text( $el, $xpath ), $dom, $url, $findings );
				}
				continue;
			}
			// Hidden from assistive technology: not in the accessibility
			// tree, so a missing name there is not a finding.
			if ( 'true' === strtolower( $el->getAttribute( 'aria-hidden' ) ) || $el->hasAttribute( 'hidden' ) ) {
				continue;
			}
			// Name from content (accname subset, locked in WP1): text
			// of descendants EXCLUDING aria-hidden and [hidden]
			// subtrees — that text never reaches assistive technology
			// (screen-reader-only classes DO count; they are in the
			// tree). Contained image alt text contributes too.
			$text = $this->visible_text( $el );
			if ( '' !== $text ) {
				// A name made only of symbols, emoji or punctuation
				// (a lone cart glyph) is technically text, but it
				// names nothing a user can act on — screen readers
				// announce a symbol name or skip it. That is a
				// different finding from having no name at all
				// (the empty-text path below), and the two never
				// fire on the same element.
				if ( 1 !== preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
					$findings[] = $this->finding(
						self::CAT_REVIEW, 'moderate', '4.1.2',
						ucfirst( $kind ) . ' name is only a symbol',
						'This ' . $kind . ' is named only by a symbol or emoji, which screen readers announce inconsistently or not at all, so its purpose is unclear. Give it a real text name (visible text or an aria-label), such as "View cart" for a cart icon.',
						$url, $dom->saveHTML( $el )
					);
				} elseif ( $is_link ) {
					$this->maybe_generic_link_text( $el, $text, $dom, $url, $findings );
				}
				continue;
			}
			// A submit/reset input with NO value attribute is
			// named by the browser itself — the UA default
			// ("Submit" / "Reset") is its accessible name, and
			// axe-core does not flag it (T1-10). An explicitly
			// empty value="" is an author-chosen state, not the
			// default, and still falls through to the finding.
			if ( 'input' === strtolower( $el->tagName ) && ! $el->hasAttribute( 'value' ) ) {
				$input_type = strtolower( trim( $el->getAttribute( 'type' ) ) );
				if ( 'submit' === $input_type || 'reset' === $input_type ) {
					continue;
				}
			}
			if ( 'input' === strtolower( $el->tagName ) && '' !== trim( $el->getAttribute( 'value' ) ) ) {
				continue;
			}
			// An image input's own alt attribute is its name source.
			if ( 'input' === strtolower( $el->tagName ) && '' !== trim( $el->getAttribute( 'alt' ) ) ) {
				continue;
			}
			$derivable  = $el->hasAttribute( 'title' ) && '' !== trim( $el->getAttribute( 'title' ) );
			$category   = $derivable ? self::CAT_FIX_A : self::CAT_REVIEW;
			$fix_note   = $derivable
				? 'The free plugin can derive a label from its title attribute automatically.'
				: 'No label can be derived safely, so this needs a human to name the control. The plugin will not invent a label.';
			$findings[] = $this->finding(
				$category, 'serious', '4.1.2',
				ucfirst( $kind ) . ' has no accessible name',
				'This ' . $kind . ' contains no text and no label, so screen reader users hear only "button" or "link" with no purpose. ' . $fix_note,
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
			// Inside a not-presented subtree the field is out
			// of the accessibility tree entirely (T1-09; the
			// shared guard since loop round 2, R2-02 — it was
			// an aria-hidden-only test, so a field inside a
			// display:none or [hidden] wrapper was accused of
			// having no label when no visitor ever meets it).
			if ( $this->element_not_presented( $el ) ) {
				continue;
			}
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
			$has_placeholder = $el->hasAttribute( 'placeholder' ) && '' !== trim( $el->getAttribute( 'placeholder' ) );
			if ( 1 === $candidates ) {
				$category = self::CAT_FIX_A;
				$note     = 'A single nearby label was found, so the free plugin can associate them automatically.';
			} elseif ( $has_placeholder ) {
				// Mirrors the fixer (1.0.2): the field's own
				// placeholder text becomes its accessible label.
				$category = self::CAT_FIX_A;
				$note     = 'No unique nearby label was found, but the field has placeholder text: the free plugin gives the field an accessible label using its own placeholder words. A visible label is still the better long-term fix — a placeholder disappears while typing.';
			} else {
				$category = self::CAT_REVIEW;
				$note     = 'No unique nearby label was found, so a human should associate this field with its label.';
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
	 * The name-bearing text of an element's subtree: descendant text,
	 * image alt values and svg aria-labels, skipping subtrees that
	 * are not presented at all — aria-hidden="true", [hidden], and
	 * inline display:none / visibility:hidden, the full channel
	 * set of node_hides_itself() (their content reaches nobody:
	 * not the accessibility tree, and not the eye either). The
	 * visibility channel is stateful within the walk: a
	 * descendant that re-declares visibility:visible presents
	 * its text again (round 3, R3-01).
	 * Screen-reader-only text counts — visually hidden via a
	 * class is not hidden from assistive technology, and this
	 * computation cannot see classes anyway. (Loop round 2,
	 * R2-03: the inline-style channels were missing here, so
	 * text nobody could see still named controls, posed as a
	 * video's transcript, and filled headings.)
	 *
	 * @param DOMNode $node Subtree root.
	 * @return string Normalized text ('' when nothing name-bearing).
	 */
	private function visible_text( $node ) {
		$parts = array();
		// The walk carries the EFFECTIVE visibility state:
		// visibility inherits, so a node that re-declares it
		// replaces the state for its own subtree — text inside
		// a visibility:visible island within a hidden wrapper
		// IS presented and names its control (round 3, R3-01;
		// the old walk pruned the wrapper and lost it). The
		// terminal channels prune outright: nothing inside a
		// display:none / aria-hidden / [hidden] subtree is
		// presented whatever it declares. The root's own
		// declared visibility seeds the state — its ancestors
		// are the caller's question, answered by
		// element_not_presented() before this is consulted.
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
					// An embedded svg names its context the way an
					// image does: its own aria-label is its text
					// alternative and wins when present (B5c — an
					// icon button whose svg is labelled "Close" IS
					// named). Without one, the walk below picks up
					// the svg's <title> and <text> content like any
					// other subtree, as it always has.
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
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', implode( ' ', $parts ) ) );
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

	/**
	 * The text this element's aria-labelledby resolves to: every
	 * referenced element's text, in order ('' when nothing
	 * resolves). Companion to labelledby_resolves().
	 *
	 * @param DOMElement $el    Element.
	 * @param DOMXPath   $xpath XPath.
	 * @return string
	 */
	private function labelledby_text( $el, $xpath ) {
		$parts = array();
		foreach ( preg_split( '/\s+/', trim( $el->getAttribute( 'aria-labelledby' ) ) ) as $id ) {
			if ( '' === $id ) {
				continue;
			}
			$ref = $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 );
			if ( $ref ) {
				$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $ref->textContent ) );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * Generic link text: a link whose WHOLE accessible name is
	 * one of a small stoplist ("click here", "read more", …)
	 * tells screen-reader users nothing when links are listed
	 * out of context. This is deliberately a review prompt, not
	 * a failure: surrounding context can give the link a clear
	 * purpose, and only a human can judge that. Matching is
	 * whole-name equality after normalization (lowercase,
	 * punctuation stripped) — "Read more about Studio pricing"
	 * and "Hereford cattle facts" do not match.
	 *
	 * @param DOMElement $el   The link element.
	 * @param string     $name Its resolved accessible name.
	 * @param DOMDocument $dom Document (for the snippet).
	 * @param string     $url  Page URL.
	 * @param array      $findings Findings (by reference).
	 */
	private function maybe_generic_link_text( $el, $name, $dom, $url, &$findings ) {
		$norm = strtolower( trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $name ) ) );
		$norm = trim( preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $norm ) ) );
		$generic = array( 'click here', 'here', 'read more', 'learn more', 'more', 'link', 'this link', 'go', 'continue' );
		if ( ! in_array( $norm, $generic, true ) ) {
			return;
		}
		$findings[] = $this->finding(
			self::CAT_REVIEW, 'moderate', '2.4.4',
			'Link text is generic',
			'This link\'s text ("' . $norm . '") does not say where it goes, so screen reader users who list the page\'s links hear it with no purpose. Check the surrounding content: if the sentence or heading around it makes the destination obvious, this is fine as-is — if not, give the link text that names its destination.',
			$url, $dom->saveHTML( $el )
		);
	}
	/**
	 * Does this <label> element actually say anything? A label
	 * with no text names nothing — screen readers announce an
	 * empty label as no label at all. The text test is the one
	 * labelledby_resolves() applies to referenced elements
	 * (normalized textContent), extended with the contained-
	 * image rule: a label whose only content is an image is
	 * named by that image's alt text. Screen-reader-only text
	 * counts — visually hidden is not hidden from AT.
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

	private function has_label( $el, $xpath ) {
		$ancestor = $el->parentNode;
		while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
			// A wrapping <label> counts only when it has text of
			// its own (B3): an empty wrapper names nothing, and
			// the shipped engine let it pass the field silently.
			if ( 'label' === strtolower( $ancestor->tagName ) && $this->label_has_text( $ancestor ) ) {
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
			// Any one associated label with real text is enough;
			// an empty <label for> names nothing (B3).
			$labels = $xpath->query( '//label[@for=' . $this->xpath_literal( $el->getAttribute( 'id' ) ) . ']' );
			foreach ( $labels as $label ) {
				if ( $this->label_has_text( $label ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Page title check: a missing <title> element, or one whose
	 * text is only whitespace, leaves the page unnamed — the
	 * title is the first thing a screen reader announces on
	 * arrival and the label browsers show in tabs, history and
	 * search results. This check was absent from the shipped
	 * engine (a regression the coverage review caught); titles
	 * are theme/SEO territory, so it is a human-review finding,
	 * never something the plugin writes itself.
	 */
	private function check_title( $dom, $url, &$findings ) {
		$titles = $dom->getElementsByTagName( 'title' );
		if ( 0 === $titles->length ) {
			$findings[] = $this->finding(
				self::CAT_REVIEW, 'moderate', '2.4.2',
				'Page title is missing or empty',
				'This page has no <title> element, so it has no name: screen readers announce nothing meaningful when the page loads, and browsers show the URL in the tab. Give the page a short, descriptive title (the theme or an SEO plugin usually controls it).',
				$url, '<head>'
			);
			return;
		}
		$title_el = $titles->item( 0 );
		$text     = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $title_el->textContent ) );
		if ( '' === $text ) {
			$findings[] = $this->finding(
				self::CAT_REVIEW, 'moderate', '2.4.2',
				'Page title is missing or empty',
				'This page\'s <title> element is empty, so the page has no name: screen readers announce nothing meaningful when the page loads, and browsers show the URL in the tab. Give the page a short, descriptive title (the theme or an SEO plugin usually controls it).',
				$url, $dom->saveHTML( $title_el )
			);
		}
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
		$lang    = $html_el->hasAttribute( 'lang' ) ? trim( $html_el->getAttribute( 'lang' ) ) : '';
		if ( '' !== $lang ) {
			// A present lang is judged on SHAPE only (C9): a
			// well-formed tag — 2–3 letter primary subtag, then
			// hyphenated subtags of 2–8 characters (en, en-US,
			// pt-BR, zh-Hans, es-419 all pass) — is accepted
			// without a registry lookup, so a shape-valid but
			// unregistered tag passes BY DESIGN. What fails is
			// what no browser or screen reader can parse at
			// all: "english", "e", "en_US", which are silently
			// ignored exactly as if the attribute were absent,
			// except that no other check would ever say so.
			if ( 1 === preg_match( '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $lang ) ) {
				return;
			}
			$findings[] = $this->finding(
				self::CAT_REVIEW, 'moderate', '3.1.1',
				'Language attribute looks invalid',
				'The <html> element has a lang attribute ("' . $lang . '"), but it is not a well-formed language tag like "en" or "en-US", so screen readers may ignore it and fall back to the wrong pronunciation rules. Replace it with the page\'s correct language code.',
				$url, '<html>'
			);
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
	private function check_skip_link( $dom, $xpath, $url, &$findings ) {
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return;
		}
		$dead_target = false;
		foreach ( $bodies->item( 0 )->getElementsByTagName( 'a' ) as $a ) {
			$fragment = $this->skip_fragment( $a->getAttribute( 'href' ), $url );
			if ( null === $fragment ) {
				continue;
			}
			// Recognition: what COUNTS as a skip link. Deliberately
			// generous — the link's text, class or aria-label may
			// say "skip", or use jump phrasing ("Jump to main
			// content", "Jump to recipe"), which is the same bypass
			// mechanism under another name. The shipped engine
			// accepted only the literal word "skip", so a page
			// whose skip link said "Jump to main content" (or was
			// named only by aria-label) was accused of having NO
			// skip link — and the free fix then added a duplicate
			// at render. A false accusation here is worse than a
			// generous match: the target-exists test below still
			// guards quality (a recognised link to a missing
			// target is reported as a dead target, not passed).
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
				// The skip link points at another page's fragment; its
				// target cannot be judged from this document, and a
				// skip link demonstrably exists — do not accuse it.
				return;
			}
			// A skip link counts only if its target exists — a link to
			// a missing id leads nowhere. The lookup goes through
			// XPath: DOMDocument::getElementById() is unreliable on
			// parsed documents (libxml does not index id attributes
			// without a DTD), which made valid skip links report as
			// dead (WP0 line-review find, fixed in 1.0).
			$hit = $xpath->query( '//*[@id=' . $this->xpath_literal( $fragment ) . ']' );
			if ( $hit && $hit->length > 0 ) {
				return;
			}
			$dead_target = true;
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
	 * Extract the fragment a link's href points at, when it targets
	 * this page. Themes write skip links three ways: fragment-only
	 * ("#content"), root-relative ("/#content"), and absolute
	 * ("https://site.test/page/#content") — 0.5.0 only recognised the
	 * first form (WP0 line-review find).
	 *
	 * @param string $href     The link's href attribute.
	 * @param string $page_url The audited page's URL.
	 * @return string|null The fragment id; 'other-page' when the href
	 *                     targets a fragment on a different page;
	 *                     null when the href has no usable fragment.
	 */
	private function skip_fragment( $href, $page_url ) {
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
		$target_path = wp_parse_url( substr( $href, 0, $hash ), PHP_URL_PATH );
		$page_path   = wp_parse_url( (string) $page_url, PHP_URL_PATH );
		$norm        = function ( $p ) {
			$p = '/' . trim( (string) $p, '/' );
			return '' === $p ? '/' : $p;
		};
		if ( null !== $target_path && $norm( $target_path ) !== $norm( $page_path ) ) {
			return 'other-page';
		}
		return $frag;
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
			// An id inside a not-presented subtree is not in
			// the document anyone meets (loop round 2, S-4 —
			// confirmed by reproduction): it neither collides
			// with a presented id nor pairs with another
			// hidden one into a finding, and skipping it also
			// means a hidden copy can never make a VISIBLE
			// element look duplicated.
			if ( $this->element_not_presented( $el ) ) {
				continue;
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
	 * Is this heading hidden — itself or via an ancestor —
	 * by aria-hidden="true", the hidden attribute, or an inline
	 * style of display:none / visibility:hidden? Inline styles
	 * only: the audit never loads stylesheet files, so a class
	 * that hides via CSS is beyond what a server-side check can
	 * see (documented boundary, same as the contrast limit).
	 *
	 * @param DOMElement $el Heading element.
	 * @return bool
	 */
	private function heading_is_hidden( $el ) {
		// The same walk element_not_presented() makes, over
		// the shared single-node predicate.
		return $this->element_not_presented( $el );
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
			if ( $this->heading_is_hidden( $el ) ) {
				// A hidden heading (B4) never triggers a finding:
				// the shipped engine let hidden headings drive the
				// sequence, so an invisible heading could be the
				// accused element in a skip finding — a phantom,
				// since assistive technology never meets it.
				// Adjudicated semantics: once the visible outline
				// has begun, the hidden heading still OCCUPIES its
				// level (it is in the document, and a reader of
				// the page source meets the full outline) but can
				// only raise the running level, never lower it and
				// never be named as a finding. So h1, hidden h2,
				// h3 is not accused (the h2 sits between them in
				// the document outline), while a visible h1 -> h3
				// skip still fires exactly as before. A hidden
				// heading BEFORE the first visible one starts
				// nothing: this engine has no first-heading rule,
				// and a hidden heading must not manufacture a skip
				// against the first heading AT users actually meet.
				// Accepted residue, stated plainly: a visible skip
				// whose missing level is supplied ONLY by a hidden
				// heading between two visible ones is not reported
				// — this engine consistently prefers silence over
				// accusing a page over content its AT users
				// cannot see.
				if ( $prev > 0 && $level > $prev ) {
					$prev = $level;
				}
				continue;
			}
			if ( '' === $this->visible_text( $el ) ) {
				// An empty heading (C7) announces as a blank stop
				// in the heading outline: screen reader users
				// navigating by headings land on it and hear
				// nothing. It still occupies its level in the
				// sequence below — unlike a hidden heading it IS
				// in the accessibility tree, emptiness and all.
				// (A heading that is both empty AND hidden yields
				// neither finding: the hidden branch above already
				// continued past it.)
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '2.4.6',
					'Heading is empty',
					'This heading element contains no text, so screen reader users navigating by headings meet a blank entry in the outline. Remove the empty heading, or give it the text it was meant to carry.',
					$url, $dom->saveHTML( $el )
				);
			}
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
	 * not reported: inventory is not an issue. A frame that is not
	 * presented at all (T1.6, G-2) is likewise not reported: hidden
	 * and zero-size frames — the tag-manager noscript frame is the
	 * standing example, parsed as a real element because the parser
	 * runs with scripting off — are encountered by no visitor, and
	 * the owner cannot act on them.
	 */
	private function check_embeds( $dom, $xpath, $url, &$findings ) {
		$page_host = $this->host_of( $url );
		foreach ( $dom->getElementsByTagName( 'iframe' ) as $iframe ) {
			$src = $iframe->getAttribute( 'src' );
			if ( '' === $src && ! $iframe->hasAttribute( 'srcdoc' ) ) {
				continue;
			}
			if ( $this->iframe_not_presented( $iframe ) ) {
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
	 * Is this iframe presented to anyone at all? A frame is NOT
	 * presented when it is removed from view by every channel the
	 * audit can see in the page's own markup: aria-hidden="true"
	 * on the frame or an ancestor, a [hidden] attribute on either,
	 * an inline style of display:none / visibility:hidden on
	 * either (inline styles only — the audit never loads
	 * stylesheets, the documented boundary everywhere), or a
	 * width/height of at most 1 pixel on the frame itself: a
	 * frame that small is a tracking artefact, not content a
	 * visitor meets. Tag-manager and analytics frames are built
	 * exactly this way; findings against them are unactionable
	 * noise that teaches owners to ignore real embed findings.
	 *
	 * @param DOMElement $iframe Frame element.
	 * @return bool
	 */
	private function iframe_not_presented( $iframe ) {
		foreach ( array( 'width', 'height' ) as $dim ) {
			$raw = trim( $iframe->getAttribute( $dim ) );
			if ( 1 === preg_match( '/^(\d+)(px)?$/i', $raw, $m ) && (int) $m[1] <= 1 ) {
				return true;
			}
			// The same size written as an inline style instead
			// of the attribute (loop round 2, R2-07): builders
			// size tracking frames from the style attribute as
			// readily as from width/height, and the size rule
			// judged only the attribute.
			$styled = $this->inline_style_dimension( $iframe, $dim );
			if ( null !== $styled && $styled <= 1 ) {
				return true;
			}
		}
		return $this->element_not_presented( $iframe );
	}

	/**
	 * One dimension (width or height) as declared in an
	 * element's inline style, in pixels, or null when the
	 * style does not declare it in a plain pixel form this
	 * bounded reader accepts (unitless or px, an !important
	 * suffix tolerated). Percentages, em/rem and calc() are
	 * not pixel sizes and stay outside the rule — the same
	 * conservatism the attribute test applies.
	 *
	 * @param DOMElement $el   Element.
	 * @param string     $prop 'width' or 'height'.
	 * @return float|null
	 */
	private function inline_style_dimension( $el, $prop ) {
		// The shared declaration resolver decides which
		// declaration of the property stands — duplicates and
		// !important resolve exactly as they do for every
		// other consumer (round 3, R3-05: this reader kept
		// the last declaration unconditionally, so a standing
		// 0px !important after a wide duplicate judged a
		// presented frame absent, and the reverse pair judged
		// an absent frame presented). An unparseable WINNING
		// declaration reads as unknown, the same conservatism
		// the old reader applied to an unparseable last one.
		$decls = $this->resolve_style_declarations( (string) $el->getAttribute( 'style' ), array( 'width', 'height' ) );
		if ( ! isset( $decls[ $prop ] ) ) {
			return null;
		}
		return 1 === preg_match( '/^(\d+(?:\.\d+)?)(px)?$/i', trim( $decls[ $prop ]['value'] ), $m ) ? (float) $m[1] : null;
	}

	/**
	 * Is this element not presented to anyone — itself or an
	 * ancestor removed from view by every channel the audit can
	 * see in the page's own markup: aria-hidden="true", a
	 * [hidden] attribute, or an inline style of display:none /
	 * visibility:hidden (inline styles only — the audit never
	 * loads stylesheets, the documented boundary everywhere)?
	 * The general form of the embed guard above (loop round 1,
	 * H-05/H-06): media and table elements hidden the same way
	 * are equally absent from what a visitor meets, and checks
	 * that flag them produce the same unactionable noise the
	 * embed guard exists to prevent. Since loop round 3
	 * (R3-01) the visibility channel resolves the way CSS
	 * does: it inherits, so the nearest declaration walking
	 * up decides — a visibility:visible descendant inside a
	 * hidden wrapper is presented, while the display and
	 * attribute channels stay absolute at any depth. Since loop round 2
	 * (R2-02/S-4) this is THE not-presented guard for the whole
	 * audit — images, names, labels, autocomplete, duplicate
	 * ids, interactive elements and positive tabindex all
	 * consult it, after the hunt found four checks flagging
	 * hidden content and three more validity checks doing the
	 * same. Two checks keep their own adjudicated semantics
	 * by design: check_aria_hidden_focus EXISTS to report
	 * focusables inside aria-hidden, and check_headings gives
	 * hidden headings outline-occupation semantics (B4).
	 * Boundary, stated plainly (round-2 suspicion S-2): the
	 * channels here are the ones written on the elements
	 * themselves (attributes and inline styles). Hiding done
	 * by a rule in a <style> block is resolved by the
	 * contrast check alone, whose bounded model was built
	 * for it; extending rule resolution to every check is
	 * beyond that model, so a subtree hidden only by a style
	 * rule is still judged by the other checks.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private function element_not_presented( $el ) {
		// The visibility channel is decided by the NEAREST
		// node, walking up from the element, that declares a
		// visibility at all: visibility inherits, and a
		// descendant that re-declares visibility:visible IS
		// presented even inside a visibility:hidden subtree
		// (round 3, R3-01 — the old any-ancestor test called
		// re-shown content hidden in both directions: real
		// unnamed images and contrast pairs were skipped, and
		// presented names and transcripts were treated as
		// absent). The other channels cannot be re-shown: a
		// terminally-hidden ancestor (display:none,
		// aria-hidden, [hidden]) removes the whole subtree
		// whatever a descendant declares.
		$visibility_decided = false;
		$node               = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
			if ( $this->node_terminally_hidden( $node ) ) {
				return true;
			}
			if ( ! $visibility_decided ) {
				$visibility = $this->node_own_visibility( $node );
				if ( null !== $visibility ) {
					$visibility_decided = true;
					if ( 'hidden' === $visibility || 'collapse' === $visibility ) {
						return true;
					}
				}
			}
			$node = $node->parentNode;
		}
		return false;
	}

	/**
	 * Does this ONE node hide itself — aria-hidden="true", a
	 * [hidden] attribute, or an inline style whose RESOLVED
	 * declarations hide it (display:none, or visibility
	 * hidden/collapse)? The single-node predicate
	 * visible_text() builds from, resolved through the shared
	 * declaration tokenizer: duplicate declarations and
	 * !important follow the cascade, and text that merely
	 * mentions the keywords — a comment, a custom property,
	 * a content string, an url value — is not a declaration
	 * and hides nothing (round 3, R3-01; the previous reader
	 * matched substrings of the whitespace-stripped style).
	 * The channels stay shared so they can never drift apart
	 * again — the round-2 hunt (R2-02/R2-03) found them
	 * drifted: whole checks that never consulted the shared
	 * test, and a text computation that skipped two channels
	 * but not the third.
	 *
	 * @param DOMElement $node Node.
	 * @return bool
	 */
	private function node_hides_itself( $node ) {
		if ( $this->node_terminally_hidden( $node ) ) {
			return true;
		}
		$visibility = $this->node_own_visibility( $node );
		return 'hidden' === $visibility || 'collapse' === $visibility;
	}

	/**
	 * Does this ONE node remove itself AND its whole subtree,
	 * with no way for a descendant to come back —
	 * aria-hidden="true", a [hidden] attribute, or a resolved
	 * inline display of none? (A display:none subtree is not
	 * rendered at all; nothing inside it can re-show itself.)
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
	 * any: the resolved inline value ('visible', 'hidden',
	 * 'collapse', ...), or null when it declares none. Only
	 * visibility gets a per-node reader — it is the one
	 * channel a descendant can legitimately re-declare, so
	 * its consumers must know WHERE the value came from.
	 *
	 * @param DOMElement $node Node.
	 * @return string|null Lowercased value or null.
	 */
	private function node_own_visibility( $node ) {
		$decls = $this->resolve_style_declarations( (string) $node->getAttribute( 'style' ), array( 'visibility' ) );
		return isset( $decls['visibility'] ) ? strtolower( trim( $decls['visibility']['value'] ) ) : null;
	}

	/**
	 * Viewport zoom check: a viewport meta that disables zooming
	 * (user-scalable=no/0) or caps it below 200% (maximum-scale < 2)
	 * locks low-vision users out of enlarging the page. A MISSING
	 * viewport meta is a different (theme-level) matter and is not
	 * this finding; a generous cap (maximum-scale=5) is fine.
	 */
	private function check_viewport( $dom, $url, &$findings ) {
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			if ( 'viewport' !== strtolower( trim( $meta->getAttribute( 'name' ) ) ) ) {
				continue;
			}
			$blocked = false;
			foreach ( preg_split( '/[,;]/', $meta->getAttribute( 'content' ) ) as $part ) {
				$kv = explode( '=', trim( $part ), 2 );
				if ( 2 !== count( $kv ) ) {
					continue;
				}
				$key   = strtolower( trim( $kv[0] ) );
				$value = strtolower( trim( $kv[1] ) );
				if ( 'user-scalable' === $key && ( 'no' === $value || '0' === $value ) ) {
					$blocked = true;
				}
				if ( 'maximum-scale' === $key && is_numeric( $value ) && (float) $value < 2 ) {
					$blocked = true;
				}
			}
			if ( $blocked ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'serious', '1.4.4',
					'Zoom is disabled on this page',
					'This page\'s viewport settings prevent or severely limit zooming (user-scalable=no, or a maximum scale below 200%), so low-vision users cannot enlarge the content on phones and tablets. Remove user-scalable=no and any low maximum-scale from the viewport meta tag in the theme.',
					$url, $dom->saveHTML( $meta )
				);
			}
		}
	}

	/**
	 * Dangling ARIA reference check: IDREF attributes
	 * (aria-describedby, aria-labelledby, aria-controls,
	 * aria-owns, aria-flowto, aria-activedescendant) that point at
	 * an id no element on the page carries. Assistive technology
	 * silently drops the reference, so the description, label or
	 * relationship the author intended never reaches the user.
	 * Reported once per element, naming every dangling reference;
	 * references that resolve and empty attributes are clean.
	 */
	private function check_aria_refs( $dom, $xpath, $url, &$findings ) {
		$attrs = array( 'aria-describedby', 'aria-labelledby', 'aria-controls', 'aria-owns', 'aria-flowto', 'aria-activedescendant' );
		$nodes = $xpath->query( '//*[@aria-describedby or @aria-labelledby or @aria-controls or @aria-owns or @aria-flowto or @aria-activedescendant]' );
		foreach ( $nodes as $el ) {
			$dangling = array();
			foreach ( $attrs as $attr ) {
				if ( ! $el->hasAttribute( $attr ) ) {
					continue;
				}
				foreach ( preg_split( '/\s+/', trim( $el->getAttribute( $attr ) ) ) as $id ) {
					if ( '' === $id ) {
						continue;
					}
					if ( ! $xpath->query( '//*[@id=' . $this->xpath_literal( $id ) . ']' )->item( 0 ) ) {
						$dangling[] = $attr . '="' . $id . '"';
					}
				}
			}
			if ( $dangling ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '4.1.2',
					'ARIA reference refers to an element that does not exist',
					'This element refers to ' . implode( ' and ', $dangling ) . ', but no element on this page has that id, so assistive technology gets nothing from the reference. Point the attribute at an element that exists, or remove it.',
					$url, $dom->saveHTML( $el )
				);
			}
		}
	}

	/**
	 * Focusable-inside-aria-hidden check (the inversion of the
	 * engine's usual aria-hidden rule): an interactive element
	 * that sits inside aria-hidden="true" is removed from the
	 * accessibility tree but stays in the keyboard tab order, so
	 * keyboard users land on a control their screen reader never
	 * announces. Static content inside aria-hidden is the
	 * legitimate decorative pattern and stays clean, as does
	 * aria-hidden="false". A disabled control cannot be focused
	 * at all, so it is not accused. An element with a negative
	 * tabindex is out of the tab order entirely, so the tabbing
	 * harm this check describes cannot occur for it either
	 * (T1.5 F-4).
	 */
	private function check_aria_hidden_focus( $dom, $xpath, $url, &$findings ) {
		$nodes = $xpath->query( '//a[@href] | //button | //input[@type!="hidden"] | //select | //textarea | //*[@tabindex] | //*[@role="button" or @role="link"]' );
		foreach ( $nodes as $el ) {
			$tag = strtolower( $el->tagName );
			if ( ( 'button' === $tag || 'input' === $tag || 'select' === $tag || 'textarea' === $tag ) && $el->hasAttribute( 'disabled' ) ) {
				continue;
			}
			// A negative tabindex takes the element out of the
			// tab order entirely (T1.5 F-4, dossier review
			// R-09): the closed-drawer pattern pairs an
			// aria-hidden container with tabindex="-1" on its
			// contents, and for those elements this finding's
			// stated harm — keyboard users tabbing to a control
			// the screen reader never announces — cannot occur.
			// The element stays click- and script-focusable, a
			// lesser concern this check does not describe, so it
			// is not accused. Strict integer parse, the same
			// discipline check_tabindex() applies.
			if ( $el->hasAttribute( 'tabindex' ) ) {
				$raw_tabindex = trim( $el->getAttribute( 'tabindex' ) );
				if ( 1 === preg_match( '/^-?\d+$/', $raw_tabindex ) && (int) $raw_tabindex < 0 ) {
					continue;
				}
			}
			// The interactive set: natively interactive elements,
			// anything carrying a non-negative tabindex, and
			// button/link roles. A plain element with a negative
			// or unparseable tabindex is not in the tab order.
			$interactive = in_array( $tag, array( 'button', 'input', 'select', 'textarea' ), true )
				|| ( 'a' === $tag && $el->hasAttribute( 'href' ) )
				|| in_array( strtolower( $el->getAttribute( 'role' ) ), array( 'button', 'link' ), true );
			if ( ! $interactive && $el->hasAttribute( 'tabindex' ) ) {
				$tabindex    = trim( $el->getAttribute( 'tabindex' ) );
				$interactive = is_numeric( $tabindex ) && (int) $tabindex >= 0;
			}
			if ( ! $interactive ) {
				continue;
			}
			$node   = $el;
			$hidden = false;
			while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
				if ( 'true' === strtolower( trim( $node->getAttribute( 'aria-hidden' ) ) ) ) {
					$hidden = true;
					break;
				}
				$node = $node->parentNode;
			}
			if ( $hidden ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'serious', '4.1.2',
					'Interactive element is hidden from assistive technology but can still be focused',
					'This control sits inside aria-hidden="true", so screen readers never announce it — but keyboard users can still tab to it and land on a control they cannot identify. Remove the aria-hidden from the container, or remove the control from the tab order as well.',
					$url, $dom->saveHTML( $el )
				);
			}
		}
	}

	/**
	 * Non-native interactive check: an element carrying an
	 * onclick attribute that is not a real button or link (and
	 * has no role saying what it is) cannot be reached or
	 * operated by keyboard at all — a mouse-only control.
	 * Native interactive elements handle their own onclick
	 * legitimately, and an element with a role is name-checked
	 * as a control elsewhere. Document-level elements (html,
	 * head, body) are excluded: handlers there are event
	 * delegation for the whole page, not fake buttons. Known
	 * limit: scripts that attach handlers from JavaScript leave
	 * no trace in the page HTML, so this catches only
	 * attribute-carried handlers.
	 */
	private function check_interactive( $dom, $xpath, $url, &$findings ) {
		$native = array( 'a', 'button', 'input', 'select', 'textarea', 'summary', 'label' );
		foreach ( $xpath->query( '//*[@onclick]' ) as $el ) {
			$tag = strtolower( $el->tagName );
			if ( in_array( $tag, $native, true ) || in_array( $tag, array( 'html', 'head', 'body' ), true ) ) {
				continue;
			}
			if ( '' === trim( $el->getAttribute( 'onclick' ) ) || $el->hasAttribute( 'role' ) ) {
				continue;
			}
			// A click handler inside a not-presented subtree
			// cannot be clicked by anyone (loop round 2,
			// S-4): same shared guard as every other check.
			if ( $this->element_not_presented( $el ) ) {
				continue;
			}
			$findings[] = $this->finding(
				self::CAT_REVIEW, 'serious', '2.1.1',
				'Clickable element is not a real button or link',
				'This element responds to mouse clicks (it has an onclick handler) but it is not a real button or link and has no role, so keyboard users cannot reach it or operate it, and screen readers do not announce it as a control. Use a real <button> or <a> element for anything clickable. This check sees handlers written as onclick attributes in the page HTML; handlers attached invisibly by scripts cannot be seen by a server-side check.',
				$url, $dom->saveHTML( $el )
			);
		}
	}

	/**
	 * Positive tabindex check: a tabindex greater than zero
	 * pulls an element ahead of everything else in the tab
	 * order, ahead of content that precedes it in the document,
	 * so keyboard users meet controls out of sequence — and
	 * the order silently rots as the page changes. Zero (in
	 * the natural order) and -1 (programmatically focusable,
	 * not in the tab order) are the legitimate values; anything
	 * that is not a plain integer is not a tabindex at all.
	 */
	private function check_tabindex( $dom, $xpath, $url, &$findings ) {
		foreach ( $xpath->query( '//*[@tabindex]' ) as $el ) {
			$raw = trim( $el->getAttribute( 'tabindex' ) );
			if ( 1 !== preg_match( '/^-?\d+$/', $raw ) ) {
				continue;
			}
			// Not presented, not in any tab order (loop
			// round 2, S-4): same shared guard.
			if ( $this->element_not_presented( $el ) ) {
				continue;
			}
			if ( (int) $raw > 0 ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '2.4.3',
					'Positive tabindex breaks the natural focus order',
					'This element has tabindex="' . $raw . '", which pulls it ahead of other content in the keyboard tab order, so keyboard users reach it out of sequence. Let the document order rule instead: use tabindex="0" to add an element to the natural order, or "-1" for elements that should only receive focus from scripts.',
					$url, $dom->saveHTML( $el )
				);
			}
		}
	}

	/**
	 * Missing autocomplete check: a field that collects a
	 * person's own data (WCAG 1.3.5's input purposes — name,
	 * email, phone, address…) without an autocomplete attribute
	 * makes browsers unable to fill it in, which is the whole
	 * point of the criterion for users who type slowly or with
	 * difficulty. The purpose signal is deliberately tight:
	 * type="email"/"tel" speaks for itself; otherwise the
	 * field's name/id must contain a purpose word as a WHOLE
	 * token (the full value, or a _/- delimited segment), so
	 * "filename" and "rename" never match on their inner
	 * "name". Two deferrals, documented: password fields are
	 * not judged (their autocomplete values, current- vs
	 * new-password, cannot be told apart from HTML), and
	 * search fields are out of the criterion's scope entirely.
	 * Non-textual types (checkbox, radio, file, range, color)
	 * are likewise out of scope — they cannot take a free-text
	 * value at all; date fields are NOT excluded, because
	 * autocomplete="bday" is one of the criterion's own
	 * purposes. The mere presence of an autocomplete attribute
	 * satisfies the check — judging its value is a human call.
	 */
	private function check_autocomplete( $dom, $xpath, $url, &$findings ) {
		// Non-textual types are out of scope (T1.6, G-3): a
		// checkbox, radio, file, range or colour control cannot
		// accept a free-text personal-data value, so a purpose
		// word in its name (a "country" radio group, an "email"
		// consent checkbox, a honeypot) is not a missing
		// autocomplete hint — browsers do not autofill them.
		// Date-family types are deliberately NOT skipped: 1.3.5's
		// own purposes include bday, and autocomplete="bday" on a
		// date field is a legitimate, supported pattern.
		$skip_types = array( 'hidden', 'submit', 'button', 'image', 'reset', 'password', 'search', 'checkbox', 'radio', 'file', 'range', 'color' );
		$tokens     = array( 'name', 'email', 'mail', 'phone', 'tel', 'mobile', 'address', 'city', 'state', 'zip', 'postal', 'country', 'username', 'user_name', 'given', 'family', 'surname' );
		foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
			$type = strtolower( trim( $input->getAttribute( 'type' ) ) );
			if ( '' === $type ) {
				$type = 'text';
			}
			if ( in_array( $type, $skip_types, true ) ) {
				continue;
			}
			// Inside a not-presented subtree the field is out
			// of the accessibility tree entirely (T1-09; the
			// shared guard since loop round 2, R2-02).
			if ( $this->element_not_presented( $input ) ) {
				continue;
			}
			if ( $input->hasAttribute( 'autocomplete' ) ) {
				continue;
			}
			// Search boxes are excluded by name as well as by
			// type: "search" anywhere in the name/id (which also
			// catches "research") marks the field as a search
			// field, and search is not a 1.3.5 input purpose.
			$ident = strtolower( $input->getAttribute( 'name' ) . ' ' . $input->getAttribute( 'id' ) );
			if ( false !== strpos( $ident, 'search' ) ) {
				continue;
			}
			$purpose = in_array( $type, array( 'email', 'tel' ), true );
			if ( ! $purpose ) {
				$candidates = array();
				foreach ( array( 'name', 'id' ) as $attr ) {
					$value = strtolower( trim( $input->getAttribute( $attr ) ) );
					if ( '' === $value ) {
						continue;
					}
					$candidates[] = $value;
					foreach ( preg_split( '/[_\-\s]+/', $value ) as $segment ) {
						$candidates[] = $segment;
					}
				}
				foreach ( $candidates as $candidate ) {
					if ( in_array( $candidate, $tokens, true ) ) {
						$purpose = true;
						break;
					}
				}
			}
			if ( $purpose ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '1.3.5',
					'Form field is missing an autocomplete hint',
					'This field collects personal information (its type or name says so) but has no autocomplete attribute, so browsers cannot fill it in automatically — a real barrier for users who type slowly, with one hand, or with assistive technology. Add the matching autocomplete value (for example autocomplete="email" on an email field).',
					$url, $dom->saveHTML( $input )
				);
			}
		}
	}

	/**
	 * Table-header check (Tier 2, T2-01 — coverage map C11,
	 * un-deferred by the launch plan): a DATA table whose cells
	 * carry no header association at all — no <th> anywhere and
	 * no headers attributes — gives screen-reader users a grid
	 * of unlabelled values; a header cell with no text in it is
	 * a label that announces as nothing. Both are human fixes
	 * (choosing headers means understanding the data), so both
	 * are review findings.
	 *
	 * The layout-table discipline is the whole game here: a
	 * false accusation against a layout table teaches owners
	 * to ignore the check. So a table is only judged when it
	 * leans DATA on conservative signals — it has a <caption>
	 * or a <thead>, or at least 3 rows — AND it is at least
	 * 2×2 (a single row or column leans layout and stays
	 * silent). role="presentation"/"none" is never judged, a
	 * grid/treegrid widget is a different pattern with its own
	 * header semantics and stays out, and when the signals
	 * disagree the check stays silent.
	 *
	 * Scope is deliberately NOT an audit finding: Pro's render
	 * fixer already adds scope where a header cell's position
	 * makes it unambiguous and warns the owner about the rest,
	 * so an audit-side scope accusation would double-report
	 * cells Pro fixes silently. Presence of a scope attribute
	 * or a headers association counts as header structure when
	 * judging whether a table has headers at all.
	 */
	private function check_tables( $dom, $xpath, $url, &$findings ) {
		foreach ( $dom->getElementsByTagName( 'table' ) as $table ) {
			$role = strtolower( trim( $table->getAttribute( 'role' ) ) );
			if ( in_array( $role, array( 'presentation', 'none', 'grid', 'treegrid' ), true ) ) {
				continue;
			}
			if ( $this->element_not_presented( $table ) ) {
				continue;
			}
			// This table's OWN rows and cells — a nested table's
			// rows belong to the nested table, never to its host.
			$rows     = $this->table_own( $table, 'tr' );
			$max_cols = 0;
			$cells    = array();
			foreach ( $rows as $row ) {
				$row_cells = array();
				foreach ( $row->childNodes as $cell ) {
					if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->tagName ), array( 'th', 'td' ), true ) ) {
						$row_cells[] = $cell;
						$cells[]     = $cell;
					}
				}
				$max_cols = max( $max_cols, count( $row_cells ) );
			}
			if ( count( $rows ) < 2 || $max_cols < 2 ) {
				continue; // Single row or column: leans layout.
			}
			$has_caption = false;
			$has_thead   = false;
			foreach ( $table->childNodes as $child ) {
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				if ( 'caption' === strtolower( $child->tagName ) ) {
					$has_caption = true;
				}
				if ( 'thead' === strtolower( $child->tagName ) ) {
					$has_thead = true;
				}
			}
			if ( ! $has_caption && ! $has_thead && count( $rows ) < 3 ) {
				continue; // No data signal strong enough: stay silent.
			}
			$ths        = $this->table_own( $table, 'th' );
			// A headers= reference only associates a cell with a
			// header when the referenced cell IS a header cell:
			// pointing headers= at a plain <td> gives the value
			// no header semantics in any assistive technology
			// (loop round 1, H-12).
			$own_ids    = array();
			foreach ( $ths as $th ) {
				$id = $th->getAttribute( 'id' );
				if ( '' !== $id ) {
					$own_ids[ $id ] = true;
				}
			}
			$associated = false;
			foreach ( $cells as $cell ) {
				if ( ! $cell->hasAttribute( 'headers' ) ) {
					continue;
				}
				foreach ( preg_split( '/\s+/', trim( $cell->getAttribute( 'headers' ) ) ) as $ref ) {
					if ( '' !== $ref && isset( $own_ids[ $ref ] ) ) {
						$associated = true;
						break 2;
					}
				}
			}
			if ( 0 === count( $ths ) && ! $associated ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'serious', '1.3.1',
					'Data table has no header cells',
					'This table presents data (' . count( $rows ) . ' rows) but none of its cells are header cells (<th>) and no cell points at a header, so screen readers announce every value with nothing saying what it means. Decide which row or column labels the data and mark those cells as <th> (your page builder usually has a "header row" setting for tables).',
					$url, $dom->saveHTML( $table )
				);
				continue;
			}
			foreach ( $ths as $th ) {
				// A header cell is empty only when NO name source
				// resolves: its own text (image alt text counts,
				// via visible_text), an aria-label, or an
				// aria-labelledby that resolves to real text
				// (loop round 1, H-11).
				if ( '' === $this->visible_text( $th ) && '' === trim( $th->getAttribute( 'aria-label' ) ) && '' === $this->labelledby_text( $th, $xpath ) ) {
					$findings[] = $this->finding(
						self::CAT_REVIEW, 'moderate', '1.3.1',
						'Table header cell is empty',
						'This header cell (<th>) contains no text, so screen readers announce an empty label for its row or column and the values under it lose their meaning. Put the label the header was meant to carry into the cell, or remove the empty header cell.',
						$url, $dom->saveHTML( $th )
					);
				}
			}
		}
	}

	/**
	 * Descendants of a table that belong to THIS table: elements
	 * whose nearest table ancestor is the table itself.
	 *
	 * @param DOMElement $table Table.
	 * @param string     $tag   Tag name.
	 * @return array
	 */
	private function table_own( $table, $tag ) {
		$out = array();
		foreach ( $table->getElementsByTagName( $tag ) as $el ) {
			$node = $el->parentNode;
			while ( $node && XML_ELEMENT_NODE === $node->nodeType && 'table' !== strtolower( $node->tagName ) ) {
				$node = $node->parentNode;
			}
			if ( $node === $table ) {
				$out[] = $el;
			}
		}
		return $out;
	}

	/**
	 * Media-presence check (Tier 2, T2-03): a <video> with no
	 * caption track and no transcript in sight, an <audio> with
	 * no transcript in sight, or an embedded third-party video
	 * whose captions — if any — live inside the provider's
	 * player where page code cannot see them. PRESENCE-LEVEL
	 * ONLY: the copy says exactly what was checked. Captions
	 * can be burned into a video or switched on in the player,
	 * so this check never claims the media has no captions in
	 * fact — it reports that the page itself offers no caption
	 * or transcript signal a visitor (or this audit) can find.
	 *
	 * A transcript signal is a link or heading whose text says
	 * "transcript", either inside the media element's container
	 * or — when the page carries exactly one media element, so
	 * there is nothing else it could belong to — anywhere on
	 * the page. Inside a shared container each signal belongs
	 * to one media element (the nearest unclaimed one), never
	 * to every player in the wrapper. An untitled video iframe is the embed check's
	 * finding, never double-flagged here; a titled one is
	 * flagged here because the embed check passes it by.
	 */
	private function check_media( $dom, $xpath, $url, &$findings ) {
		$videos = array();
		foreach ( $dom->getElementsByTagName( 'video' ) as $v ) {
			$videos[] = $v;
		}
		$audios = array();
		foreach ( $dom->getElementsByTagName( 'audio' ) as $a ) {
			$audios[] = $a;
		}
		$native_count = count( $videos ) + count( $audios );
		$page_transcript = null; // Lazily computed, only if needed.
		foreach ( array_merge( $videos, $audios ) as $media ) {
			if ( $this->element_not_presented( $media ) ) {
				continue;
			}
			$tag      = strtolower( $media->tagName );
			$is_video = 'video' === $tag;
			if ( $is_video && $media->hasAttribute( 'muted' ) && $media->hasAttribute( 'autoplay' ) && $media->hasAttribute( 'loop' ) ) {
				// The ambient background-loop pattern (muted +
				// autoplay + loop): a decorative hero backdrop,
				// not presented content with a soundtrack — a
				// captions prompt for it is unactionable noise
				// (loop round 1, suspicion S-3, confirmed for
				// this pattern only). A muted video WITHOUT
				// the full pattern keeps the prompt below: the
				// attribute alone says nothing about whether
				// the video carries meaningful audio/visual
				// content.
				continue;
			}
			if ( $is_video && $this->media_has_caption_track( $media ) ) {
				continue;
			}
			if ( $this->transcript_near( $media ) ) {
				continue;
			}
			if ( 1 === $native_count ) {
				if ( null === $page_transcript ) {
					$page_transcript = $this->page_has_transcript_signal( $dom, $xpath );
				}
				if ( $page_transcript ) {
					continue;
				}
			}
			if ( $is_video ) {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '1.2.2',
					'Video has no caption track or transcript',
					'This video has no <track> element offering captions in the page code, and no transcript was found near it. That does not prove the video has no captions — captions can be burned into the picture or provided by the player — so play the video and check. If it truly has none, add a captions track or publish a written transcript next to it: visitors who cannot hear the audio otherwise get none of its content.',
					$url, $dom->saveHTML( $media )
				);
			} else {
				$findings[] = $this->finding(
					self::CAT_REVIEW, 'moderate', '1.2.1',
					'Audio has no transcript',
					'This audio element has no transcript in the page code near it, and transcripts cannot be detected beyond the words on the page — if a transcript is published elsewhere, link it next to the player so visitors can find it. Without one, visitors who cannot hear the audio get none of its content.',
					$url, $dom->saveHTML( $media )
				);
			}
		}
		// Embedded videos: captions, if any, are set inside the
		// provider's player and are invisible to the page code.
		// Providers match on the host's own domain (the host IS
		// the provider domain or a subdomain of it) — never on a
		// substring, which credited any look-alike host that
		// merely contained a provider's name (loop round 1,
		// H-14: "notyoutube.example.net" is not YouTube).
		$video_hosts = array( 'youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'twitch.tv', 'wistia.com', 'wistia.net', 'brightcove.net' );
		foreach ( $dom->getElementsByTagName( 'iframe' ) as $iframe ) {
			$src = $iframe->getAttribute( 'src' );
			if ( '' === $src ) {
				continue;
			}
			$host = strtolower( (string) $this->host_of( $src ) );
			$is_video_embed = false;
			foreach ( $video_hosts as $domain ) {
				if ( '' !== $host && $this->host_matches_domain( $host, $domain ) ) {
					$is_video_embed = true;
					break;
				}
			}
			if ( ! $is_video_embed ) {
				continue;
			}
			// A frame that is not presented at all (the embed
			// check's G-2 guard) is not a media finding either
			// (loop round 1, H-05).
			if ( $this->iframe_not_presented( $iframe ) ) {
				continue;
			}
			// Untitled frames belong to the embed check — never
			// flag the same node twice.
			if ( '' === trim( $iframe->getAttribute( 'title' ) ) && '' === trim( $iframe->getAttribute( 'aria-label' ) )
				&& ! ( '' !== trim( $iframe->getAttribute( 'aria-labelledby' ) ) && $this->labelledby_resolves( $iframe, $xpath ) ) ) {
				continue;
			}
			$findings[] = $this->finding(
				self::CAT_REVIEW, 'moderate', '1.2.2',
				'Embedded video captions cannot be checked from the page code',
				'This embedded video loads its player from ' . $host . ', so whether it has captions is decided inside that provider\'s player and cannot be seen in this page\'s code — this is a prompt to check, not a finding that captions are missing. Play the video, turn captions on, and confirm they exist and are accurate; if the video is yours, captions are managed in the provider\'s studio.',
				$url, $dom->saveHTML( $iframe )
			);
		}
	}

	/**
	 * Does this <video> carry a track that offers captions or
	 * subtitles? A <track> with no kind defaults to subtitles
	 * per the HTML spec; kind="descriptions" is a separate
	 * track type and does not satisfy the captions question.
	 *
	 * @param DOMElement $media Video element.
	 * @return bool
	 */
	private function media_has_caption_track( $media ) {
		foreach ( $media->getElementsByTagName( 'track' ) as $track ) {
			$kind = strtolower( trim( $track->getAttribute( 'kind' ) ) );
			if ( '' === $kind || 'captions' === $kind || 'subtitles' === $kind ) {
				// A track with no src loads nothing — the empty
				// element alone offers no captions (loop
				// round 1, H-13).
				if ( '' !== trim( $track->getAttribute( 'src' ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Is there a transcript signal inside the media element's
	 * container — a link or heading whose visible text says
	 * "transcript"? (The container is the wrapping element
	 * builders put a player and its transcript link in.)
	 *
	 * @param DOMElement $media Media element.
	 * @return bool
	 */
	private function transcript_near( $media ) {
		$container = $media->parentNode;
		if ( ! $container || XML_ELEMENT_NODE !== $container->nodeType ) {
			return false;
		}
		// The page's own landmark containers are not a media
		// wrapper: a media element sitting directly in <main>
		// or <body> has its "container" be the whole page, and
		// a transcript link anywhere in the content would
		// silence it wrongly. Only a local wrapper (a div,
		// figure, section…) counts as "near"; the page-level
		// signal is reserved for single-media pages.
		if ( in_array( strtolower( $container->tagName ), array( 'main', 'body', 'html' ), true ) ) {
			return false;
		}
		// Association (loop round 1, H-09): a wrapper can hold
		// several players, and one transcript link belongs to
		// ONE of them — the nearest player before it that no
		// earlier signal already claimed (the pattern builders
		// write: player, then its transcript link), or, when no
		// player precedes it, the nearest player after it. Walk
		// the wrapper in document order pairing signals to
		// players; a signal never silences the whole wrapper.
		$covered       = array(); // spl_object_id => true.
		$prev_media    = null;    // Nearest preceding unclaimed player.
		$pending_claim = 0;       // Signals waiting for a following player.
		$signal_els    = array(); // Accepted signal elements (top-most only).
		foreach ( $container->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->tagName );
			if ( 'video' === $tag || 'audio' === $tag ) {
				if ( $pending_claim > 0 ) {
					$covered[ spl_object_id( $el ) ] = true;
					$pending_claim--;
				} else {
					$prev_media = $el;
				}
				continue;
			}
			if ( in_array( $tag, array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true )
				&& ! $this->element_not_presented( $el )
				&& false !== stripos( $this->visible_text( $el ), 'transcript' ) ) {
				// A signal nested inside another signal (a link
				// inside a "Transcript" heading) is the SAME
				// signal, not a second one.
				$nested = false;
				$up     = $el->parentNode;
				while ( $up && $up !== $container ) {
					if ( in_array( $up, $signal_els, true ) ) {
						$nested = true;
						break;
					}
					$up = $up->parentNode;
				}
				if ( $nested ) {
					continue;
				}
				$signal_els[] = $el;
				if ( null !== $prev_media ) {
					$covered[ spl_object_id( $prev_media ) ] = true;
					$prev_media = null;
				} else {
					$pending_claim++;
				}
			}
		}
		return isset( $covered[ spl_object_id( $media ) ] );
	}

	/**
	 * Is there a transcript signal anywhere on the page? Only
	 * consulted when the page carries exactly one media
	 * element, so the signal cannot belong to a different one.
	 *
	 * @param DOMDocument $dom Document.
	 * @param DOMXPath    $xpath XPath.
	 * @return bool
	 */
	private function page_has_transcript_signal( $dom, $xpath ) {
		foreach ( $xpath->query( '//a | //h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) as $el ) {
			// A signal nobody is presented covers nothing
			// (loop round 2, R2-03): visible_text() judges
			// the subtree it is handed, so a link styled
			// display:none — or sitting inside a hidden
			// wrapper — still "said" transcript when the
			// link itself was the subtree. The presented
			// gate belongs on the signal element, here and
			// in transcript_near().
			if ( $this->element_not_presented( $el ) ) {
				continue;
			}
			if ( false !== stripos( $this->visible_text( $el ), 'transcript' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Bounded static contrast check (Tier 2, T2-04 — owner
	 * decision D-1: INCLUDE, bounded). Owner question asked of
	 * every contrast tool: "is this text readable against its
	 * background?" A server-side audit can only answer it when
	 * BOTH colours are written in the page itself. This check
	 * therefore evaluates ONLY:
	 *   (a) inline style colour/background declarations, on the
	 *       element or inherited from an inline-styled ancestor;
	 *   (b) rules in the page's own <style> blocks whose
	 *       selectors are a single tag, class, id, tag+class or
	 *       tag#id — nothing with combinators, pseudo states or
	 *       attribute matching, and no @-rule (media/container)
	 *       contents, because which branch applies is unknowable
	 *       without a browser.
	 * Anything else — external stylesheets, gradients or
	 * background images anywhere in the element's background
	 * chain, alpha that never reaches an opaque page-written
	 * colour, hsl()/var()/currentColor values, font sizes in
	 * relative units — is NOT DETERMINABLE and the check stays
	 * silent. Silence is the design: a bounded check that
	 * guesses is worse than no check. Ratios are the real WCAG
	 * computation (sRGB relative luminance); large text means
	 * ≥24px, or ≥18.66px bold, and size/weight count ONLY when
	 * determinable from the same bounded sources — never from
	 * tag-name defaults — otherwise the normal 4.5:1 threshold
	 * applies. Review category, capped per page so a repeated
	 * failing pattern cannot flood the stored findings, and
	 * the finding text states the bound in plain words.
	 */
	private function check_contrast( $dom, $xpath, $url, &$findings ) {
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return;
		}
		$rules   = $this->contrast_style_rules( $dom );
		// Loop fix round (test-4), BUG 4: 'html' (the root element is
		// not rendered text — its text is the whole document),
		// 'option'/'optgroup' (rendered by the browser chrome, not by
		// page CSS) can never be legitimate rendered-text contrast
		// findings and join the skip list.
		$skip    = array( 'script', 'style', 'noscript', 'template', 'svg', 'img', 'iframe', 'video', 'audio', 'source', 'track', 'input', 'select', 'textarea', 'br', 'hr', 'meta', 'link', 'title', 'head', 'html', 'option', 'optgroup' );
		$emitted = 0;
		foreach ( $xpath->query( './/*', $bodies->item( 0 ) ) as $el ) {
			if ( $emitted >= 10 ) {
				break;
			}
			if ( in_array( strtolower( $el->tagName ), $skip, true ) ) {
				continue;
			}
			// BUG 4 (test-4): never evaluate the root element itself,
			// however the document was parsed.
			if ( null !== $dom->documentElement && $el === $dom->documentElement ) {
				continue;
			}
			if ( ! $this->element_has_direct_text( $el ) ) {
				continue;
			}
			if ( $this->contrast_not_rendered( $el, $rules ) ) {
				continue;
			}
			$fg = $this->contrast_resolve_color( $el, $rules );
			if ( null === $fg ) {
				continue;
			}
			$bg = $this->contrast_resolve_background( $el, $rules );
			if ( null === $bg ) {
				continue;
			}
			if ( $fg['a'] < 1.0 ) {
				$fg = $this->color_composite_over( $fg, $bg );
			}
			$ratio = $this->contrast_ratio( $fg, $bg );
			$need  = $this->contrast_is_large_text( $el, $rules ) ? 3.0 : 4.5;
			if ( $ratio >= $need - 1e-9 ) {
				continue;
			}
			$findings[] = $this->finding(
				self::CAT_REVIEW, 'moderate', '1.4.3',
				'Low text contrast (from styles written in this page)',
				'This text\'s colours, as written in this page, measure ' . number_format( $ratio, 2 ) . ':1 (' . $this->color_label( $fg ) . ' text on ' . $this->color_label( $bg ) . '), below the ' . number_format( $need, 1 ) . ':1 that WCAG asks for ' . ( $need < 4.5 ? 'large text' : 'text this size' ) . '. This was checked only from styles written in the page itself — inline styles and the page\'s own style rules. Theme stylesheets, background images and hover states are not covered, so treat this as a prompt to look at the real page, not a complete contrast verdict.',
				$url, $dom->saveHTML( $el )
			);
			$emitted++;
		}
	}

	/**
	 * Does this element carry text of its own (a direct text
	 * child with non-whitespace)? Container elements are not
	 * judged — their text-bearing descendants are judged
	 * themselves, with inherited colours resolved upward.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private function element_has_direct_text( $el ) {
		foreach ( $el->childNodes as $child ) {
			if ( ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType )
				&& '' !== trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $child->nodeValue ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is this element not rendered — itself or an ancestor
	 * aria-hidden, [hidden], or with a bounded-resolvable
	 * display:none / visibility:hidden? Text nobody can see
	 * has no contrast to judge.
	 *
	 * @param DOMElement $el    Element.
	 * @param array      $rules Parsed bounded style rules.
	 * @return bool
	 */
	private function contrast_not_rendered( $el, $rules ) {
		// The visibility channel resolves like the rest of the
		// audit's (round 3, R3-01): the FIRST node walking up
		// that declares a visibility — inline or by bounded
		// rule — decides, so a re-shown element (its own
		// visibility:visible) IS rendered and judged even
		// inside a hidden wrapper, exactly as
		// element_not_presented() judges it presented. The
		// display and attribute channels stay absolute: a
		// display:none or aria-hidden ancestor ends the walk
		// as not-rendered whatever was decided below.
		$visibility_decided = false;
		$node               = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
			if ( 'true' === strtolower( trim( $node->getAttribute( 'aria-hidden' ) ) ) || $node->hasAttribute( 'hidden' ) ) {
				return true;
			}
			// Loop fix round (test-4), BUG 4: the standard
			// visually-hidden utility classes (WordPress core's
			// screen-reader-text, Bootstrap's sr-only /
			// visually-hidden) mark text that is read by assistive
			// technology but never painted — the skip link's text
			// is the standing case. The class token itself is the
			// statically knowable signal; no cascade is invented.
			if ( $node->hasAttribute( 'class' ) ) {
				$classes = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'class' ) ) ) );
				if ( is_array( $classes ) && array_intersect( $classes, array( 'screen-reader-text', 'sr-only', 'visually-hidden' ) ) ) {
					return true;
				}
			}
			$display = $this->declared_value( $node, 'display', $rules );
			if ( null !== $display && 'none' === strtolower( trim( $display['value'] ) ) ) {
				return true;
			}
			if ( ! $visibility_decided ) {
				$visibility = $this->declared_value( $node, 'visibility', $rules );
				if ( null !== $visibility ) {
					$visibility_decided = true;
					if ( in_array( strtolower( trim( $visibility['value'] ) ), array( 'hidden', 'collapse' ), true ) ) {
						return true;
					}
				}
			}
			$node = $node->parentNode;
		}
		return false;
	}

	/**
	 * Parse the page's <style> blocks into the bounded rule
	 * set: only rules whose selectors are a single tag, .class,
	 * #id, tag.class or tag#id. @-rule blocks (media queries,
	 * keyframes, supports) are skipped whole — which of their
	 * branches applies cannot be known server-side. Only the
	 * declarations the contrast model reads are kept.
	 *
	 * S4-3 CLOSED (round 6, LC-6): the brace matching that
	 * splits rules originally ignored quoted strings, so a
	 * '{' or '}' inside a string (a content glyph, say)
	 * truncated a rule body and derailed the FOLLOWING
	 * selector; the round-4 fixtures proved that direction
	 * miss-only, but the parent's final-dossier vetting
	 * constructed a shape its "true prefix" argument did not
	 * cover — a swallowed later rule's declaration beginning
	 * a clean ';' segment could be extracted into the wrong
	 * rule. The scan now tracks quoted strings (with
	 * backslash escapes) and parenthesis depth with the same
	 * discipline as the declaration tokenizer below, so
	 * braces inside strings or url(...) values are content,
	 * never structure, and a rule body is exactly its own.
	 * The class is closed in both directions and probe-locked
	 * in wp50 (both round-4 fixtures, the bleed shape, url()
	 * and escape variants). The style-block HIDING boundary
	 * documented at element_not_presented() is a separate,
	 * still-accepted limit (S-2).
	 *
	 * @param DOMDocument $dom Document.
	 * @return array Rules: {tag, key_type, key, spec, order, decls}.
	 */
	private function contrast_style_rules( $dom ) {
		$rules = array();
		$order = 0;
		foreach ( $dom->getElementsByTagName( 'style' ) as $style_el ) {
			$css = preg_replace( '/\/\*.*?\*\//s', '', $style_el->textContent );
			if ( null === $css ) {
				continue;
			}
			$len = strlen( $css );
			$i   = 0;
			while ( $i < $len ) {
				// Find the opening brace with the tokenizer's
				// string discipline (LC-6): braces inside
				// quoted strings or parentheses are content,
				// never structure.
				$open  = false;
				$quote = '';
				$par   = 0;
				for ( $k = $i; $k < $len; $k++ ) {
					$c = $css[ $k ];
					if ( '' !== $quote ) {
						if ( '\\' === $c ) {
							$k++;
							continue;
						}
						if ( $c === $quote ) {
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
					if ( ')' === $c ) {
						if ( $par > 0 ) {
							$par--;
						}
						continue;
					}
					if ( '{' === $c && 0 === $par ) {
						$open = $k;
						break;
					}
				}
				if ( false === $open ) {
					break;
				}
				$selector_text = trim( substr( $css, $i, $open - $i ) );
				// Find the matching close brace (blocks can nest
				// inside @-rules), same discipline: a brace
				// inside a string or an url(...) value never
				// opens or closes anything.
				$depth = 1;
				$quote = '';
				$par   = 0;
				$j     = $open + 1;
				$end   = $len; // Close-brace index; EOF when the rule never closes.
				while ( $j < $len ) {
					$c = $css[ $j ];
					if ( '' !== $quote ) {
						if ( '\\' === $c ) {
							$j += 2;
							continue;
						}
						if ( $c === $quote ) {
							$quote = '';
						}
						$j++;
						continue;
					}
					if ( '"' === $c || "'" === $c ) {
						$quote = $c;
						$j++;
						continue;
					}
					if ( '(' === $c ) {
						$par++;
						$j++;
						continue;
					}
					if ( ')' === $c ) {
						if ( $par > 0 ) {
							$par--;
						}
						$j++;
						continue;
					}
					if ( 0 === $par ) {
						if ( '{' === $c ) {
							$depth++;
						} elseif ( '}' === $c ) {
							$depth--;
							if ( 0 === $depth ) {
								$end = $j;
								$j++;
								break;
							}
						}
					}
					$j++;
				}
				$body = substr( $css, $open + 1, $end - $open - 1 );
				$i    = $j;
				if ( '' === $selector_text || '@' === $selector_text[0] ) {
					continue;
				}
				$decls = $this->parse_style_declarations( $body );
				if ( empty( $decls ) ) {
					continue;
				}
				foreach ( explode( ',', $selector_text ) as $selector ) {
					$selector = trim( $selector );
					if ( 1 !== preg_match( '/^([a-zA-Z][a-zA-Z0-9]*)?([.#][a-zA-Z_][\w-]*)?$/', $selector, $m ) ) {
						continue; // Out of bounds: combinators, pseudo, attributes, universals.
					}
					$tag      = isset( $m[1] ) ? strtolower( $m[1] ) : '';
					$key_type = isset( $m[2] ) && '' !== $m[2] ? $m[2][0] : '';
					$key      = isset( $m[2] ) && '' !== $m[2] ? substr( $m[2], 1 ) : '';
					if ( '' === $tag && '' === $key_type ) {
						continue;
					}
					$spec     = ( '#' === $key_type ? 100 : 0 ) + ( '.' === $key_type ? 10 : 0 ) + ( '' !== $tag ? 1 : 0 );
					$rules[]  = array(
						'tag'      => $tag,
						'key_type' => $key_type,
						'key'      => $key,
						'spec'     => $spec,
						'order'    => $order++,
						'decls'    => $decls,
					);
				}
			}
		}
		return $rules;
	}

	/**
	 * Tokenize a declaration block (a style attribute or a
	 * rule body) into [ property, value ] pairs. The single
	 * tokenizer every declaration consumer in this class
	 * shares (loop round 3, R3-01/R3-02): CSS comments are
	 * stripped — the style-block path always stripped them,
	 * the inline path did not, so a commented-out declaration
	 * was live, a real declaration after a comment was
	 * dropped, and a commented !important polluted a value —
	 * and a ';' or ':' inside a quoted string or inside
	 * parentheses (an url(...) value) is part of the value,
	 * never a separator: the naive split let separator text
	 * inside data URIs and quoted strings forge whole
	 * declarations. Comment markers inside a quoted string
	 * are string content, not comments.
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
			// Split property from value on the first ':' that
			// is outside quotes and parentheses.
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
	 * Resolve the declarations of one block into the winning
	 * declaration per property: prop => { value, important,
	 * pos }. A repeated property resolves the way CSS
	 * resolves it within a block: an !important declaration
	 * beats a normal one wherever each appears, and among
	 * declarations of equal importance the last one wins
	 * (loop round 2, R2-04). 'pos' is the KEPT declaration's
	 * place within its own block: cross-property cascade
	 * questions (the background shorthand vs the
	 * background-color longhand, H-07) are decided by it when
	 * importance and specificity tie.
	 *
	 * @param string $text Declaration text.
	 * @param array  $keep Property names to keep.
	 * @return array
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
			// An earlier !important stands against a later
			// normal duplicate; every other repeat is
			// last-wins. The kept declaration keeps its own
			// position (below).
			if ( isset( $decls[ $prop ] ) && $decls[ $prop ]['important'] && ! $important ) {
				continue;
			}
			$decls[ $prop ] = array( 'value' => $value, 'important' => $important, 'pos' => $pos++ );
		}
		return $decls;
	}

	/**
	 * Parse a declaration block (a style attribute or a rule
	 * body) into the properties the contrast model reads:
	 * prop => { value, important, pos }. Resolution and
	 * tokenizing live in the shared helpers above; this is
	 * the keep-list the contrast model reads.
	 *
	 * @param string $text Declaration text.
	 * @return array
	 */
	private function parse_style_declarations( $text ) {
		return $this->resolve_style_declarations( $text, array( 'color', 'background-color', 'background', 'background-image', 'font-size', 'font-weight', 'display', 'visibility' ) );
	}

	/**
	 * Lexicographic comparison of two cascade ranks
	 * (important, specificity, source order, position within
	 * block). True when $a strictly beats $b.
	 *
	 * @param array $a Rank.
	 * @param array $b Rank.
	 * @return bool
	 */
	private function cascade_rank_beats( $a, $b ) {
		foreach ( $a as $i => $v ) {
			if ( $v !== $b[ $i ] ) {
				return $v > $b[ $i ];
			}
		}
		return false;
	}

	/**
	 * Does a parsed bounded rule match this element?
	 *
	 * @param array      $rule Rule.
	 * @param DOMElement $el   Element.
	 * @return bool
	 */
	private function contrast_rule_matches( $rule, $el ) {
		if ( '' !== $rule['tag'] && strtolower( $el->tagName ) !== $rule['tag'] ) {
			return false;
		}
		if ( '#' === $rule['key_type'] ) {
			return $el->getAttribute( 'id' ) === $rule['key'];
		}
		if ( '.' === $rule['key_type'] ) {
			$classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );
			return in_array( $rule['key'], $classes, true );
		}
		return true;
	}

	/**
	 * The winning bounded declaration of one property on ONE
	 * element: inline style (specificity above any single
	 * selector) and matching style-block rules compete by
	 * !important first, then specificity, then source order —
	 * the CSS cascade reduced to what bounded sources can
	 * prove.
	 *
	 * @param DOMElement $el    Element.
	 * @param string     $prop  Property.
	 * @param array      $rules Parsed rules.
	 * @return array|null { value, important, pos, rank } or null when undeclared.
	 */
	private function declared_value( $el, $prop, $rules ) {
		$best      = null;
		$best_rank = null;
		$consider  = function ( $decl, $spec, $order ) use ( &$best, &$best_rank ) {
			$rank = array( $decl['important'] ? 1 : 0, $spec, $order, isset( $decl['pos'] ) ? $decl['pos'] : 0 );
			if ( null === $best_rank || $this->cascade_rank_beats( $rank, $best_rank ) ) {
				$best      = $decl;
				$best_rank = $rank;
			}
		};
		foreach ( $rules as $rule ) {
			if ( isset( $rule['decls'][ $prop ] ) && $this->contrast_rule_matches( $rule, $el ) ) {
				$consider( $rule['decls'][ $prop ], $rule['spec'], $rule['order'] );
			}
		}
		$inline = $this->parse_style_declarations( $el->getAttribute( 'style' ) );
		if ( isset( $inline[ $prop ] ) ) {
			$consider( $inline[ $prop ], 1000, PHP_INT_MAX );
		}
		if ( null !== $best ) {
			$best['rank'] = $best_rank;
		}
		return $best;
	}

	/**
	 * Resolve the text colour for an element: the nearest
	 * bounded colour declaration on the element or an ancestor
	 * (colour inherits). A declaration whose value cannot be
	 * parsed as a colour stops the walk with "undeterminable"
	 * — the author set SOMETHING there, and guessing past it
	 * would judge a colour the page does not use. The one
	 * exception is an explicit "inherit", which by definition
	 * keeps walking.
	 *
	 * @param DOMElement $el    Element.
	 * @param array      $rules Parsed rules.
	 * @return array|null {r,g,b,a} or null.
	 */
	private function contrast_resolve_color( $el, $rules ) {
		$node = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
			$decl = $this->declared_value( $node, 'color', $rules );
			if ( null !== $decl ) {
				if ( 'inherit' === strtolower( trim( $decl['value'] ) ) ) {
					$node = $node->parentNode;
					continue;
				}
				return $this->parse_color( $decl['value'] );
			}
			$node = $node->parentNode;
		}
		return null;
	}

	/**
	 * Resolve the effective background under an element: walk
	 * from the element upward collecting page-written
	 * background colours, compositing alpha layers until an
	 * opaque one is reached. Returns null (silent) when: any
	 * element in the chain up to and including the colour
	 * provider carries a background image (inline, rule, or a
	 * shorthand with an image/gradient — an image paints OVER
	 * the colour beneath it, so the colour is not the
	 * background a visitor sees); a declared background value
	 * cannot be parsed; or the chain runs out with alpha
	 * still unresolved (no page-written opaque backdrop —
	 * the browser canvas is NOT assumed, because a theme
	 * stylesheet the audit cannot read may paint it).
	 *
	 * @param DOMElement $el    Element.
	 * @param array      $rules Parsed rules.
	 * @return array|null {r,g,b,a=1} or null.
	 */
	private function contrast_resolve_background( $el, $rules ) {
		$layers = array();
		$node   = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
			// The background shorthand and the background-color /
			// background-image longhands are ONE cascade
			// question, not three independent ones (loop round
			// 1, H-07): the shorthand resets both longhands, so
			// whichever declaration wins — by importance,
			// specificity, source order, then position within
			// its block — is the one that paints. Resolving the
			// properties separately let a losing longhand
			// override a winning shorthand (white text on a
			// "white" background that is really black).
			$shorthand = $this->declared_value( $node, 'background', $rules );
			$longhand  = $this->declared_value( $node, 'background-color', $rules );
			$image     = $this->declared_value( $node, 'background-image', $rules );
			$short_wins = null !== $shorthand && ( null === $longhand || $this->cascade_rank_beats( $shorthand['rank'], $longhand['rank'] ) );
			// Effective image: the image longhand decides when
			// it beats the shorthand (or no shorthand exists);
			// otherwise the shorthand's own image (if any) is
			// what the reset leaves in place.
			$has_image = false;
			if ( null !== $image && ( null === $shorthand || $this->cascade_rank_beats( $image['rank'], $shorthand['rank'] ) ) ) {
				$has_image = 'none' !== strtolower( trim( $image['value'] ) ) && '' !== trim( $image['value'] );
			} elseif ( null !== $shorthand ) {
				$sv        = strtolower( $shorthand['value'] );
				$has_image = false !== strpos( $sv, 'url(' ) || false !== strpos( $sv, '-gradient(' );
			}
			if ( $has_image ) {
				return null;
			}
			$color = null;
			if ( $short_wins ) {
				$sv    = strtolower( $shorthand['value'] );
				$color = $this->color_from_shorthand( $shorthand['value'] );
				if ( null === $color && 1 === preg_match( '/\b(var\(|hsl|currentcolor|inherit|initial|unset)\b/i', $sv ) ) {
					return null; // A colour was declared in a form we cannot read.
				}
				// A winning shorthand with no colour token
				// resets the colour to transparent: no layer
				// from this node, and the losing longhand is
				// NOT consulted — the walk continues upward.
			} elseif ( null !== $longhand ) {
				if ( 'inherit' !== strtolower( trim( $longhand['value'] ) ) ) {
					$color = $this->parse_color( $longhand['value'] );
					if ( null === $color ) {
						return null; // Declared but unreadable: the chain is unknowable.
					}
				}
			}
			if ( null !== $color ) {
				$layers[] = $color;
				if ( $color['a'] >= 1.0 ) {
					$out = array_pop( $layers );
					while ( ! empty( $layers ) ) {
						$out = $this->color_composite_over( array_pop( $layers ), $out );
					}
					return $out;
				}
			}
			$node = $node->parentNode;
		}
		return null;
	}

	/**
	 * Extract the colour token from a `background` shorthand
	 * value, when it carries one readable colour.
	 *
	 * @param string $value Shorthand value.
	 * @return array|null {r,g,b,a} or null.
	 */
	private function color_from_shorthand( $value ) {
		foreach ( preg_split( '/\s+/', trim( $value ) ) as $token ) {
			$color = $this->parse_color( $token );
			if ( null !== $color ) {
				return $color;
			}
		}
		if ( 1 === preg_match( '/rgba?\([^)]*\)/i', $value, $m ) ) {
			return $this->parse_color( $m[0] );
		}
		return null;
	}

	/**
	 * Is this large text by the bounded evidence — font-size
	 * and font-weight as declared in page-written styles only
	 * (px/pt sizes; relative units and keyword sizes are
	 * undeterminable and fall back to the normal threshold,
	 * as do bolder/lighter weights)? Tag-name defaults (an
	 * <h2> is "bold") are UA stylesheet facts, not bounded
	 * sources, and are never used.
	 *
	 * @param DOMElement $el    Element.
	 * @param array      $rules Parsed rules.
	 * @return bool
	 */
	private function contrast_is_large_text( $el, $rules ) {
		$size = null;
		$node = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType && null === $size ) {
			$decl = $this->declared_value( $node, 'font-size', $rules );
			if ( null !== $decl ) {
				$v = strtolower( trim( $decl['value'] ) );
				if ( 'inherit' === $v ) {
					$node = $node->parentNode;
					continue;
				}
				if ( 1 === preg_match( '/^([0-9]*\.?[0-9]+)px$/', $v, $m ) ) {
					$size = (float) $m[1];
				} elseif ( 1 === preg_match( '/^([0-9]*\.?[0-9]+)pt$/', $v, $m ) ) {
					$size = (float) $m[1] * 96 / 72;
				} else {
					$size = -1; // Declared but undeterminable: normal threshold.
				}
			}
			$node = $node->parentNode;
		}
		if ( null === $size || $size < 0 ) {
			return false;
		}
		if ( $size >= 24 ) {
			return true;
		}
		if ( $size < 18.66 ) {
			return false;
		}
		$bold = false;
		$node = $el;
		while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
			$decl = $this->declared_value( $node, 'font-weight', $rules );
			if ( null !== $decl ) {
				$v = strtolower( trim( $decl['value'] ) );
				if ( 'inherit' === $v ) {
					$node = $node->parentNode;
					continue;
				}
				$bold = ( 'bold' === $v || ( is_numeric( $v ) && (float) $v >= 700 ) );
				break;
			}
			$node = $node->parentNode;
		}
		return $bold;
	}

	/**
	 * Parse a CSS colour in the bounded forms: #rgb, #rgba,
	 * #rrggbb, #rrggbbaa, rgb()/rgba() (comma syntax, numeric
	 * or percentage channels), the keyword "transparent", and
	 * the CSS named colours. Anything else returns null.
	 *
	 * @param string $value Colour value.
	 * @return array|null {r,g,b (0–255 floats), a (0–1)} or null.
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
		$named = self::named_colors();
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

	/**
	 * The CSS named colours (keyword => rrggbb), grey aliases
	 * included.
	 *
	 * @return array
	 */
	private static function named_colors() {
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
	 * Composite a translucent colour over an opaque backdrop
	 * (standard source-over).
	 *
	 * @param array $fg Foreground {r,g,b,a}.
	 * @param array $bg Backdrop {r,g,b,a} (a treated as opaque base).
	 * @return array {r,g,b,a}
	 */
	private function color_composite_over( $fg, $bg ) {
		$a = $fg['a'] + $bg['a'] * ( 1 - $fg['a'] );
		if ( $a <= 0 ) {
			return array( 'r' => 0.0, 'g' => 0.0, 'b' => 0.0, 'a' => 0.0 );
		}
		return array(
			'r' => ( $fg['r'] * $fg['a'] + $bg['r'] * $bg['a'] * ( 1 - $fg['a'] ) ) / $a,
			'g' => ( $fg['g'] * $fg['a'] + $bg['g'] * $bg['a'] * ( 1 - $fg['a'] ) ) / $a,
			'b' => ( $fg['b'] * $fg['a'] + $bg['b'] * $bg['a'] * ( 1 - $fg['a'] ) ) / $a,
			'a' => $a,
		);
	}

	/**
	 * The WCAG contrast ratio between two opaque colours.
	 *
	 * @param array $c1 Colour {r,g,b}.
	 * @param array $c2 Colour {r,g,b}.
	 * @return float Ratio 1.0–21.0.
	 */
	private function contrast_ratio( $c1, $c2 ) {
		$l1 = $this->relative_luminance( $c1 );
		$l2 = $this->relative_luminance( $c2 );
		if ( $l1 < $l2 ) {
			$tmp = $l1;
			$l1  = $l2;
			$l2  = $tmp;
		}
		return ( $l1 + 0.05 ) / ( $l2 + 0.05 );
	}

	/**
	 * sRGB relative luminance (WCAG definition).
	 *
	 * @param array $c Colour {r,g,b} (0–255).
	 * @return float
	 */
	private function relative_luminance( $c ) {
		$f = function ( $channel ) {
			$s = $channel / 255;
			return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $f( $c['r'] ) + 0.7152 * $f( $c['g'] ) + 0.0722 * $f( $c['b'] );
	}

	/**
	 * A colour as a short hex label for finding copy.
	 *
	 * @param array $c Colour {r,g,b}.
	 * @return string e.g. "#777777".
	 */
	private function color_label( $c ) {
		return sprintf( '#%02x%02x%02x', (int) round( $c['r'] ), (int) round( $c['g'] ), (int) round( $c['b'] ) );
	}

	/**
	 * Raw readability counts for one page's HTML (Tier 2,
	 * T2-05): words, sentences and syllables of the page's main
	 * readable text — script, style, noscript, navigation and
	 * template content excluded, since menus and code are not
	 * the reading matter a visitor faces. Returns null when
	 * the page carries too little text to judge (under 30
	 * words), so short pages contribute nothing rather than a
	 * junk signal. The settings layer aggregates counts across
	 * a run and computes the Flesch-Kincaid grade from the
	 * totals; nothing here is a finding.
	 *
	 * @param string $html Rendered HTML.
	 * @return array|null { words, sentences, syllables } or null.
	 */
	public function page_text_counts( $html ) {
		$dom = $this->parse( $html );
		if ( ! $dom ) {
			return null;
		}
		$templates = array();
		foreach ( $dom->getElementsByTagName( 'template' ) as $tpl ) {
			$templates[] = $tpl;
		}
		foreach ( $templates as $tpl ) {
			if ( $tpl->parentNode ) {
				$tpl->parentNode->removeChild( $tpl );
			}
		}
		$bodies = $dom->getElementsByTagName( 'body' );
		if ( 0 === $bodies->length ) {
			return null;
		}
		$skip = array( 'script', 'style', 'noscript', 'nav', 'svg', 'iframe', 'select', 'textarea', 'button' );
		$parts = array();
		// The walk carries the effective visibility state,
		// like visible_text(): text nobody can see is not the
		// page's reading matter (loop round 1, H-10) — but a
		// descendant that re-declares visibility:visible
		// inside a hidden wrapper IS readable and counts
		// (round 3, R3-01; the old per-node prune discarded
		// its whole subtree). Ancestors above the body are
		// out of frame: the body is the reading surface.
		$walk = function ( $node, $vis ) use ( &$walk, &$parts, $skip ) {
			foreach ( $node->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					if ( 'hidden' !== $vis && 'collapse' !== $vis ) {
						$parts[] = $child->nodeValue;
					}
					continue;
				}
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				if ( in_array( strtolower( $child->tagName ), $skip, true ) ) {
					continue;
				}
				if ( $this->node_terminally_hidden( $child ) ) {
					continue;
				}
				$own = $this->node_own_visibility( $child );
				$walk( $child, null !== $own ? $own : $vis );
			}
		};
		$walk( $bodies->item( 0 ), null );
		$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', implode( ' ', $parts ) ) );
		if ( '' === $text ) {
			return null;
		}
		$tokens = preg_split( '/\s+/', $text );
		$words  = array();
		foreach ( $tokens as $token ) {
			if ( 1 === preg_match( '/[\p{L}\p{N}]/u', $token ) ) {
				$words[] = $token;
			}
		}
		if ( count( $words ) < 30 ) {
			return null;
		}
		$sentences = 0;
		foreach ( preg_split( '/[.!?]+/', $text ) as $segment ) {
			if ( 1 === preg_match( '/[\p{L}\p{N}]/u', $segment ) ) {
				$sentences++;
			}
		}
		if ( 0 === $sentences ) {
			$sentences = 1;
		}
		$syllables = 0;
		foreach ( $words as $word ) {
			$syllables += $this->count_syllables( $word );
		}
		return array( 'words' => count( $words ), 'sentences' => $sentences, 'syllables' => $syllables );
	}

	/**
	 * Syllable estimate for one word: vowel-group counting
	 * with the silent-e adjustment (a trailing e adds no
	 * syllable, except after -le where it usually does —
	 * "table", "simple"). Numbers and letterless tokens count
	 * as one. A standard heuristic — good enough for a grade
	 * band, never presented as more.
	 *
	 * @param string $word Word.
	 * @return int
	 */
	private function count_syllables( $word ) {
		$w = strtolower( preg_replace( '/[^a-z]/', '', strtolower( $word ) ) );
		if ( '' === $w ) {
			return 1;
		}
		if ( strlen( $w ) <= 3 ) {
			return 1;
		}
		$count = preg_match_all( '/[aeiouy]+/', $w, $m );
		if ( substr( $w, -1 ) === 'e' && substr( $w, -2 ) !== 'le' && $count > 1 ) {
			$count--;
		}
		return max( 1, (int) $count );
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
	 * Does a host belong to a domain — the host IS the domain
	 * or a subdomain of it? Look-alike hosts that merely
	 * contain the domain as a substring ("notyoutube.example
	 * .net", "youtube.com.evil.example") never match.
	 *
	 * @param string $host   Host (lowercased by caller or here).
	 * @param string $domain Domain, e.g. 'youtube.com'.
	 * @return bool
	 */
	private function host_matches_domain( $host, $domain ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}
		if ( $host === $domain ) {
			return true;
		}
		$suffix = '.' . $domain;
		return strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Name a known embed provider from its host. Matching is
	 * by domain (see host_matches_domain), the same rule the
	 * media check uses — a look-alike host is never credited
	 * to a provider it merely names (loop round 1, H-14).
	 */
	private function name_provider( $host ) {
		$host = strtolower( $host );
		$map  = array(
			'donorbox.org'  => 'Donorbox',
			'classy.org'    => 'Classy',
			'stripe.com'    => 'Stripe',
			'paypal.com'    => 'PayPal',
			'youtube.com'   => 'YouTube',
			'youtu.be'      => 'YouTube',
			'vimeo.com'     => 'Vimeo',
			'google.com'    => 'Google',
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'twitter.com'   => 'X (Twitter)',
			'x.com'         => 'X (Twitter)',
			'tiktok.com'    => 'TikTok',
		);
		foreach ( $map as $domain => $name ) {
			if ( $this->host_matches_domain( $host, $domain ) ) {
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
