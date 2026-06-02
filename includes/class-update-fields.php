<?php
/**
 * Updatable field definitions and selection helpers.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Update_Fields
 */
class Boulk_UP_Update_Fields {

	/**
	 * Field groups and labels for the admin UI.
	 *
	 * @return array<string, array{label: string, fields: array<string, string>}>
	 */
	public static function get_groups() {
		$groups = array(
			'product' => array(
				'label'  => __( 'Product', 'boulk-update-products' ),
				'fields' => array(
					'title'             => __( 'Title', 'boulk-update-products' ),
					'short_description' => __( 'Short description', 'boulk-update-products' ),
					'description'       => __( 'Full description', 'boulk-update-products' ),
					'slug'              => __( 'Slug / permalink', 'boulk-update-products' ),
				),
			),
			'pricing' => array(
				'label'  => __( 'Pricing & tax', 'boulk-update-products' ),
				'fields' => array(
					'regular_price' => __( 'Regular price', 'boulk-update-products' ),
					'sale_price'    => __( 'Sale price', 'boulk-update-products' ),
					'product_tax'   => __( 'Tax class', 'boulk-update-products' ),
				),
			),
			'seo'     => array(
				'label'  => __( 'Yoast SEO', 'boulk-update-products' ),
				'fields' => array(
					'seo_title'        => __( 'SEO title', 'boulk-update-products' ),
					'meta_description' => __( 'Meta description', 'boulk-update-products' ),
					'focus_keyphrase'  => __( 'Focus keyphrase', 'boulk-update-products' ),
					'meta_keywords'    => __( 'Meta keywords', 'boulk-update-products' ),
				),
			),
			'relations' => array(
				'label'  => __( 'Relations', 'boulk-update-products' ),
				'fields' => array(
					'categories'  => __( 'Categories', 'boulk-update-products' ),
					'cross_sells' => __( 'Cross-sells', 'boulk-update-products' ),
				),
			),
			'media'   => array(
				'label'  => __( 'Media', 'boulk-update-products' ),
				'fields' => array(
					'alt_text' => __( 'Featured image alt text', 'boulk-update-products' ),
				),
			),
		);

		return apply_filters( 'boulk_up_update_field_groups', $groups );
	}

	/**
	 * All updatable field keys.
	 *
	 * @return string[]
	 */
	public static function get_all_field_keys() {
		$keys = array();
		foreach ( self::get_groups() as $group ) {
			$keys = array_merge( $keys, array_keys( $group['fields'] ) );
		}
		return $keys;
	}

	/**
	 * Quick-select presets for the admin UI.
	 *
	 * @return array<string, array{label: string, fields: string[]}>
	 */
	public static function get_presets() {
		return apply_filters(
			'boulk_up_field_presets',
			array(
				'all'     => array(
					'label'  => __( 'All fields', 'boulk-update-products' ),
					'fields' => self::get_all_field_keys(),
				),
				'prices'  => array(
					'label'  => __( 'Prices only', 'boulk-update-products' ),
					'fields' => array( 'regular_price', 'sale_price' ),
				),
				'seo'     => array(
					'label'  => __( 'SEO only', 'boulk-update-products' ),
					'fields' => array( 'seo_title', 'meta_description', 'focus_keyphrase', 'meta_keywords' ),
				),
				'content' => array(
					'label'  => __( 'Content only', 'boulk-update-products' ),
					'fields' => array( 'title', 'short_description', 'description', 'slug' ),
				),
			)
		);
	}

	/**
	 * Sanitize submitted field selection.
	 *
	 * @param string[] $selected Raw field keys from POST.
	 * @return string[]
	 */
	public static function sanitize_selection( $selected ) {
		if ( ! is_array( $selected ) ) {
			return array();
		}

		$allowed = self::get_all_field_keys();
		$clean   = array();

		foreach ( $selected as $field ) {
			$field = sanitize_key( $field );
			if ( in_array( $field, $allowed, true ) ) {
				$clean[] = $field;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Human-readable list of selected fields for display.
	 *
	 * @param string[]|null $selected Selected field keys.
	 * @return string
	 */
	public static function format_selection_label( $selected ) {
		if ( null === $selected || array() === $selected ) {
			return __( 'All fields', 'boulk-update-products' );
		}

		$labels = array();
		foreach ( self::get_groups() as $group ) {
			foreach ( $group['fields'] as $key => $label ) {
				if ( in_array( $key, $selected, true ) ) {
					$labels[] = $label;
				}
			}
		}

		if ( empty( $labels ) ) {
			return __( 'All fields', 'boulk-update-products' );
		}

		if ( count( $labels ) <= 3 ) {
			return implode( ', ', $labels );
		}

		return sprintf(
			/* translators: 1: number of fields, 2: first few field names */
			__( '%1$d fields (%2$s…)', 'boulk-update-products' ),
			count( $labels ),
			implode( ', ', array_slice( $labels, 0, 2 ) )
		);
	}

	/**
	 * Whether a field should be applied (null selection = all fields).
	 *
	 * @param string        $field    Field key.
	 * @param string[]|null $enabled  Enabled fields, or null for all.
	 * @return bool
	 */
	public static function is_enabled( $field, $enabled ) {
		if ( null === $enabled || array() === $enabled ) {
			return true;
		}
		return in_array( $field, $enabled, true );
	}

	/**
	 * Strip disabled fields from a CSV row (sku always kept).
	 *
	 * @param array<string, string> $row     Row data.
	 * @param string[]|null         $enabled Enabled fields.
	 * @return array<string, string>
	 */
	public static function filter_row( $row, $enabled ) {
		if ( null === $enabled || array() === $enabled ) {
			return $row;
		}

		$filtered = array();
		if ( isset( $row['sku'] ) ) {
			$filtered['sku'] = $row['sku'];
		}

		foreach ( $enabled as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$filtered[ $field ] = $row[ $field ];
			}
		}

		return $filtered;
	}
}
