<?php
/**
 * WooCommerce product field updates from CSV rows.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Product_Updater
 */
class Boulk_UP_Product_Updater {

	/**
	 * Yoast updater instance.
	 *
	 * @var Boulk_UP_Yoast_Updater
	 */
	private $yoast;

	/**
	 * Enabled field keys, or null for all fields.
	 *
	 * @var string[]|null
	 */
	private $enabled_fields;

	/**
	 * Fields applied for the current row (price_create mode).
	 *
	 * @var string[]|null
	 */
	private $active_fields;

	/**
	 * Import mode: full|price_create.
	 *
	 * @var string
	 */
	private $import_mode = 'full';

	/**
	 * CSV feed profile.
	 *
	 * @var string
	 */
	private $csv_feed = 'default';

	/**
	 * Fields to apply when creating products (price_create mode).
	 *
	 * @var string[]
	 */
	private $create_fields = array();

	/**
	 * SKU => product ID cache for this batch.
	 *
	 * @var array<string, int>
	 */
	private $sku_cache = array();

	/**
	 * Constructor.
	 *
	 * @param string[]|null $enabled_fields Fields to update; null = all.
	 * @param string        $import_mode    full|price_create.
	 * @param string[]      $create_fields  Fields for new products in price_create mode.
	 * @param string        $csv_feed       CSV feed profile.
	 */
	public function __construct( $enabled_fields = null, $import_mode = 'full', $create_fields = array(), $csv_feed = 'default' ) {
		$this->enabled_fields = $enabled_fields;
		$this->import_mode    = $import_mode;
		$this->create_fields  = is_array( $create_fields ) ? $create_fields : array();
		$this->csv_feed       = $csv_feed;
		$this->active_fields  = $enabled_fields;
		$this->yoast          = $this->needs_yoast() ? new Boulk_UP_Yoast_Updater( $enabled_fields ) : null;
	}

	/**
	 * Whether Yoast updates are needed for this import.
	 *
	 * @return bool
	 */
	private function needs_yoast() {
		if ( 'price_create' === $this->import_mode ) {
			return false;
		}
		if ( null === $this->enabled_fields ) {
			return true;
		}
		$seo_fields = array( 'seo_title', 'meta_description', 'focus_keyphrase', 'meta_keywords' );
		return (bool) array_intersect( $seo_fields, $this->enabled_fields );
	}

	/**
	 * Resolve product ID by SKU with in-memory cache.
	 * Uses oldest product (lowest ID) when duplicate SKUs exist in the store.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	private function get_product_id_by_sku( $sku ) {
		if ( isset( $this->sku_cache[ $sku ] ) ) {
			return $this->sku_cache[ $sku ];
		}

		$id = $this->lookup_product_id_by_sku( $sku );
		$this->sku_cache[ $sku ] = $id;

		return $this->sku_cache[ $sku ];
	}

	/**
	 * Find product by SKU via database (oldest match wins if duplicates exist).
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	private function lookup_product_id_by_sku( $sku ) {
		global $wpdb;

		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku' AND pm.meta_value = %s
				WHERE p.post_type = 'product'
				AND p.post_status IN ({$in})",
				...array_merge( array( $sku ), $statuses )
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Remember SKU → product ID after create or resolve (prevents duplicate creates in same import).
	 *
	 * @param string $sku        SKU.
	 * @param int    $product_id Product ID.
	 */
	private function remember_sku( $sku, $product_id ) {
		$this->sku_cache[ $sku ] = (int) $product_id;
	}

	/**
	 * Process a single CSV row.
	 *
	 * @param array<string, string> $row        Mapped row data.
	 * @param int                   $row_number Display row number for logs.
	 * @param bool                  $dry_run    If true, no DB writes.
	 * @return array{status: string, message: string, product_id?: int}
	 */
	public function process_row( $row, $row_number, $dry_run = false ) {
		$sku = Boulk_UP_Update_Fields::resolve_sku( $row, $this->csv_feed );

		if ( '' === $sku ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Missing SKU in CSV row — product was not created or updated.', 'boulk-update-products' ),
			);
		}

		$row['sku'] = $sku;

