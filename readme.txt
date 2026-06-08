=== Boulk Bulk Product Update ===
Contributors: draganjovanoski3
Tags: woocommerce, yoast, bulk, csv, import, seo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk update WooCommerce products and Yoast SEO fields from CSV files. Products are matched by SKU with batched background processing for large catalogs.

== Description ==

Boulk Bulk Product Update lets store managers update thousands of WooCommerce products from a CSV spreadsheet — ideal for monthly price adjustments and SEO optimization workflows.

**Requirements:** WooCommerce and Yoast SEO must be active.

**Features:**

* Match products by SKU (update existing products only)
* Partial row updates — empty cells are skipped
* Yoast SEO fields: SEO title, meta description, focus keyphrase, meta keywords
* WooCommerce fields: prices, titles, descriptions, slug, tax class, categories, cross-sells
* Featured image alt text
* Dry run mode (validate without saving)
* Background batched processing with import size profiles (10k+ rows per run) via Action Scheduler
* Import history with progress tracking and downloadable error logs
* **Product Manager** tab: fast AJAX product table, search, bulk price/stock updates, bulk trash, and background jobs for large selections
* **Price & Create** tab: Automann/Irfan feed — update existing products by price only; create new products with title, SKU, and price (57k+ rows via auto-queue)

== Installation ==

1. Upload the `boulk-update-products` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Ensure WooCommerce and Yoast SEO are installed and active
4. Go to **WooCommerce → Bulk Product Update**

== CSV Format ==

The **sku** column is required. All other columns are optional.

**Supported columns:**

| Column | Aliases | Updates |
|--------|---------|---------|
| sku | — | Required lookup key |
| title | — | Product title |
| short_description | — | Short description |
| description | full_description | Full description |
| slug | — | Product permalink slug |
| updated_price | regular_price, price | New price (updates WooCommerce regular price) |
| sale_price | — | Sale price |
| seo_title | meta_title | Yoast SEO title |
| meta_description | — | Yoast meta description |
| focus_keyphrase | focus_keyword | Yoast focus keyphrase |
| meta_keywords | keyword | Yoast meta keywords |
| product_tax | tax_class | WooCommerce tax class |
| cross_sells | cross_reference, cross_sell | Cross-sell IDs (comma-separated SKUs) |
| categories | product_category | Categories (pipe-separated, existing only) |
| alt_text | image_alt | Featured image alt text |

**Categories:** Use pipe-separated values, e.g. `Electronics|Cables|USB`. Categories must already exist in WooCommerce.

**Cross-sells:** Comma-separated SKUs, e.g. `SKU-A,SKU-B`.

**Encoding:** UTF-8 (BOM supported). Delimiter auto-detected (comma or semicolon).

Download a sample CSV from **WooCommerce → Bulk Product Update → Documentation**.

== Large imports (10,000–50,000+ products) ==

* Choose **Import size → Auto-queue (1,000 per run)** for 20k–30k spreadsheets (recommended; selected automatically for files over 2,000 rows)
* The CSV stays saved on the server; each background run updates 1,000 products, then the next run starts automatically
* Progress shows **Run X of Y** until the full file is finished
* Run imports during low-traffic periods
* Always run a **dry run** first to validate SKUs
* Monitor progress under Import History
* If imports stall, check **WooCommerce → Status → Scheduled Actions**
* Increase PHP `max_execution_time` and memory limits on the server if needed
* Log files are stored in `wp-content/uploads/boulk-imports/` (protected from web access)

== Frequently Asked Questions ==

= Does this create new products? =

No. Version 1.0 only updates existing products matched by SKU. Rows with unknown SKUs are skipped and logged.

= Can I update only prices? =

Yes. Include only `sku` and `updated_price` (and/or `sale_price`) columns. Empty cells are ignored.

= Does it support Excel files? =

CSV only in v1.0. Export your spreadsheet as CSV from Excel or Google Sheets.

== Changelog ==

= 1.7.0 =
* Price & Create tab for Automann-style CSVs: Automann Part Number = SKU, Description = product title, Updated Price = regular price
* Existing products: price update only; missing SKUs: create simple product with title + SKU + price
* Large files use auto-queue (1,000 rows per background run)

= 1.6.0 =
* Auto-queue import mode: CSV stored on server, 1,000 products per background run, automatically continues until file is complete
* Import progress shows Run X of Y for large files; files over 2,000 rows default to auto-queue

= 1.5.2 =
* CSV import: fix duplicate product creation when the same SKU appears multiple times in one file (SKU is now cached after create; lookup always updates the oldest matching product)
* CSV import: SKU lookup uses direct DB match (MIN product ID) instead of wc_get_product_id_by_sku only

= 1.5.1 =
* Product Manager: Duplicate SKU + price scanner — find products with the same SKU and regular price, select copies (keep oldest), bulk delete to trash

= 1.5.0 =
* Product Manager tab: list products (1,000–10,000 rows per load or chunked “all”), search by SKU/name, select page or all matching, bulk update regular/sale price and stock status, bulk move to trash
* Background processing for bulk actions on more than 500 products

= 1.3.1 =
* CSV column `updated_price` (and aliases) maps to WooCommerce regular price

= 1.3.0 =
* Errors and skipped rows listed by SKU with reason in admin
* Separate CSV downloads for errors-only and skipped-only

= 1.2.0 =
* Choose which fields to update on each import (e.g. prices only)
* Quick presets: All fields, Prices only, SEO only, Content only

= 1.1.0 =
* Import size profiles: Standard, Large (~10k), Maximum (50k+)
* Time-based batch processing (up to 45–90 seconds per tick)
* Increased default max upload to 128MB
* WooCommerce bulk defer optimizations for faster updates

= 1.0.0 =
* Initial release
