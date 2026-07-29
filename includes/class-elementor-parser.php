<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Elementor_Parser
 * 
 * Extracts headings, paragraph text, word counts, and missing image ALTs from Elementor JSON
 * and standard WordPress post_content. Provides 1-click H1 promotion engine.
 */
class Supercraft_SEO_Elementor_Parser {

	/**
	 * Main controller reference
	 * 
	 * @var Supercraft_SEO
	 */
	private $main;

	/**
	 * Constructor
	 *
	 * @param Supercraft_SEO|null $main Main plugin instance (optional).
	 */
	public function __construct( $main = null ) {
		$this->main = $main;
	}

	/**
	 * Parse page content for a given post ID (supports both Elementor and Gutenberg/Classic).
	 *
	 * @param int $post_id Post ID.
	 * @return array Structured parsed page data payload.
	 */
	public function get_page_content( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return $this->get_empty_page_data();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->get_empty_page_data();
		}

		$is_elementor = get_post_meta( $post_id, '_elementor_edit_mode', true );
		$is_builder   = ( 'builder' === $is_elementor );

		$parsed = array(
			'post_id'      => $post_id,
			'title'        => get_the_title( $post_id ),
			'permalink'    => get_permalink( $post_id ),
			'is_elementor' => $is_builder,
			'headings'     => array(
				'h1' => array(),
				'h2' => array(),
				'h3' => array(),
				'h4' => array(),
				'h5' => array(),
				'h6' => array(),
			),
			'paragraphs'   => array(),
			'word_count'   => 0,
			'images'       => array(),
		);

		if ( $is_builder ) {
			$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
			if ( ! empty( $elementor_data ) ) {
				$elements = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
				if ( is_array( $elements ) ) {
					$this->parse_elements_recursive( $elements, $parsed );
				}
			}
		}

		// Always fallback / combine with post_content HTML parsing
		$this->parse_raw_html_content( $post->post_content, $parsed );

		// Calculate total word count
		$all_text = implode( ' ', array_merge(
			array( $parsed['title'] ),
			$this->flatten_headings( $parsed['headings'] ),
			$parsed['paragraphs']
		) );
		$parsed['word_count'] = $this->count_words( $all_text );

