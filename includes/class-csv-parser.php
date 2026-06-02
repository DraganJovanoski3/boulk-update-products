<?php
/**
 * CSV parser with header aliases and row offset iteration.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_CSV_Parser
 */
class Boulk_UP_CSV_Parser {

	/**
	 * Canonical field => accepted header aliases (lowercase).
	 *
	 * @var array<string, string[]>
	 */
	public static $field_aliases = array(
		'sku'                  => array( 'sku' ),
		'automann_part_number' => array( 'automann_part_number', 'automann part number' ),
		'title'                => array( 'title', 'name' ),
		'short_description'    => array( 'short_description', 'short description' ),
		'description'          => array( 'description', 'full_description', 'full description' ),
		'slug'                 => array( 'slug' ),
		'regular_price'        => array( 'regular_price', 'updated_price', 'updated price', 'new_price', 'new price' ),
		'sale_price'           => array( 'sale_price', 'dist_net_price', 'dist net price' ),
		'dist_price_list'      => array( 'dist_price_list', 'dist price list' ),
		'seo_title'            => array( 'seo_title', 'meta_title' ),
		'meta_description'     => array( 'meta_description' ),
		'focus_keyphrase'      => array( 'focus_keyphrase', 'focus_keyword', 'focus keyword' ),
		'meta_keywords'        => array( 'meta_keywords', 'keyword', 'keywords' ),
		'product_tax'          => array( 'product_tax', 'tax_class' ),
		'cross_sells'          => array( 'cross_sells', 'cross_reference', 'cross_sell', 'cross sell' ),
		'categories'           => array( 'categories', 'product_category', 'product category', 'master_category', 'master category' ),
		'brands'               => array( 'brands', 'brand' ),
		'stock_status'         => array( 'stock_status', 'stock status' ),
		'weight'               => array( 'weight' ),
		'width'                => array( 'width' ),
		'length'               => array( 'length' ),
		'height'               => array( 'height' ),
		'units'                => array( 'units', 'unit' ),
		'pkg_qty'              => array( 'pkg_qty', 'pkg/qty', 'pkg qty' ),
		'product_group_id'     => array( 'product_group_id', 'product group id' ),
		'product_group_desc'   => array( 'product_group_desc', 'product group desc', 'product_group_description' ),
		'alt_text'             => array( 'alt_text', 'image_alt', 'alt text' ),
	);

	/**
	 * File path.
	 *
	 * @var string
	 */
	private $file_path;

	/**
	 * CSV delimiter.
	 *
	 * @var string
	 */
	private $delimiter = ',';

	/**
	 * Header row (canonical field => column index).
	 *
	 * @var array<string, int>
	 */
	private $column_map = array();

	/**
	 * Total data rows (excluding header).
	 *
	 * @var int
	 */
	private $total_rows = 0;

	/**
	 * Constructor.
	 *
	 * @param string $file_path Absolute path to CSV file.
	 */
	public function __construct( $file_path ) {
		$this->file_path = $file_path;
	}

	/**
	 * Parse headers and count rows.
	 *
	 * @return true|WP_Error
	 */
	public function initialize() {
		if ( ! is_readable( $this->file_path ) ) {
			return new WP_Error( 'boulk_csv_unreadable', __( 'CSV file is not readable.', 'boulk-update-products' ) );
		}

		$handle = $this->open_file();
		if ( ! $handle ) {
			return new WP_Error( 'boulk_csv_open', __( 'Could not open CSV file.', 'boulk-update-products' ) );
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle );
			return new WP_Error( 'boulk_csv_empty', __( 'CSV file is empty.', 'boulk-update-products' ) );
		}

		$this->delimiter = self::detect_delimiter( $first_line );
		rewind( $handle );

		$headers = fgetcsv( $handle, 0, $this->delimiter );
		fclose( $handle );

		if ( empty( $headers ) ) {
			return new WP_Error( 'boulk_csv_no_headers', __( 'CSV file has no header row.', 'boulk-update-products' ) );
		}

		$this->column_map = $this->build_column_map( $headers );

		if ( ! isset( $this->column_map['sku'] ) ) {
			return new WP_Error(
				'boulk_csv_missing_sku',
				__( 'CSV must include a "sku" column.', 'boulk-update-products' )
			);
		}

		$this->total_rows = $this->count_data_rows();

