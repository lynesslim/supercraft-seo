<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Elementor_Parser
 * 
 * Parses Elementor page builder JSON payloads stored in `_elementor_data` post meta.
 * Extracts clean, structured copy, headings, body text, and image metadata for SEO processing.
 * Supports multibyte / CJK (Chinese, Japanese, Korean) word counting and H1 heading promotion.
 */
class Supercraft_SEO_Elementor_Parser {

	/**
	 * Parse post content and extract clean structured text for a given post ID.
	 *
	 * @param int $post_id Post ID to inspect.
	 * @return array Structured content containing headings, paragraphs, images, raw text, and word count.
	 */
	public function get_page_content( $post_id ) {
		$is_elementor = get_post_meta( $post_id, '_elementor_edit_mode', true );
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		$parsed = array(
			'is_elementor' => ! empty( $is_elementor ) && 'builder' === $is_elementor,
			'title'        => get_the_title( $post_id ),
			'post_type'    => get_post_type( $post_id ),
			'headings'     => array(
				'h1' => array(),
				'h2' => array(),
				'h3' => array(),
				'h4' => array(),
			),
			'paragraphs'   => array(),
			'images'       => array(),
			'raw_text'     => '',
			'word_count'   => 0,
		);

		if ( $parsed['is_elementor'] && ! empty( $elementor_data ) ) {
			$elements = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;

			if ( is_array( $elements ) ) {
				$this->parse_elements_recursive( $elements, $parsed );
			}
		}

		// Fallback to post_content if not built with Elementor or if Elementor data is empty
		if ( empty( $parsed['paragraphs'] ) && empty( $parsed['headings']['h1'] ) && empty( $parsed['headings']['h2'] ) ) {
			$raw_content = get_post_field( 'post_content', $post_id );
			if ( ! empty( $raw_content ) ) {
				$this->parse_raw_html_fallback( $raw_content, $parsed );
			}
		}

		// Aggregate raw text representation
		$text_parts = array();
		$text_parts[] = $parsed['title'];

		foreach ( $parsed['headings'] as $tag => $items ) {
			foreach ( $items as $heading ) {
				$text_parts[] = $heading['text'];
			}
		}

		foreach ( $parsed['paragraphs'] as $paragraph ) {
			$text_parts[] = $paragraph;
		}

		$parsed['raw_text']   = implode( "\n\n", array_filter( array_map( 'trim', $text_parts ) ) );
		$parsed['word_count'] = $this->count_words( $parsed['raw_text'] );

		return $parsed;
	}

