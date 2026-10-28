=== Processing WP Rocket ===
Contributors: gustavog2d
Donate link: https://github.com/gustavog2d/processing-wp-rocket
Tags: cache, performance, logs, wp-rocket
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.2.0
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
* Unified admin table view with search and filters, including an exact-match toggle.
* Site Health panel with per-layer status percentages across the whole site.
* Cron Status panel showing WP Rocket's WP-Cron hooks and Action Scheduler queue activity.
* Environment Checklist: WP_CACHE, WP Rocket's advanced-cache.php drop-in, cache config file, writable cache folders, .htaccess rules, license, preload and overdue Action Scheduler actions.
* [file] column: whether the cache file actually exists on disk (desktop and mobile) and how old it is.
* Inspect URL: cache files on disk, optimizations actually applied to the page (read from WP Rocket's `data-wpr-features` meta tag) compared with the enabled settings, per-post exclusions, and error details for each processing queue.
* Error messages from WP Rocket's SaaS (Remove Unused CSS) and beacon tables shown next to each status.
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
No. It only reads WP Rocket’s tables and cache files for informational purposes. Since 2.2.0 the plugin has no purge code at all.

= Do I need WP Rocket installed? =
Yes. Without WP Rocket active, this plugin will not display any data.

= Will it slow down my site? =
The plugin only runs in the WordPress admin. It does not affect your frontend performance. For large sites with thousands of entries, use filters/pagination.

== Screenshots ==

1. Environment checklist, site health, cron/queue status and the per-URL table.
2. Inspect URL: cache files, optimizations applied vs enabled, and queue errors.

== Changelog ==

= 2.2.0 =
* New: Environment Checklist panel (WP_CACHE, advanced-cache.php drop-in, cache config file, writable folders, server rules, license, preload, overdue queue actions).
* New: [file] column showing whether the cache file exists on disk for desktop/mobile, and its age.
* New: Inspect URL panel comparing the optimizations WP Rocket actually applied to the cached page (`data-wpr-features`) with the enabled settings, plus per-post exclusions and per-queue error details.
* New: error code/message from WP Rocket tables shown under failed statuses.
* New: Action Scheduler backlog (pending, running, overdue) per group; history aggregated per hook over the last 7 days.
* New: "Coverage vs cache" column in Site Health.
* Fix: filtering by "In Progress" or "Pending" now matches WP Rocket's raw `in-progress` / `to-submit` values.
* Fix: badges show translated, colored labels for every raw status.
* Fix: conflicting statuses for the same URL now resolve by rank (failed > in progress > pending > completed).
* Fix: exact-match search accepts URLs with or without the trailing slash.
* Fix: WP-Cron hooks are only flagged overdue after a grace period.
* Performance: pagination, sorting and status filtering now run in SQL; Site Health uses GROUP BY queries.
* Removed: legacy REST purge endpoints and purge adapter. The RUCSS purge path could wipe the Used CSS of the whole site.

= 2.1.0 =
* Added an exact match search toggle, for finding a single URL (e.g. the home page) without substring noise.
* Added a Site Health panel with per-layer status percentages across the whole site.
* Added a Cron Status panel combining native WP-Cron and Action Scheduler data specific to WP Rocket.

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

= 2.2.0 =
Adds environment checklist, cache file verification and per-URL inspection. Removes the unused REST purge endpoints.

= 2.1.0 =
Adds Site Health and Cron Status panels, plus exact-match search. No breaking changes.

= 2.0.0 =
Major update with translation support, timezone-aware dates, and cleaner UI.  
Please update to ensure compatibility and stability.