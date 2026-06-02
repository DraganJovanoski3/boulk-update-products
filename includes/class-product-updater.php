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
	 * Constructor.
	 *
	 * @param string[]|null $enabled_fields Fields to update; null = all.
	 */
	public function __construct( $enabled_fields = null ) {
		$this->enabled_fields = $enabled_fields;
		$this->yoast          = new Boulk_UP_Yoast_Updater( $enabled_fields );
	}

	/**
	 * Process a single CSV row.
	 *
	 * @param array<string, string> $row        Mapped row data.
	 * @param int                   $row_number Display row number for logs.
	 * @param bool                  $dry_run    If true, no DB writes.
	 * @return array{status: string, message: string}
	 */
	public function process_row( $row, $row_number, $dry_run = false ) {
		$row = Boulk_UP_Update_Fields::filter_row( $row, $this->enabled_fields );

		$sku = isset( $row['sku'] ) ? trim( $row['sku'] ) : '';

		if ( '' === $sku ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Missing SKU.', 'boulk-update-products' ),
			);
		}

		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'No product found with this SKU.', 'boulk-update-products' ),
			);
		}

		if ( $dry_run ) {
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
		$changed  = false;

		try {
			if ( $this->apply_product_fields( $product, $row, $warnings ) ) {
				$product->save();
				$changed = true;
			}

			if ( $this->apply_post_fields( $post_id, $row, $warnings ) ) {
				$changed = true;
			}

			if ( $this->yoast->update( $post_id, $row ) ) {
				$changed = true;
			}

			if ( $this->apply_alt_text( $post_id, $row ) ) {
				$changed = true;
			}

			if ( $this->apply_categories( $post_id, $row, $warnings ) ) {
				$changed = true;
			}

			if ( $this->apply_cross_sells( $product, $row, $warnings ) ) {
				$changed = true;
			}

			wc_delete_product_transients( $post_id );

			if ( ! $changed ) {
				return array(
					'status'  => 'skipped',
					'message' => __( 'No values to update in selected fields for this row.', 'boulk-update-products' ),
				);
			}

			$message = __( 'Product updated successfully.', 'boulk-update-products' );
			if ( ! empty( $warnings ) ) {
				$message .= ' ' . implode( ' ', $warnings );
			}

			return array(
				'status'  => 'updated',
				'message' => $message,
			);
		} catch ( Exception $e ) {
			return array(
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Check if field is enabled for this import.
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	private function is_enabled( $field ) {
		return Boulk_UP_Update_Fields::is_enabled( $field, $this->enabled_fields );
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

		return $changed;
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
			$slug                       = sanitize_title( $row['slug'] );
			$post_update['post_name']   = wp_unique_post_slug( $slug, $post_id, get_post_status( $post_id ), 'product', 0 );
			$changed                    = true;
		}

		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
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
	 * Assign product categories (no auto-create).
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

		$parts = array_map( 'trim', explode( '|', $row['categories'] ) );
		$terms = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$term = $this->find_category_term( $part );
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
	 * Find category by slug then name.
	 *
	 * @param string $identifier Category slug or name.
	 * @return WP_Term|null
	 */
	private function find_category_term( $identifier ) {
		$slug_term = get_term_by( 'slug', sanitize_title( $identifier ), 'product_cat' );
		if ( $slug_term && ! is_wp_error( $slug_term ) ) {
			return $slug_term;
		}

		$name_term = get_term_by( 'name', $identifier, 'product_cat' );
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
