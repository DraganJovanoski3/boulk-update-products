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
	 * Fields always read from CSV for product lookup (not gated by selection).
	 *
	 * @var string[]
	 */
	public static $lookup_fields = array( 'sku' );

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
					'title'                => __( 'Title', 'boulk-update-products' ),
					'description'          => __( 'Description', 'boulk-update-products' ),
					'short_description'    => __( 'Short description', 'boulk-update-products' ),
					'slug'                 => __( 'Slug / permalink', 'boulk-update-products' ),
					'automann_part_number' => __( 'Automann part number (meta)', 'boulk-update-products' ),
				),
			),
			'pricing' => array(
				'label'  => __( 'Pricing', 'boulk-update-products' ),
				'fields' => array(
					'regular_price'   => __( 'Updated price', 'boulk-update-products' ),
					'sale_price'      => __( 'Dist. net price (sale price)', 'boulk-update-products' ),
					'dist_price_list' => __( 'Dist. price list (saved as meta)', 'boulk-update-products' ),
					'product_tax'     => __( 'Tax class', 'boulk-update-products' ),
				),
			),
			'inventory' => array(
				'label'  => __( 'Inventory & shipping', 'boulk-update-products' ),
				'fields' => array(
					'stock_status' => __( 'Stock status', 'boulk-update-products' ),
					'weight'       => __( 'Weight', 'boulk-update-products' ),
					'width'        => __( 'Width', 'boulk-update-products' ),
					'length'       => __( 'Length', 'boulk-update-products' ),
					'height'       => __( 'Height', 'boulk-update-products' ),
					'units'        => __( 'Units (meta)', 'boulk-update-products' ),
					'pkg_qty'      => __( 'Pkg/Qty (meta)', 'boulk-update-products' ),
				),
			),
			'catalog' => array(
				'label'  => __( 'Catalog', 'boulk-update-products' ),
				'fields' => array(
					'categories'         => __( 'Master category', 'boulk-update-products' ),
					'brands'             => __( 'Brands', 'boulk-update-products' ),
					'product_group_id'   => __( 'Product group ID (meta)', 'boulk-update-products' ),
					'product_group_desc' => __( 'Product group description (meta)', 'boulk-update-products' ),
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
				'all'      => array(
					'label'  => __( 'All fields', 'boulk-update-products' ),
					'fields' => self::get_all_field_keys(),
				),
				'automann' => array(
					'label'  => __( 'Automann feed (typical columns)', 'boulk-update-products' ),
					'fields' => array(
						'description',
						'regular_price',
						'sale_price',
						'dist_price_list',
						'stock_status',
						'weight',
						'width',
						'length',
						'height',
						'units',
						'pkg_qty',
						'categories',
						'brands',
						'product_group_id',
						'product_group_desc',
						'automann_part_number',
					),
				),
				'prices'   => array(
					'label'  => __( 'Prices & stock only', 'boulk-update-products' ),
					'fields' => array( 'regular_price', 'sale_price', 'stock_status' ),
				),
				'seo'      => array(
					'label'  => __( 'SEO only', 'boulk-update-products' ),
					'fields' => array( 'seo_title', 'meta_description', 'focus_keyphrase', 'meta_keywords' ),
				),
				'content'  => array(
					'label'  => __( 'Content only', 'boulk-update-products' ),
					'fields' => array( 'title', 'short_description', 'description', 'slug' ),
				),
			)
		);
	}

	/**
	 * Optional fields when creating new products (Price & Create tab).
	 *
	 * @return array<string, string>
	 */
	public static function get_price_create_create_fields() {
		return apply_filters(
			'boulk_up_price_create_create_fields',
			array(
				'title'              => __( 'Title (Description column)', 'boulk-update-products' ),
				'regular_price'      => __( 'Updated Price', 'boulk-update-products' ),
				'sale_price'         => __( 'Dist. Net Price (sale price)', 'boulk-update-products' ),
				'stock_status'       => __( 'Stock status', 'boulk-update-products' ),
				'dist_price_list'    => __( 'Dist. Price List (meta)', 'boulk-update-products' ),
				'weight'             => __( 'Weight', 'boulk-update-products' ),
				'width'              => __( 'Width', 'boulk-update-products' ),
				'length'             => __( 'Length', 'boulk-update-products' ),
				'height'             => __( 'Height', 'boulk-update-products' ),
				'units'              => __( 'Units (meta)', 'boulk-update-products' ),
				'pkg_qty'            => __( 'Pkg/Qty (meta)', 'boulk-update-products' ),
				'categories'         => __( 'Master Category', 'boulk-update-products' ),
				'brands'             => __( 'Brands', 'boulk-update-products' ),
				'product_group_id'   => __( 'Product group ID (meta)', 'boulk-update-products' ),
				'product_group_desc' => __( 'Product group description (meta)', 'boulk-update-products' ),
			)
		);
	}

	/**
	 * Default checked fields for new products on Price & Create tab.
	 *
	 * @return string[]
	 */
	public static function get_price_create_create_defaults() {
		return array( 'title', 'regular_price', 'stock_status' );
	}

	/**
	 * Sanitize Price & Create “new product” field selection (title + price always kept).
	 *
	 * @param string[] $selected Raw field keys from POST.
	 * @return string[]
	 */
	public static function sanitize_price_create_create_fields( $selected ) {
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}

		$allowed = array_keys( self::get_price_create_create_fields() );
		$clean   = array();

		foreach ( $selected as $field ) {
			$field = sanitize_key( $field );
			if ( in_array( $field, $allowed, true ) ) {
				$clean[] = $field;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		foreach ( array( 'title', 'regular_price' ) as $required ) {
			if ( ! in_array( $required, $clean, true ) ) {
				$clean[] = $required;
			}
		}

		return $clean;
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
	 * @param string        $field   Field key.
	 * @param string[]|null $enabled Enabled fields, or null for all.
	 * @return bool
	 */
	public static function is_enabled( $field, $enabled ) {
		if ( null === $enabled || array() === $enabled ) {
			return true;
		}
		return in_array( $field, $enabled, true );
	}

	/**
	 * Strip disabled fields from a CSV row (lookup fields always kept).
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
		foreach ( self::$lookup_fields as $field ) {
			if ( isset( $row[ $field ] ) && '' !== trim( $row[ $field ] ) ) {
				$filtered[ $field ] = $row[ $field ];
			}
		}

		foreach ( $enabled as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$filtered[ $field ] = $row[ $field ];
			}
		}

		return $filtered;
	}

	/**
	 * Resolve WooCommerce SKU from the sku column (and feed-specific fallbacks).
	 *
	 * @param array<string, string> $row  Row data.
	 * @param string                $feed CSV feed profile.
	 * @return string
	 */
	public static function resolve_sku( $row, $feed = 'default' ) {
		if ( isset( $row['sku'] ) && '' !== trim( (string) $row['sku'] ) ) {
			return trim( (string) $row['sku'] );
		}

		if ( 'automann_price' === $feed && ! empty( $row['automann_part_number'] ) ) {
			return trim( (string) $row['automann_part_number'] );
		}

		return '';
	}
}