	/**
	 * Promote the first heading widget on an Elementor page (or raw post_content) to an H1 tag.
	 *
	 * @param int $post_id Post ID to modify.
	 * @return bool True if heading was promoted to H1, false otherwise.
	 */
	public function promote_first_heading_to_h1( $post_id ) {
		$is_elementor = get_post_meta( $post_id, '_elementor_edit_mode', true );
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( 'builder' === $is_elementor && ! empty( $elementor_data ) ) {
			$elements = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;

			if ( is_array( $elements ) ) {
				$promoted = false;
				$this->promote_heading_in_elements( $elements, $promoted );

				if ( $promoted ) {
					// Save updated Elementor JSON structure
					update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
					
					// Clear Elementor CSS cache if class exists
					if ( class_exists( '\Elementor\Plugin' ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_stack();
					}
					return true;
				}
			}
		}

		// Fallback for standard WordPress posts/pages: replace top <h2> or <h3> in post_content with <h1>
		$raw_content = get_post_field( 'post_content', $post_id );
		if ( ! empty( $raw_content ) ) {
			if ( preg_match( '/<h[2-4]\b([^>]*)>(.*?)<\/h[2-4]>/is', $raw_content ) ) {
				$new_content = preg_replace( '/<h[2-4]\b([^>]*)>(.*?)<\/h[2-4]>/is', '<h1$1>$2</h1>', $raw_content, 1 );
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				) );
				return true;
			}
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
		foreach ( $elements as &$element ) {
			if ( $promoted ) {
				return;
			}

			if ( isset( $element['widgetType'] ) && 'heading' === $element['widgetType'] ) {
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
				if ( ! empty( $settings['image']['id'] ) || ! empty( $settings['image']['url'] ) ) {
					$img_id  = ! empty( $settings['image']['id'] ) ? absint( $settings['image']['id'] ) : 0;
					$img_url = ! empty( $settings['image']['url'] ) ? esc_url_raw( $settings['image']['url'] ) : '';
					$alt     = '';

					if ( $img_id > 0 ) {
						$alt = get_post_meta( $img_id, '_wp_attachment_image_alt', true );
					}

					$parsed['images'][] = array(
						'id'  => $img_id,
						'url' => $img_url,
						'alt' => $alt,
					);
				}
				break;

			case 'icon-box':
			case 'image-box':
			case 'info-box':
				if ( ! empty( $settings['title_text'] ) ) {
					$parsed['headings']['h3'][] = array(
						'text' => wp_strip_all_tags( $settings['title_text'] ),
						'tag'  => 'h3',
					);
				}
				if ( ! empty( $settings['description_text'] ) ) {
					$parsed['paragraphs'][] = wp_strip_all_tags( $settings['description_text'] );
				}
				break;

			case 'call-to-action':
				if ( ! empty( $settings['title'] ) ) {
					$parsed['headings']['h2'][] = array(
						'text' => wp_strip_all_tags( $settings['title'] ),
						'tag'  => 'h2',
					);
				}
				if ( ! empty( $settings['description'] ) ) {
					$parsed['paragraphs'][] = wp_strip_all_tags( $settings['description'] );
				}
				break;

			case 'icon-list':
				if ( ! empty( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
					foreach ( $settings['icon_list'] as $item ) {
						if ( ! empty( $item['text'] ) ) {
							$parsed['paragraphs'][] = wp_strip_all_tags( $item['text'] );
						}
					}
				}
				break;

			case 'accordion':
			case 'toggle':
			case 'tabs':
				if ( ! empty( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					foreach ( $settings['tabs'] as $tab ) {
						if ( ! empty( $tab['tab_title'] ) ) {
							$parsed['headings']['h3'][] = array(
								'text' => wp_strip_all_tags( $tab['tab_title'] ),
								'tag'  => 'h3',
							);
						}
						if ( ! empty( $tab['tab_content'] ) ) {
							$parsed['paragraphs'][] = wp_strip_all_tags( $tab['tab_content'] );
						}
					}
				}
				break;

			case 'testimonial':
				if ( ! empty( $settings['testimonial_content'] ) ) {
					$parsed['paragraphs'][] = wp_strip_all_tags( $settings['testimonial_content'] );
				}
				break;

			case 'button':
				if ( ! empty( $settings['text'] ) ) {
					$parsed['paragraphs'][] = wp_strip_all_tags( $settings['text'] );
				}
				break;

			default:
				if ( ! empty( $settings['title'] ) && is_string( $settings['title'] ) ) {
					$parsed['headings']['h3'][] = array(
						'text' => wp_strip_all_tags( $settings['title'] ),
						'tag'  => 'h3',
					);
				}
				if ( ! empty( $settings['editor'] ) && is_string( $settings['editor'] ) ) {
					$parsed['paragraphs'][] = wp_strip_all_tags( $settings['editor'] );
				}
				break;
		}
	}

	/**
	 * Fallback HTML parser for non-Elementor posts.
	 *
	 * @param string $html Raw HTML content.
	 * @param array  &$parsed Target parsed result reference.
	 */
	private function parse_raw_html_fallback( $html, &$parsed ) {
		if ( preg_match_all( '/<h([1-4])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag_num = 'h' . $match[1];
				$text    = wp_strip_all_tags( $match[2] );
				if ( ! empty( $text ) ) {
					$parsed['headings'][ $tag_num ][] = array(
						'text' => $text,
						'tag'  => $tag_num,
					);
				}
			}
		}

		if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $p_text ) {
				$clean = wp_strip_all_tags( $p_text );
				if ( ! empty( $clean ) ) {
					$parsed['paragraphs'][] = $clean;
				}
			}
		}

		if ( preg_match_all( '/<img\b[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $img_matches, PREG_SET_ORDER ) ) {
			foreach ( $img_matches as $img ) {
				$url = esc_url_raw( $img[1] );
				$alt = '';
				if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $img[0], $alt_match ) ) {
					$alt = sanitize_text_field( $alt_match[1] );
				}
				$parsed['images'][] = array(
					'id'  => 0,
					'url' => $url,
					'alt' => $alt,
				);
			}
		}
	}
}
