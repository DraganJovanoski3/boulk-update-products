<?php
/**
 * Yoast SEO meta updates for products.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Yoast_Updater
 */
class Boulk_UP_Yoast_Updater {

	/**
	 * Yoast meta key mapping.
	 *
	 * @var array<string, string>
	 */
	private static $meta_map = array(
		'seo_title'        => 'title',
		'meta_description' => 'metadesc',
		'focus_keyphrase'  => 'focuskw',
		'meta_keywords'    => 'metakeywords',
	);

	/**
	 * Update Yoast fields for a product.
	 *
	 * @param int                  $product_id Product post ID.
	 * @param array<string, string> $row       CSV row (canonical fields).
	 * @return string[] Warning messages.
	 */
	public function update( $product_id, $row ) {
		$warnings = array();

		foreach ( self::$meta_map as $csv_field => $yoast_key ) {
			if ( ! isset( $row[ $csv_field ] ) || '' === $row[ $csv_field ] ) {
				continue;
			}

			$value = $this->sanitize_field( $csv_field, $row[ $csv_field ] );
			$this->set_meta( $product_id, $yoast_key, $value );
		}

		$this->maybe_reindex( $product_id );

		return $warnings;
	}

	/**
	 * Set Yoast meta value.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Internal Yoast key (without prefix).
	 * @param string $value     Meta value.
	 */
	private function set_meta( $post_id, $meta_key, $value ) {
		if ( class_exists( 'WPSEO_Meta' ) && method_exists( 'WPSEO_Meta', 'set_value' ) ) {
			WPSEO_Meta::set_value( $meta_key, $post_id, $value );
			return;
		}

		update_post_meta( $post_id, '_yoast_wpseo_' . $meta_key, $value );
	}

	/**
	 * Sanitize field by type.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_field( $field, $value ) {
		switch ( $field ) {
			case 'meta_description':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Best-effort Yoast indexable rebuild.
	 *
	 * @param int $post_id Post ID.
	 */
	private function maybe_reindex( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return;
		}

		try {
			$yoast = YoastSEO();
			if ( isset( $yoast->helpers->indexable ) && method_exists( $yoast->helpers->indexable, 'build' ) ) {
				$yoast->helpers->indexable->build( $post_id );
			}
		} catch ( Exception $e ) {
			// Silently ignore reindex failures.
		}
	}
}
