=== Processing WP Rocket ===
Contributors: gustavog2d
Donate link: https://github.com/gustavog2d/processing-wp-rocket
Tags: cache, performance, logs, wp-rocket
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Processing WP Rocket is a diagnostic companion for WP Rocket.  
It visualizes cache processing statuses from WP Rocket internal tables, with filters, color-coded badges, and quick PageSpeed testing.

== Description ==

Processing WP Rocket is a **diagnostic tool** that helps developers and site owners understand how WP Rocket is processing caches and resources.

This plugin does **not replace or alter WP Rocket**.  
It simply reads WP Rocket’s internal tables and displays their status in the WordPress admin.

= Features =

* Reads from WP Rocket’s database tables:
  * `wpr_rocket_cache`
  * `wpr_rucss_used_css`
  * `wpr_lazy_render_content`
  * `wpr_above_the_fold`
* Unified admin table view with search and filters.
* Status badges: Pending, In Progress, Completed, Failed.
* Statuses include date/time of last update (adjusted to your WordPress timezone).
* PageSpeed Insights button for each URL.
* Translation ready (English default, Portuguese (Brazil) included).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/processing-wp-rocket` directory, or install the plugin through the WordPress “Plugins > Add New” screen by uploading the `.zip`.
2. Activate the plugin through the “Plugins” screen in WordPress.
3. Access the new **Processing WP Rocket** menu in the WordPress admin sidebar.

== Frequently Asked Questions ==

= Does this plugin clear or modify cache files? =
No. It only reads WP Rocket’s tables for informational purposes.

= Do I need WP Rocket installed? =
Yes. Without WP Rocket active, this plugin will not display any data.

= Will it slow down my site? =
The plugin only runs in the WordPress admin. It does not affect your frontend performance. For large sites with thousands of entries, use filters/pagination.

== Screenshots ==

1. Main admin page with status table.  
2. Color-coded status badges for each processing type.  
3. PageSpeed Insights button for quick analysis.

== Changelog ==

= 2.0.0 =
* Unified cache status (no desktop/mobile split).
* Timezone-aware date formatting based on WordPress settings.
* Added i18n (default en_US, pt_BR translation included).
* Removed purge/clear buttons; kept PageSpeed testing.
* UI cleanup and stability improvements.

= 1.0.0 =
* Initial release.
* Displayed raw WP Rocket processing logs in admin.

== Upgrade Notice ==

= 2.0.0 =
Major update with translation support, timezone-aware dates, and cleaner UI.  
Please update to ensure compatibility and stability.