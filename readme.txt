=== Boulk Bulk Product Update ===
Contributors: boulk
Tags: woocommerce, yoast, bulk, csv, import, seo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
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
| regular_price | price | Regular price |
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

* Choose **Import size → Large** for ~10,000 rows per run (default)
* Choose **Maximum** for 50,000+ row files on powerful hosting
* Run imports during low-traffic periods
* Always run a **dry run** first to validate SKUs
* Processing runs in background; each tick processes many rows until a time limit is reached
* Monitor progress under Import History
* If imports stall, check **WooCommerce → Status → Scheduled Actions**
* Increase PHP `max_execution_time` and memory limits on the server if needed
* Log files are stored in `wp-content/uploads/boulk-imports/` (protected from web access)

== Frequently Asked Questions ==

= Does this create new products? =

No. Version 1.0 only updates existing products matched by SKU. Rows with unknown SKUs are skipped and logged.

= Can I update only prices? =

Yes. Include only `sku` and `regular_price` (and/or `sale_price`) columns. Empty cells are ignored.

= Does it support Excel files? =

CSV only in v1.0. Export your spreadsheet as CSV from Excel or Google Sheets.

== Changelog ==

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