		$product_id = $this->get_product_id_by_sku( $sku );
		$is_new     = false;

		if ( ! $product_id ) {
			if ( $dry_run ) {
				return array(
					'status'  => 'created',
					'message' => __( 'Dry run: new product would be created.', 'boulk-update-products' ),
				);
			}

			$product = $this->create_product( $sku, $row );
			if ( ! $product ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Could not create new product.', 'boulk-update-products' ),
				);
			}

			$product_id = $product->get_id();
			$this->remember_sku( $sku, $product_id );
			$is_new     = true;
		}

		if ( 'price_create' === $this->import_mode ) {
			$this->active_fields = $is_new ? $this->create_fields : array( 'regular_price' );
		} else {
			$this->active_fields = $this->enabled_fields;
		}

		$row        = Boulk_UP_Update_Fields::filter_row( $row, $this->active_fields );
		$row['sku'] = $sku;

		if ( $dry_run && ! $is_new ) {
			return array(
				'status'  => 'updated',
				'message' => __( 'Dry run: product would be updated.', 'boulk-update-products' ),
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Could not load product.', 'boulk-update-products' ),
			);
		}

		$warnings = array();
		$post_id  = $product->get_id();
		$changed  = $is_new;

		try {
			if ( $this->apply_product_fields( $product, $row, $warnings ) ) {
				$product->save();
				$changed = true;
			}

			if ( $this->apply_post_fields( $post_id, $row, $warnings ) ) {
				$changed = true;
			}

			if ( $this->yoast && $this->yoast->update( $post_id, $row ) ) {
				$changed = true;
			}

			if ( $this->apply_alt_text( $post_id, $row ) ) {
				$changed = true;
			}

			if ( $this->apply_categories( $post_id, $row, $warnings ) ) {
				$changed = true;
			}

			if ( $this->apply_brands( $post_id, $row, $warnings ) ) {
				$changed = true;
			}

			if ( $this->apply_cross_sells( $product, $row, $warnings ) ) {
				$changed = true;
			}

			if ( $this->apply_meta_fields( $post_id, $row ) ) {
				$changed = true;
			}

			if ( ! $changed ) {
				return array(
					'status'  => 'skipped',
					'message' => __( 'No values to update in selected fields for this row.', 'boulk-update-products' ),
				);
			}

			if ( $is_new ) {
				$message = __( 'New product created and saved.', 'boulk-update-products' );
			} else {
				$message = __( 'Product updated successfully.', 'boulk-update-products' );
			}

			if ( ! empty( $warnings ) ) {
				$message .= ' ' . implode( ' ', $warnings );
			}

			return array(
				'status'     => $is_new ? 'created' : 'updated',
				'message'    => $message,
				'product_id' => $post_id,
			);
		} catch ( Exception $e ) {
			return array(
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Create a new simple product.
	 *
	 * @param string                $sku  Product SKU.
	 * @param array<string, string> $row  Row data.
	 * @return WC_Product_Simple|null
	 */
	private function create_product( $sku, $row ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );

		$name = $sku;
		if ( ! empty( $row['title'] ) ) {
			$name = $row['title'];
		} elseif ( ! empty( $row['description'] ) ) {
			$name = wp_trim_words( wp_strip_all_tags( $row['description'] ), 12, '…' );
		}

		$product->set_name( sanitize_text_field( $name ) );
		$product->set_status( apply_filters( 'boulk_up_new_product_status', 'publish' ) );
		$product->set_catalog_visibility( 'visible' );
		$product->save();

		return $product;
	}

	/**
	 * Check if field is enabled for this import.
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	private function is_enabled( $field ) {
		$fields = null !== $this->active_fields ? $this->active_fields : $this->enabled_fields;
		return Boulk_UP_Update_Fields::is_enabled( $field, $fields );
	}

	/**
	 * Apply WooCommerce product object fields.
	 *
	 * @param WC_Product            $product  Product.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings collector.
	 * @return bool True if something changed.
	 */
	private function apply_product_fields( $product, $row, &$warnings ) {
		$changed = false;

		if ( $this->is_enabled( 'regular_price' ) && ! empty( $row['regular_price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'sale_price' ) && ! empty( $row['sale_price'] ) ) {
			$product->set_sale_price( wc_format_decimal( $row['sale_price'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'short_description' ) && ! empty( $row['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $row['short_description'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'description' ) && ! empty( $row['description'] ) ) {
			$product->set_description( wp_kses_post( $row['description'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'product_tax' ) && ! empty( $row['product_tax'] ) ) {
			$product->set_tax_class( sanitize_title( $row['product_tax'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'stock_status' ) && ! empty( $row['stock_status'] ) ) {
			$status = $this->normalize_stock_status( $row['stock_status'] );
			if ( $status ) {
				$product->set_stock_status( $status );
				$product->set_manage_stock( false );
				$changed = true;
			}
		}

		if ( $this->is_enabled( 'weight' ) && '' !== ( $row['weight'] ?? '' ) ) {
			$product->set_weight( wc_format_decimal( $row['weight'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'length' ) && '' !== ( $row['length'] ?? '' ) ) {
			$product->set_length( wc_format_decimal( $row['length'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'width' ) && '' !== ( $row['width'] ?? '' ) ) {
			$product->set_width( wc_format_decimal( $row['width'] ) );
			$changed = true;
		}

		if ( $this->is_enabled( 'height' ) && '' !== ( $row['height'] ?? '' ) ) {
			$product->set_height( wc_format_decimal( $row['height'] ) );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Normalize stock status from CSV text.
	 *
	 * @param string $value Raw value.
	 * @return string|null instock|outofstock|onbackorder
	 */
	private function normalize_stock_status( $value ) {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( ' ', '-' ), '', $value );

		$map = array(
			'instock'     => 'instock',
			'in_stock'    => 'instock',
			'yes'         => 'instock',
			'1'           => 'instock',
			'available'   => 'instock',
			'outofstock'  => 'outofstock',
			'out_of_stock' => 'outofstock',
			'no'          => 'outofstock',
			'0'           => 'outofstock',
			'unavailable' => 'outofstock',
			'onbackorder' => 'onbackorder',
			'backorder'   => 'onbackorder',
		);

		return isset( $map[ $value ] ) ? $map[ $value ] : null;
	}

	/**
	 * Apply post-level fields (title, slug).
	 *
	 * @param int                   $post_id  Post ID.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings.
	 * @return bool
	 */
	private function apply_post_fields( $post_id, $row, &$warnings ) {
		$post_update = array( 'ID' => $post_id );
		$changed     = false;

		if ( $this->is_enabled( 'title' ) && ! empty( $row['title'] ) ) {
			$post_update['post_title'] = sanitize_text_field( $row['title'] );
			$changed                   = true;
		}

		if ( $this->is_enabled( 'slug' ) && ! empty( $row['slug'] ) ) {
			$slug                     = sanitize_title( $row['slug'] );
			$post_update['post_name'] = wp_unique_post_slug( $slug, $post_id, get_post_status( $post_id ), 'product', 0 );
			$changed                  = true;
		}

		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
		}

		return $changed;
	}

	/**
	 * Save Automann / distributor custom meta fields.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $row     Row data.
	 * @return bool
	 */
	private function apply_meta_fields( $post_id, $row ) {
		$meta_map = array(
			'automann_part_number' => '_boulk_automann_part_number',
			'dist_price_list'      => '_boulk_dist_price_list',
			'units'                => '_boulk_units',
			'pkg_qty'              => '_boulk_pkg_qty',
			'product_group_id'     => '_boulk_product_group_id',
			'product_group_desc'   => '_boulk_product_group_desc',
		);

		$changed = false;

		foreach ( $meta_map as $field => $meta_key ) {
			if ( ! $this->is_enabled( $field ) || ! isset( $row[ $field ] ) || '' === $row[ $field ] ) {
				continue;
			}
			update_post_meta( $post_id, $meta_key, sanitize_text_field( $row[ $field ] ) );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Set featured image alt text.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $row     Row data.
	 * @return bool
	 */
	private function apply_alt_text( $post_id, $row ) {
		if ( ! $this->is_enabled( 'alt_text' ) || empty( $row['alt_text'] ) ) {
			return false;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return false;
		}

		update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', sanitize_text_field( $row['alt_text'] ) );
		return true;
	}

	/**
	 * Assign product categories (pipe or comma separated).
	 *
	 * @param int                   $post_id  Post ID.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings.
	 * @return bool
	 */
	private function apply_categories( $post_id, $row, &$warnings ) {
		if ( ! $this->is_enabled( 'categories' ) || empty( $row['categories'] ) ) {
			return false;
		}

		$raw   = $row['categories'];
		$parts = preg_split( '/[|,]/', $raw );
		$parts = is_array( $parts ) ? $parts : array( $raw );
		$terms = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			$term = $this->find_term( $part, 'product_cat' );
			if ( $term ) {
				$terms[] = (int) $term->term_id;
			} else {
				$warnings[] = sprintf(
					/* translators: %s: category name */
					__( 'Category not found: %s', 'boulk-update-products' ),
					$part
				);
			}
		}

		if ( ! empty( $terms ) ) {
			wp_set_object_terms( $post_id, $terms, 'product_cat' );
			return true;
		}

		return false;
	}

	/**
	 * Assign brand taxonomy or meta.
	 *
	 * @param int                   $post_id  Post ID.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings.
	 * @return bool
	 */
	private function apply_brands( $post_id, $row, &$warnings ) {
		if ( ! $this->is_enabled( 'brands' ) || empty( $row['brands'] ) ) {
			return false;
		}

		$taxonomy = $this->get_brand_taxonomy();
		$parts    = preg_split( '/[|,]/', $row['brands'] );
		$parts    = is_array( $parts ) ? $parts : array( $row['brands'] );

		if ( $taxonomy ) {
			$term_ids = array();
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$term = $this->find_term( $part, $taxonomy );
				if ( $term ) {
					$term_ids[] = (int) $term->term_id;
				} else {
					$warnings[] = sprintf(
						/* translators: %s: brand name */
						__( 'Brand not found: %s', 'boulk-update-products' ),
						$part
					);
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
				return true;
			}
			return false;
		}

		update_post_meta( $post_id, '_boulk_brand', sanitize_text_field( $row['brands'] ) );
		return true;
	}

	/**
	 * Detect brand taxonomy on this store.
	 *
	 * @return string|null
	 */
	private function get_brand_taxonomy() {
		$candidates = apply_filters(
			'boulk_up_brand_taxonomies',
			array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'brand', 'pa_brand' )
		);

		foreach ( $candidates as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return null;
	}

	/**
	 * Find taxonomy term by slug then name.
	 *
	 * @param string $identifier Term name or slug.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return WP_Term|null
	 */
	private function find_term( $identifier, $taxonomy ) {
		$slug_term = get_term_by( 'slug', sanitize_title( $identifier ), $taxonomy );
		if ( $slug_term && ! is_wp_error( $slug_term ) ) {
			return $slug_term;
		}

		$name_term = get_term_by( 'name', $identifier, $taxonomy );
		if ( $name_term && ! is_wp_error( $name_term ) ) {
			return $name_term;
		}

		return null;
	}

	/**
	 * Set cross-sell products by SKU list.
	 *
	 * @param WC_Product            $product  Product.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings.
	 * @return bool
	 */
	private function apply_cross_sells( $product, $row, &$warnings ) {
		if ( ! $this->is_enabled( 'cross_sells' ) || empty( $row['cross_sells'] ) ) {
			return false;
		}

		$skus = array_map( 'trim', explode( ',', $row['cross_sells'] ) );
		$ids  = array();

		foreach ( $skus as $cross_sku ) {
			if ( '' === $cross_sku ) {
				continue;
			}

			$cross_id = wc_get_product_id_by_sku( $cross_sku );
			if ( $cross_id && (int) $cross_id !== (int) $product->get_id() ) {
				$ids[] = (int) $cross_id;
			} elseif ( ! $cross_id ) {
				$warnings[] = sprintf(
					/* translators: %s: SKU */
					__( 'Cross-sell SKU not found: %s', 'boulk-update-products' ),
					$cross_sku
				);
			}
		}

		$product->set_cross_sell_ids( array_unique( $ids ) );
		$product->save();
		return true;
	}
}
