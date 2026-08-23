<?php
/**
 * Per-language attachment metadata.
 *
 * Attachments remain shared between languages, while their accessible and
 * editorial text can be translated independently.
 */
defined( 'ABSPATH' ) || exit;

final class SML_Media {
	private static $instance;

	const META_PREFIX = '_sml_media_';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 20, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_attachment_fields' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 20, 3 );
		add_filter( 'wp_get_attachment_caption', array( $this, 'attachment_caption' ), 20, 2 );
		add_filter( 'the_title', array( $this, 'attachment_title' ), 20, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_attachment_for_js' ), 20, 3 );
		add_shortcode( 'sml_media_text', array( $this, 'media_text_shortcode' ) );
	}

	public static function meta_key( $field, $language ) {
		return self::META_PREFIX . sanitize_key( $field ) . '_' . sanitize_key( $language );
	}

	private function fields() {
		return array(
			'alt'         => __( 'Alt text', 'smart-multilingual' ),
			'title'       => __( 'Image title', 'smart-multilingual' ),
			'caption'     => __( 'Caption', 'smart-multilingual' ),
			'description' => __( 'Description', 'smart-multilingual' ),
		);
	}

	public function attachment_fields( $form_fields, $post ) {
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $form_fields;
		}

		$languages = SML_Languages::enabled();
		if ( empty( $languages ) ) {
			return $form_fields;
		}

		$completed = 0;
		$panels    = '';
		$tabs      = '';
		$first     = true;

		$default_language = SML_Languages::default_code();
		$default_config   = SML_Languages::get( $default_language );

		foreach ( $languages as $language ) {
			$cfg       = SML_Languages::get( $language );
			$values    = array();
			$non_empty = 0;

			foreach ( array_keys( $this->fields() ) as $field ) {
				$values[ $field ] = $this->raw_value( $post->ID, $field, $language );
				if ( '' !== trim( wp_strip_all_tags( $values[ $field ] ) ) ) {
					$non_empty++;
				}
			}

			$is_complete = 4 === $non_empty;
			if ( $is_complete ) {
				$completed++;
			}

			$status = $is_complete ? __( 'Complete', 'smart-multilingual' ) : ( $non_empty ? __( 'Partial', 'smart-multilingual' ) : __( 'Empty', 'smart-multilingual' ) );
			$tabs  .= sprintf(
				'<button type="button" class="sml-media-tab%1$s" data-sml-media-language="%2$s" aria-selected="%3$s"><span class="sml-media-tab-name">%4$s</span><span class="sml-media-tab-status %5$s">%6$s</span></button>',
				$first ? ' is-active' : '',
				esc_attr( $language ),
				$first ? 'true' : 'false',
				esc_html( $cfg['native'] ),
				$is_complete ? 'is-complete' : ( $non_empty ? 'is-partial' : 'is-empty' ),
				esc_html( $status )
			);

			$direction = ! empty( $cfg['direction'] ) && 'rtl' === $cfg['direction'] ? 'rtl' : 'ltr';
			$panels   .= '<section class="sml-media-panel' . ( $first ? ' is-active' : '' ) . '" data-sml-media-panel="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '">';
			$panels   .= '<div class="sml-media-panel-head"><div><strong>' . esc_html( $cfg['native'] ) . '</strong><p>' . esc_html( sprintf( __( 'Media information for this language. Empty fields use the %s value as fallback.', 'smart-multilingual' ), $default_config['native'] ) ) . '</p></div>';
			if ( $default_language !== $language ) {
				$panels .= '<button type="button" class="button sml-copy-media-source" data-source-language="' . esc_attr( $default_language ) . '" data-target-language="' . esc_attr( $language ) . '">' . esc_html( sprintf( __( 'Copy from %s', 'smart-multilingual' ), $default_config['native'] ) ) . '</button>';
			}
			$panels .= '</div>';

			foreach ( $this->fields() as $field => $label ) {
				$name        = 'attachments[' . (int) $post->ID . '][sml_media_' . $field . '_' . $language . ']';
				$id          = 'sml-media-' . $post->ID . '-' . $field . '-' . $language;
				$placeholder = $this->field_placeholder( $field, $cfg['native'] );
				$panels     .= '<div class="sml-media-field">';
				$panels     .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
				if ( in_array( $field, array( 'caption', 'description' ), true ) ) {
					$rows    = 'description' === $field ? 5 : 3;
					$panels .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . $rows . '" placeholder="' . esc_attr( $placeholder ) . '" data-sml-media-field="' . esc_attr( $field ) . '" data-sml-media-lang="' . esc_attr( $language ) . '">' . esc_textarea( $values[ $field ] ) . '</textarea>';
				} else {
					$panels .= '<input id="' . esc_attr( $id ) . '" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $values[ $field ] ) . '" placeholder="' . esc_attr( $placeholder ) . '" data-sml-media-field="' . esc_attr( $field ) . '" data-sml-media-lang="' . esc_attr( $language ) . '">';
				}
				$panels .= '</div>';
			}
			$panels .= '</section>';
			$first   = false;
		}

