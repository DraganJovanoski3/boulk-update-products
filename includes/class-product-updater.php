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
	 * Constructor.
	 */
	public function __construct() {
		$this->yoast = new Boulk_UP_Yoast_Updater();
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

		try {
			$this->apply_product_fields( $product, $row, $warnings );
			$product->save();

			$this->apply_post_fields( $post_id, $row, $warnings );
			$this->yoast->update( $post_id, $row );
			$this->apply_alt_text( $post_id, $row );
			$this->apply_categories( $post_id, $row, $warnings );
			$this->apply_cross_sells( $product, $row, $warnings );

			wc_delete_product_transients( $post_id );

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
	 * Apply WooCommerce product object fields.
	 *
	 * @param WC_Product            $product  Product.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings collector.
	 */
	private function apply_product_fields( $product, $row, &$warnings ) {
		if ( ! empty( $row['regular_price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
		}

		if ( ! empty( $row['sale_price'] ) ) {
			$product->set_sale_price( wc_format_decimal( $row['sale_price'] ) );
		} elseif ( array_key_exists( 'sale_price', $row ) && '' === $row['sale_price'] ) {
			// Explicit empty sale_price in CSV could clear sale — only if column present and empty.
			// Skip: empty means "don't update" per plan.
		}

		if ( ! empty( $row['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $row['short_description'] ) );
		}

		if ( ! empty( $row['description'] ) ) {
			$product->set_description( wp_kses_post( $row['description'] ) );
		}

		if ( ! empty( $row['product_tax'] ) ) {
			$product->set_tax_class( sanitize_title( $row['product_tax'] ) );
		}
	}

	/**
	 * Apply post-level fields (title, slug).
	 *
	 * @param int                   $post_id  Post ID.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings.
	 */
	private function apply_post_fields( $post_id, $row, &$warnings ) {
		$post_update = array( 'ID' => $post_id );

		if ( ! empty( $row['title'] ) ) {
			$post_update['post_title'] = sanitize_text_field( $row['title'] );
		}

		if ( ! empty( $row['slug'] ) ) {
			$slug = sanitize_title( $row['slug'] );
			$post_update['post_name'] = wp_unique_post_slug( $slug, $post_id, get_post_status( $post_id ), 'product', 0 );
		}

		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
		}
	}

	/**
	 * Set featured image alt text.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $row     Row data.
	 */
	private function apply_alt_text( $post_id, $row ) {
		if ( empty( $row['alt_text'] ) ) {
			return;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return;
		}

		update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', sanitize_text_field( $row['alt_text'] ) );
	}

	/**
	 * Assign product categories (no auto-create).
	 *
	 * @param int                   $post_id  Post ID.
	 * @param array<string, string> $row      Row data.
	 * @param string[]              $warnings Warnings.
	 */
	private function apply_categories( $post_id, $row, &$warnings ) {
		if ( empty( $row['categories'] ) ) {
			return;
		}

		$parts  = array_map( 'trim', explode( '|', $row['categories'] ) );
		$terms  = array();

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
		}
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
	 */
	private function apply_cross_sells( $product, $row, &$warnings ) {
		if ( empty( $row['cross_sells'] ) ) {
			return;
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
	}
}