		return true;
	}

	/**
	 * Load parser state from a saved import job (skips full file scan).
	 *
	 * @param array<string, int> $column_map Column index map.
	 * @param string             $delimiter  CSV delimiter.
	 * @param int                $total_rows Known row total.
	 */
	public function load_state( $column_map, $delimiter, $total_rows = 0 ) {
		$this->column_map = $column_map;
		$this->delimiter  = $delimiter;
		$this->total_rows = (int) $total_rows;
	}

	/**
	 * Read the next chunk of rows using a byte offset (fast resume).
	 *
	 * @param int $file_offset   Byte position in file (0 = after header).
	 * @param int $row_index     Current 0-based data row index.
	 * @param int $limit         Max rows to read.
	 * @return array{rows: array<int, array<string, string>>, next_offset: int, next_row_index: int}
	 */
	public function read_chunk( $file_offset, $row_index, $limit ) {
		$rows      = array();
		$handle    = fopen( $this->file_path, 'rb' );
		$next_index = $row_index;

		if ( ! $handle ) {
			return array(
				'rows'           => $rows,
				'next_offset'    => $file_offset,
				'next_row_index' => $row_index,
			);
		}

		if ( 0 === $file_offset ) {
			$bom = fread( $handle, 3 );
			if ( "\xEF\xBB\xBF" !== $bom ) {
				rewind( $handle );
			}
			fgetcsv( $handle, 0, $this->delimiter );
		} else {
			fseek( $handle, $file_offset );
		}

		$fetched = 0;
		while ( ( $data = fgetcsv( $handle, 0, $this->delimiter ) ) !== false && $fetched < $limit ) {
			if ( $this->is_empty_row( $data ) ) {
				continue;
			}

			$rows[ $next_index ] = $this->map_row( $data );
			++$next_index;
			++$fetched;
		}

		$next_offset = ftell( $handle );
		fclose( $handle );

		return array(
			'rows'           => $rows,
			'next_offset'    => (int) $next_offset,
			'next_row_index' => $next_index,
		);
	}

	/**
	 * Get total data row count.
	 *
	 * @return int
	 */
	public function get_total_rows() {
		return $this->total_rows;
	}

	/**
	 * Get column map.
	 *
	 * @return array<string, int>
	 */
	public function get_column_map() {
		return $this->column_map;
	}

	/**
	 * Get delimiter.
	 *
	 * @return string
	 */
	public function get_delimiter() {
		return $this->delimiter;
	}

	/**
	 * Iterate rows starting at offset (0-based data rows, not including header).
	 *
	 * @param int $offset     Starting data row index.
	 * @param int $limit        Max rows to return.
	 * @return array<int, array<string, string>>
	 */
	public function get_rows( $offset = 0, $limit = 50 ) {
		$rows   = array();
		$handle = $this->open_file();
		if ( ! $handle ) {
			return $rows;
		}

		// Skip header.
		fgetcsv( $handle, 0, $this->delimiter );

		$current = 0;
		$fetched = 0;

		while ( ( $data = fgetcsv( $handle, 0, $this->delimiter ) ) !== false ) {
			if ( $this->is_empty_row( $data ) ) {
				continue;
			}

			if ( $current < $offset ) {
				++$current;
				continue;
			}

			if ( $fetched >= $limit ) {
				break;
			}

			$rows[ $current ] = $this->map_row( $data );
			++$current;
			++$fetched;
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Open file handle with BOM stripped from first read.
	 *
	 * @return resource|false
	 */
	private function open_file() {
		$handle = fopen( $this->file_path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		return $handle;
	}

	/**
	 * Detect comma vs semicolon delimiter.
	 *
	 * @param string $line First line of file.
	 * @return string
	 */
	public static function detect_delimiter( $line ) {
		$commas     = substr_count( $line, ',' );
		$semicolons = substr_count( $line, ';' );
		return $semicolons > $commas ? ';' : ',';
	}

	/**
	 * Build canonical field => column index map from headers.
	 *
	 * @param array<int, string|null> $headers Raw header cells.
	 * @return array<string, int>
	 */
	private function build_column_map( $headers ) {
		$map            = array();
		$normalized_hdr = array();

		foreach ( $headers as $index => $header ) {
			$normalized_hdr[ $index ] = self::normalize_header( (string) $header );
		}

		foreach ( self::$field_aliases as $field => $aliases ) {
			foreach ( $normalized_hdr as $index => $norm ) {
				if ( in_array( $norm, $aliases, true ) ) {
					$map[ $field ] = $index;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Normalize header for matching.
	 *
	 * @param string $header Raw header.
	 * @return string
	 */
	public static function normalize_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
		$header = strtolower( trim( $header ) );
		$header = str_replace( array( '.', '/', '\\' ), '_', $header );
		$header = preg_replace( '/[^a-z0-9_]+/', '_', $header );
		$header = preg_replace( '/_+/', '_', $header );
		$header = trim( $header, '_' );
		return $header;
	}

	/**
	 * Map raw CSV row to canonical fields.
	 *
	 * @param array<int, string|null> $data Raw row.
	 * @return array<string, string>
	 */
	private function map_row( $data ) {
		$row = array();
		foreach ( $this->column_map as $field => $index ) {
			$value = isset( $data[ $index ] ) ? (string) $data[ $index ] : '';
			$row[ $field ] = trim( $value );
		}
		return $row;
	}

	/**
	 * Check if row is entirely empty.
	 *
	 * @param array<int, string|null> $data Row data.
	 * @return bool
	 */
	private function is_empty_row( $data ) {
		foreach ( $data as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Count non-empty data rows.
	 *
	 * @return int
	 */
	private function count_data_rows() {
		$handle = $this->open_file();
		if ( ! $handle ) {
			return 0;
		}

		fgetcsv( $handle, 0, $this->delimiter );

		$count = 0;
		while ( ( $data = fgetcsv( $handle, 0, $this->delimiter ) ) !== false ) {
			if ( ! $this->is_empty_row( $data ) ) {
				++$count;
			}
		}

		fclose( $handle );
		return $count;
	}

	/**
	 * Get sample CSV headers for download.
	 *
	 * @return string[]
	 */
	public static function get_sample_headers() {
		$headers = array_keys( self::$field_aliases );

		return array_map(
			static function ( $key ) {
				if ( 'regular_price' === $key ) {
					return 'updated_price';
				}
				return $key;
			},
			$headers
		);
	}
}