		return $parsed;
	}

	/**
	 * Promote the first non-H1 hero heading in Elementor JSON or post_content to H1.
	 * Safely wrapped in Throwable catch to prevent fatal crashes.
	 *
	 * @param int $post_id Post ID to modify.
	 * @return bool True if heading was promoted to H1, false otherwise.
	 */
	public function promote_first_heading_to_h1( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		try {
			$is_elementor   = get_post_meta( $post_id, '_elementor_edit_mode', true );
			$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

			if ( 'builder' === $is_elementor && ! empty( $elementor_data ) ) {
				$elements = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;

				if ( is_array( $elements ) && ! empty( $elements ) ) {
					$promoted = false;
					$this->promote_heading_in_elements( $elements, $promoted );

					if ( $promoted ) {
						// Save updated Elementor JSON structure
						update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
						
						// Safely clear Elementor CSS cache
						try {
							if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
								\Elementor\Plugin::$instance->files_manager->clear_stack();
							}
						} catch ( \Throwable $t ) {
							// Ignore cache clear error
						}
						return true;
					}
				}
			}

			// Fallback for standard WordPress posts/pages or Elementor pages with non-heading top widgets
			$raw_content = get_post_field( 'post_content', $post_id );
			if ( ! empty( $raw_content ) && preg_match( '/<h[2-4]\b([^>]*)>(.*?)<\/h[2-4]>/is', $raw_content ) ) {
				$new_content = preg_replace( '/<h[2-4]\b([^>]*)>(.*?)<\/h[2-4]>/is', '<h1$1>$2</h1>', $raw_content, 1 );
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				) );
				return true;
			}

			// If no heading tag exists at all, prepend post title as an H1 tag to post_content
			$title = get_the_title( $post_id );
			if ( ! empty( $title ) ) {
				$new_content = '<h1>' . esc_html( $title ) . '</h1>' . "\n" . $raw_content;
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				) );
				return true;
			}
		} catch ( \Throwable $t ) {
			// Catch any Throwable exception safely
			return false;
		}

		return false;
	}

	/**
	 * Recursively search and promote the first heading widget to H1 in Elementor elements array.
	 *
	 * @param array &$elements Elementor elements array.
	 * @param bool  &$promoted Reference flag indicating if promotion succeeded.
	 */
	private function promote_heading_in_elements( &$elements, &$promoted ) {
		$target_types = array( 'heading', 'theme-post-title', 'page-title', 'post-title', 'animated-headline', 'title-box' );

		foreach ( $elements as &$element ) {
			if ( $promoted ) {
				return;
			}

			$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
			if ( in_array( $widget_type, $target_types, true ) || ( ! empty( $widget_type ) && isset( $element['settings']['header_size'] ) ) ) {
				if ( ! isset( $element['settings'] ) ) {
					$element['settings'] = array();
				}
				$element['settings']['header_size'] = 'h1';
				$promoted = true;
				return;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->promote_heading_in_elements( $element['elements'], $promoted );
			}
		}
	}

	/**
	 * Multibyte & CJK Aware Word Count Helper.
	 *
	 * @param string $text Text to count.
	 * @return int Total word / character count.
	 */
	public function count_words( $text ) {
		$clean = wp_strip_all_tags( $text );
		if ( empty( trim( $clean ) ) ) {
			return 0;
		}

		$cjk_count   = preg_match_all( '/[\x{4e00}-\x{9fa5}\x{3040}-\x{30ff}\x{3130}-\x{318f}]/u', $clean );
		$latin_text  = preg_replace( '/[\x{4e00}-\x{9fa5}\x{3040}-\x{30ff}\x{3130}-\x{318f}]/u', ' ', $clean );
		$latin_count = str_word_count( $latin_text );

		return $cjk_count + $latin_count;
	}

	/**
	 * Recursively traverse Elementor element tree.
	 *
	 * @param array $elements Element array tree.
	 * @param array &$parsed Target parsed result array reference.
	 */
	private function parse_elements_recursive( $elements, &$parsed ) {
		foreach ( $elements as $element ) {
			if ( empty( $element['elType'] ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) ) {
				$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
				$this->extract_widget_content( $widget_type, $element['settings'], $parsed );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->parse_elements_recursive( $element['elements'], $parsed );
			}
		}
	}

	/**
	 * Extract text content and metadata from specific Elementor widgets.
	 *
	 * @param string $widget_type Elementor widget slug.
	 * @param array  $settings Widget settings array.
	 * @param array  &$parsed Target parsed result reference.
	 */
	private function extract_widget_content( $widget_type, $settings, &$parsed ) {
		switch ( $widget_type ) {
			case 'heading':
				if ( ! empty( $settings['title'] ) ) {
					$tag  = ! empty( $settings['header_size'] ) ? strtolower( $settings['header_size'] ) : 'h2';
					$text = wp_strip_all_tags( $settings['title'] );

					if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
						$tag = 'h2';
					}

					if ( isset( $parsed['headings'][ $tag ] ) ) {
						$parsed['headings'][ $tag ][] = array(
							'text' => $text,
							'tag'  => $tag,
						);
					} else {
						$parsed['headings']['h2'][] = array(
							'text' => $text,
							'tag'  => 'h2',
						);
					}
				}
				break;

			case 'text-editor':
				if ( ! empty( $settings['editor'] ) ) {
					$clean_text = wp_strip_all_tags( $settings['editor'] );
					if ( ! empty( $clean_text ) ) {
						$parsed['paragraphs'][] = $clean_text;
					}
				}
				break;

			case 'image':
				if ( ! empty( $settings['image']['url'] ) ) {
					$url = esc_url_raw( $settings['image']['url'] );
					$id  = isset( $settings['image']['id'] ) ? absint( $settings['image']['id'] ) : 0;
					$alt = '';

					if ( $id > 0 ) {
						$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
					}
					if ( empty( $alt ) && ! empty( $settings['caption'] ) ) {
						$alt = wp_strip_all_tags( $settings['caption'] );
					}

					$parsed['images'][] = array(
						'url' => $url,
						'alt' => $alt,
						'id'  => $id,
					);
				}
				break;
		}
	}

	/**
	 * Fallback HTML Regex Parser for post_content.
	 *
	 * @param string $content HTML content.
	 * @param array  &$parsed Target parsed array reference.
	 */
	private function parse_raw_html_content( $content, &$parsed ) {
		if ( empty( $content ) ) {
			return;
		}

		// Extract headings <h1-h6>
		if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag_num = $match[1];
				$tag_key = 'h' . $tag_num;
				$text    = wp_strip_all_tags( $match[2] );

				if ( ! empty( trim( $text ) ) ) {
					$parsed['headings'][ $tag_key ][] = array(
						'text' => $text,
						'tag'  => $tag_key,
					);
				}
			}
		}

		// Extract paragraphs <p>
		if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $content, $p_matches ) ) {
			foreach ( $p_matches[1] as $p_text ) {
				$clean_p = wp_strip_all_tags( $p_text );
				if ( ! empty( trim( $clean_p ) ) ) {
					$parsed['paragraphs'][] = $clean_p;
				}
			}
		}

		// Extract images <img src="..." alt="...">
		if ( preg_match_all( '/<img\b[^>]+src=["\']([^"\']+)["\'][^>]*>/is', $content, $img_matches, PREG_SET_ORDER ) ) {
			foreach ( $img_matches as $img_tag ) {
				$url = esc_url_raw( $img_tag[1] );
				$alt = '';

				if ( preg_match( '/alt=["\']([^"\']*)["\']/is', $img_tag[0], $alt_match ) ) {
					$alt = wp_strip_all_tags( $alt_match[1] );
				}

				// Check if already extracted
				$already_found = false;
				foreach ( $parsed['images'] as $existing ) {
					if ( isset( $existing['url'] ) && $existing['url'] === $url ) {
						$already_found = true;
						break;
					}
				}

				if ( ! $already_found ) {
					$parsed['images'][] = array(
						'url' => $url,
						'alt' => $alt,
						'id'  => 0,
					);
				}
			}
		}
	}

	/**
	 * Helper: Flatten all extracted heading strings into a single array.
	 *
	 * @param array $headings Headings tree.
	 * @return array Array of heading texts.
	 */
	private function flatten_headings( $headings ) {
		$flat = array();
		foreach ( $headings as $tag => $items ) {
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( ! empty( $item['text'] ) ) {
						$flat[] = $item['text'];
					}
				}
			}
		}
		return $flat;
	}

	/**
	 * Return empty default parsed structure.
	 *
	 * @return array Empty parsed payload.
	 */
	private function get_empty_page_data() {
		return array(
			'post_id'      => 0,
			'title'        => '',
			'permalink'    => '',
			'is_elementor' => false,
			'headings'     => array(
				'h1' => array(),
				'h2' => array(),
				'h3' => array(),
				'h4' => array(),
				'h5' => array(),
				'h6' => array(),
			),
			'paragraphs'   => array(),
			'word_count'   => 0,
			'images'       => array(),
		);
	}
}
