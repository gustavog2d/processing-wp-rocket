# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Processing WP Rocket is a WordPress admin plugin (not affiliated with WP Rocket) that reads WP Rocket's internal database tables and displays per-URL cache processing status in a `WP_List_Table`. It is read-only by design in its current UI: it does not clear caches or change WP Rocket settings (older purge/clear code paths still exist internally — see "Known quirks" below).

There is no build system, package manager, or test suite in this repo — it's plain PHP/JS/CSS loaded directly by WordPress. There are no linting or formatting configs either.

## Running / testing changes

There's no CLI build or test command. To verify changes:
1. Symlink or copy this directory into a WordPress install's `wp-content/plugins/`.
2. Activate **Processing WP Rocket** in WP Admin (requires WP Rocket active for real data; the admin table itself renders regardless).
3. Visit **Tools → Processing WP Rocket**.
4. Append `?pwr_debug=1` to the admin page URL (while logged in as a `manage_options` user) to see a debug panel listing which WP Rocket tables were detected, and which URL/status/updated/device columns were matched in each.

## Architecture

**Bootstrapping**: `processing-wp-rocket.php` defines constants (`PWR_VERSION`, `PWR_DIR`, `PWR_URL`), loads `includes/helpers.php`, then instantiates the `PWR_Plugin` singleton (`includes/class-pwr-plugin.php`). That singleton's constructor manually `require_once`s every other class file — there is no autoloader. When adding a new class file, register it there.

**Data flow**: `PWR_Repository::build_groups()` (`includes/data/class-pwr-repository.php`) is the core of the plugin — a private helper shared by both `query_items()` (paginated/filtered list table data) and `get_health_report()` (whole-site health snapshot). Because WP Rocket's table/column schema varies across versions/sites, it does *dynamic schema detection* rather than assuming fixed columns:
- Scans a filterable list of candidate tables (`pwr_tables` filter, default `wpr_rucss_used_css`, `wpr_rocket_cache`, `wpr_lazy_render_content`, `wpr_above_the_fold`) prefixed with `$wpdb->prefix`, and skips any that don't exist via `SHOW TABLES LIKE`.
- For each table found, inspects `SHOW COLUMNS` and picks the first matching column name from priority lists (`$url_cols`, `$status_cols`, `$updated_cols`, `$device_cols`) — e.g. `updated_cols` tries `updated`, `updated_at`, `last_update`, etc. in order.
- A table is skipped entirely if no URL column is found.
- The `search`/`search_mode` args control the WHERE clause: `search_mode = 'contains'` (default) does `LIKE %term%`, `search_mode = 'exact'` does a plain `=` equality match against the same raw URL column shown in the URL column — used by the search screen's "Exact match" checkbox to isolate a single URL (e.g. the home page) instead of matching every URL under the same domain.
- `guess_layer()` maps table name → logical layer (`cache`, `rucss`, `lazy`, `priority`) by substring match on the table name.
- Rows are then grouped by **canonical URL** (`canonical_key()`: lowercased scheme+host+path, ignoring query/fragment) into one row per page, with separate desktop/mobile fields per layer (cache is not split by device). `pick_status()` resolves conflicting statuses for the same field using a rank (`failed` > `in_progress` > `done`), and per-field `*_updated` timestamps track the latest update per layer/device.
- Status strings are normalized via `normalize_status()` (synonym mapping including Portuguese terms like `sucesso`, `pendente`) into the canonical set `pending` / `in_progress` / `completed` / `failed`.

`query_items()` additionally supports the `pwr_repository_items` filter (return `['items' => ..., 'total' => ...]` to short-circuit the DB query entirely) — `get_health_report()` does **not** honor this filter, since its contract is raw per-URL group data, not a finished list-table page; it always reads the tables live (subject to its own cache, below).

`PWR_Repository::get_health_report()` calls `build_groups()` with no search/status/pagination (always the whole site) and tallies each layer/device field into `pending` / `in_progress` / `completed` / `failed` / `other` (unrecognized status strings land in `other` rather than being dropped, so percentages still sum to ~100%). Fields with zero tracked URLs report `0.0%` everywhere rather than being divided by zero. Result is cached via `set_transient('pwr_health_report', …, apply_filters('pwr_health_cache_ttl', 5 * MINUTE_IN_SECONDS))` since it requires an unpaginated scan of every matched row; `get_health_report(true)` forces a recompute (wired to the `?pwr_refresh_health=1` link in the admin page).

**Admin UI**: `PWR_Admin_Page::render()` (`includes/admin/class-pwr-admin-page.php`) renders two summary panels (`render_health_panel()`, `render_cron_panel()`) followed by the filter form, and delegates the table to `PWR_List_Table` (`includes/admin/class-pwr-list-table.php`), which extends core `WP_List_Table`. Columns render status+date pairs per layer/device via `render_status_with_date()`. Dates are always converted to the site timezone using `wp_date()`/`wp_timezone()`, never raw DB timestamps.