		$html  = '<div class="sml-media-translations" data-attachment-id="' . (int) $post->ID . '">';
		$html .= '<div class="sml-media-header"><div><strong>' . esc_html__( 'Media translations', 'smart-multilingual' ) . '</strong><p>' . esc_html__( 'Edit the text used for this image in each active language.', 'smart-multilingual' ) . '</p></div><span class="sml-media-progress"><b>' . (int) $completed . '</b> / <span>' . count( $languages ) . '</span> ' . esc_html__( 'completed', 'smart-multilingual' ) . '</span></div>';
		$html .= '<div class="sml-media-tabs" role="tablist">' . $tabs . '</div>';
		$html .= '<div class="sml-media-panels">' . $panels . '</div>';
		$html .= '</div>';

		$form_fields['sml_media_translations'] = array(
			'label' => '',
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	private function field_placeholder( $field, $language_name ) {
		$placeholders = array(
			'alt'         => __( 'Describe the image for visitors and search engines…', 'smart-multilingual' ),
			'title'       => __( 'Enter the image title…', 'smart-multilingual' ),
			'caption'     => __( 'Enter the visible image caption…', 'smart-multilingual' ),
			'description' => __( 'Enter a longer media description…', 'smart-multilingual' ),
		);
		return isset( $placeholders[ $field ] ) ? $placeholders[ $field ] : '';
	}

	public function save_attachment_fields( $post, $attachment ) {
		$attachment_id = isset( $post['ID'] ) ? absint( $post['ID'] ) : 0;
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return $post;
		}

		foreach ( SML_Languages::enabled() as $language ) {
			foreach ( array_keys( $this->fields() ) as $field ) {
				$request_key = 'sml_media_' . $field . '_' . $language;
				if ( ! array_key_exists( $request_key, $attachment ) ) {
					continue;
				}

				$value = wp_unslash( $attachment[ $request_key ] );
				$value = in_array( $field, array( 'caption', 'description' ), true )
					? wp_kses_post( $value )
					: sanitize_text_field( $value );

				if ( SML_Languages::is_default( $language ) ) {
					switch ( $field ) {
						case 'alt':
							update_post_meta( $attachment_id, '_wp_attachment_image_alt', $value );
							break;
						case 'title':
							$post['post_title'] = $value;
							break;
						case 'caption':
							$post['post_excerpt'] = $value;
							break;
						case 'description':
							$post['post_content'] = $value;
							break;
					}
					continue;
				}

				$meta_key = self::meta_key( $field, $language );
				if ( '' === trim( wp_strip_all_tags( $value ) ) ) {
					delete_post_meta( $attachment_id, $meta_key );
				} else {
					update_post_meta( $attachment_id, $meta_key, $value );
				}
			}
		}

		return $post;
	}

	private function raw_value( $attachment_id, $field, $language ) {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return '';
		}

		if ( SML_Languages::is_default( $language ) ) {
			switch ( $field ) {
				case 'alt':
					return (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				case 'title':
					return (string) $post->post_title;
				case 'caption':
					return (string) $post->post_excerpt;
				case 'description':
					return (string) $post->post_content;
			}
		}

		return (string) get_post_meta( $attachment_id, self::meta_key( $field, $language ), true );
	}

	public function translated_value( $attachment_id, $field, $language = '' ) {
		$language = $language ? sanitize_key( $language ) : SML_Plugin::instance()->current_language();
		$value    = $this->raw_value( $attachment_id, $field, $language );

		$default_language = SML_Languages::default_code();
		if ( '' === trim( wp_strip_all_tags( $value ) ) && $default_language !== $language ) {
			$value = $this->raw_value( $attachment_id, $field, $default_language );
		}

		return $value;
	}

	public function image_attributes( $attr, $attachment, $size ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $attr;
		}

		$attachment_id = is_object( $attachment ) ? (int) $attachment->ID : (int) $attachment;
		if ( ! $attachment_id ) {
			return $attr;
		}

		$alt   = $this->translated_value( $attachment_id, 'alt' );
		$title = $this->translated_value( $attachment_id, 'title' );

		$attr['alt'] = wp_strip_all_tags( $alt );
		if ( '' !== trim( $title ) ) {
			$attr['title'] = wp_strip_all_tags( $title );
		}

		return $attr;
	}

	public function attachment_caption( $caption, $attachment_id ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $caption;
		}
		return $this->translated_value( (int) $attachment_id, 'caption' );
	}

	public function attachment_title( $title, $post_id = 0 ) {
		if ( is_admin() || ! $post_id || 'attachment' !== get_post_type( $post_id ) ) {
			return $title;
		}
		return $this->translated_value( (int) $post_id, 'title' );
	}

	public function prepare_attachment_for_js( $response, $attachment, $meta ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $response;
		}

		$attachment_id = is_object( $attachment ) ? (int) $attachment->ID : 0;
		if ( ! $attachment_id ) {
			return $response;
		}

		$response['alt']         = wp_strip_all_tags( $this->translated_value( $attachment_id, 'alt' ) );
		$response['title']       = wp_strip_all_tags( $this->translated_value( $attachment_id, 'title' ) );
		$response['caption']     = $this->translated_value( $attachment_id, 'caption' );
		$response['description'] = $this->translated_value( $attachment_id, 'description' );
		return $response;
	}

	public function media_text_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'field'    => 'alt',
				'language' => '',
			),
			$atts,
			'sml_media_text'
		);

		$id    = absint( $atts['id'] );
		$field = sanitize_key( $atts['field'] );
		if ( ! $id || ! array_key_exists( $field, $this->fields() ) ) {
			return '';
		}

		return wp_kses_post( $this->translated_value( $id, $field, $atts['language'] ) );
	}
}