**WP Rocket integration**: `PWR_WPRocket_Adapter` (`includes/integration/class-pwr-wprocket-adapter.php`) detects WP Rocket presence (`defined('WP_ROCKET_VERSION')`) and support for specific features (page cache, RUCSS, priority elements) by checking for WP Rocket functions/DI container services. It never assumes WP Rocket internals are stable — service lookups are wrapped in `try/catch (Throwable)`.

**Cron status**: `PWR_Cron_Status::get_status()` (`includes/integration/class-pwr-cron-status.php`) reports on the cron machinery that keeps the WP Rocket tables above up to date, combining two sources because they offer different guarantees:
- **Native WP-Cron**: scans `_get_cron_array()` for hook names containing `rocket` (configurable via `pwr_cron_hook_pattern`, dynamic detection rather than a hardcoded hook list, same philosophy as the table/column detection above). WordPress core keeps no execution history for wp-cron, only the next-scheduled timestamp, so this only reports "next run" and flags `overdue` when that time has passed. `DISABLE_WP_CRON` is surfaced as an informational note, not an error (common on sites using a real system cron).
- **Action Scheduler** (the queue library WP Rocket uses to process the RUCSS backlog): feature-detected via `function_exists('as_get_scheduled_actions')`/`class_exists('ActionScheduler_Store')`, then queried directly against `{$wpdb->prefix}actionscheduler_actions` (same `SHOW TABLES LIKE`-guarded, try/catch-wrapped pattern as the repository, rather than relying on AS API methods that vary across bundled library versions) for the most recent completed/failed actions matching the same hook pattern. This is the only source with real "last run" + success/failure history.
Cached via `set_transient('pwr_cron_status', …, apply_filters('pwr_cron_cache_ttl', 5 * MINUTE_IN_SECONDS))`; forced refresh via `?pwr_refresh_cron=1`.

**REST API**: `PWR_REST` (`includes/rest/class-pwr-rest.php`) registers `processing-wp-rocket/v1/purge` and `/bulk-purge`, gated by `pwr_min_cap()` capability and a `pwr_rest`-action nonce. These call into `PWR_WPRocket_Adapter`.

## Known quirks

- **Version bumps need two places**: the plugin header `Version:` in `processing-wp-rocket.php` and the `PWR_VERSION` constant defined just below it must be kept in sync manually — nothing derives one from the other. `PWR_VERSION` is what's actually used for asset cache-busting (`wp_enqueue_style`/`wp_enqueue_script`).
- **Purge code is not exposed in the current UI**: the README states purge/clear buttons were removed in 2.0.0, and `PWR_List_Table::render_row_actions()` only renders a PageSpeed Insights link now — but `PWR_REST` (`/purge`, `/bulk-purge`) and `PWR_WPRocket_Adapter::purge_page_cache()` / `clear_used_css_for_url()` / `clear_priority_elements_data()` are still fully implemented and reachable via direct REST calls (capability + nonce gated), and `assets/js/admin.js` still wires up a `.pwr-act` click handler that POSTs to `/purge` for a class of button that no longer exists in the table markup. Treat this as functioning-but-unused legacy code, not dead code to assume-and-ignore if touching purge behavior.

## Extensibility filters

These are the public filters other code (or debugging) can hook:
- `pwr_status_labels` — customize status badge labels.
- `pwr_datetime_format` — change the date format (default `Y-m-d H:i:s`).
- `pwr_tables` — override/extend which WP Rocket tables are scanned.
- `pwr_min_capability` — capability required to access the screen/REST routes (default `manage_options`).
- `pwr_table_per_page` — rows per page in the admin list table (default `20`).
- `pwr_repository_items` — short-circuit `PWR_Repository::query_items()` entirely with custom data (list table only, not the Site Health panel).
- `pwr_health_cache_ttl` — cache lifetime in seconds for the Site Health panel (default `5 * MINUTE_IN_SECONDS`).
- `pwr_health_field_labels` — customize the per-layer labels shown in the Site Health panel.
- `pwr_cron_cache_ttl` — cache lifetime in seconds for the Cron Status panel (default `5 * MINUTE_IN_SECONDS`).
- `pwr_cron_hook_pattern` — substring used to auto-detect WP Rocket's cron hooks and Action Scheduler actions (default `rocket`).
- `pwr_cron_actions_limit` — how many recent Action Scheduler actions to consider for the Cron Status success/failure rollup (default `50`).

## i18n

Text domain is `processing-wp-rocket`; translations live in `languages/` (`.pot` source, `pt_BR` `.po`/`.mo` included). When adding user-facing strings, use the existing `__()`/`esc_html__()` calls with this text domain and keep the `.pot` file in sync if regenerating translations.
