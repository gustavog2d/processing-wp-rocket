# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Processing WP Rocket is a WordPress admin plugin (not affiliated with WP Rocket) that reads WP Rocket's internal database tables, cache files and environment, and shows per-URL processing status, what was actually applied to each cached page, and whether the server/queues are healthy. It is strictly read-only: since 2.2.0 there is no purge/clear code at all.

There is no build system, package manager, or test suite in this repo — it's plain PHP/CSS loaded directly by WordPress. There are no linting or formatting configs either.

## Running / testing changes

There's no CLI build or test command. To verify changes:
1. Symlink or copy this directory into a WordPress install's `wp-content/plugins/`.
2. Activate **Processing WP Rocket** in WP Admin (requires WP Rocket active for real data; the admin table itself renders regardless).
3. Visit **Tools → Processing WP Rocket**.
4. Append `?pwr_debug=1` to the admin page URL (while logged in as a `manage_options` user) to see the detected sources (`PWR_Repository::get_sources()`): tables, layers and matched columns.
5. Without a WP Rocket license you can still test locally: set `consumer_email`, an 8-character `consumer_key` and `secret_key = hash( 'crc32', consumer_email )` in the `wp_rocket_settings` option (and remove `licence-data.php` from the WP Rocket copy so its constants don't override them). Front-end optimizations then run; SaaS features (RUCSS, CPCSS) won't complete offline, so insert rows into their tables to exercise those states.

## Architecture

**Bootstrapping**: `processing-wp-rocket.php` defines constants (`PWR_VERSION`, `PWR_DIR`, `PWR_URL`), loads `includes/helpers.php`, then instantiates the `PWR_Plugin` singleton (`includes/class-pwr-plugin.php`). That singleton's constructor manually `require_once`s every other class file — there is no autoloader. When adding a new class file, register it there.

**Data flow**: `PWR_Repository` (`includes/data/class-pwr-repository.php`) is the core. Because WP Rocket's table/column schema varies across versions, it does *dynamic schema detection* in `get_sources()` (memoized per request):
- Scans the `pwr_tables` filter list (default `wpr_rocket_cache`, `wpr_rucss_used_css`, `wpr_lazy_render_content`, `wpr_above_the_fold`) prefixed with `$wpdb->prefix`, skipping missing tables via `SHOW TABLES LIKE`.
- For each table, picks the first existing column from priority lists for `url`, `status`, `updated` (`modified` first), `device` (`is_mobile` first) and the optional error columns `error_code`, `error_message`, `retries`, `next_retry_time`, `submitted_at`. Tables without a URL column are skipped.
- `guess_layer()` maps the table name to a layer (`cache`, `rucss`, `lazy`, `priority`); unknown tables keep their own name and are ignored by the UI fields.

`query_items()` builds a `UNION ALL` of `(url, updated)` across all sources with search/status filters applied (each fragment escaped with `$wpdb->prepare()`), then runs `COUNT` and a `GROUP BY url ... LIMIT/OFFSET` page query in SQL. Each union part selects `CAST(url AS BINARY)` because WP Rocket tables can have different collations (created at different times or after a DB migration) and `UNION` refuses to mix them. **The union string is already prepared — never pass it through `prepare()` again** (literal `%` from LIKE values would break). Only the current page's URLs are then fetched in full (`fetch_rows()`) and collapsed by `build_item()` into one item per URL with `{field}`, `{field}_updated` and `errors[{field}]` for the fields in `PWR_Repository::FIELDS`. The `pwr_repository_items` filter can still short-circuit everything. Returns an `error` key when the SQL fails (shown as an admin notice).

Status handling: WP Rocket writes `to-submit`, `pending`, `in-progress`, `completed`, `failed`. `STATUS_SYNONYMS` maps raw values to `pending` / `in_progress` / `completed` / `failed`; the status filter uses the same map in SQL (`LOWER(TRIM(status)) IN (...)`), and badges always use `normalize_status()` for class + label (raw value kept in the `title`). `pick_status()` resolves duplicates with `STATUS_RANK` (failed > in_progress > pending > completed). MySQL zero dates (`0000-00-00 ...`, WP Rocket's column default) are treated as empty by `is_empty_date()`.

`get_health_report()` runs one `GROUP BY status, device` per table (no full scan), adds `coverage` (tracked rows ÷ cache rows) per field, and is cached in the `pwr_health_report` transient (`pwr_health_cache_ttl`, forced by `?pwr_refresh_health=1`).

**Cache inspection**: `PWR_Cache_Inspector` (`includes/integration/class-pwr-cache-inspector.php`) mirrors `WP_Rocket\Buffer\Cache::get_request_cache_path()` for anonymous visitors: `WP_ROCKET_CACHE_PATH . host . path`, host lowercased without port (dots → `_` when `rocket_url_no_dots`), path without trailing slash with lowercased `%XX`, file `index[-mobile][-https][-webp][-cookie].html`. **Security gate**: `dir_for_url()` is the only gate before filesystem reads for the user-supplied `?pwr_inspect=` URL — it returns `''` unless the host is in `allowed_hosts()` (this site's home/site URL hosts, filter `pwr_inspect_allowed_hosts`), no decoded path segment is `.`/`..`, and `is_within_cache_root()` confirms the resolved path (symlinks included) stays inside `cache_root()`. Keep every new file access behind it. `parse_features()` finds the meta tag with `stripos()` and runs regexes only on that tag, with at most 20 candidates and fixed windows — never run a regex over the whole cached file. `file_summary()` is the cheap per-row check for the `[file]` column. `inspect()` lists every `index*.html` variant, reads the desktop file and parses `<meta name="generator" ... data-wpr-features="...">` (each step of WP Rocket's `rocket_buffer` pipeline leaves a `wpr_<feature>` marker that WP Rocket's Support module turns into this tag) plus the `cached@<ts>` footprint, then `compare_features()` maps markers to settings: `applied`, `applied_unexpected`, `missing`, `waiting` (data-dependent), `stale` (data completed after the file was written), `data_failed`, `excluded` (post meta `_rocket_exclude_{option}` from WP Rocket's metabox), `unknown` (no meta tag), `off`. Unknown markers from newer WP Rocket versions are listed as applied.

**Environment checklist**: `PWR_Environment::get_checks()` (`includes/integration/class-pwr-environment.php`) returns `ok|info|warning|error` checks: license (`rocket_valid_key()`), `WP_CACHE`, `advanced-cache.php` (exists, contains `WP_ROCKET_ADVANCED_CACHE`, `WP_ROCKET_ADVANCED_CACHE_PROBLEM` not defined), `wp-rocket-config/<host>*.php`, writable cache folders, Apache `.htaccess` `# BEGIN WP Rocket` block / Nginx note, preload on, and overdue Action Scheduler actions. Note the `advanced-cache.php` "did not run" warning appears under WP-CLI, which never loads drop-ins. When `PWR_WPRocket_Adapter::host_handles_page_cache()` is true (WP Rocket's host integrations set `do_rocket_generate_caching_files` / `rocket_generate_advanced_cache_file` to false on Kinsta, WP Engine, Pressable, Flywheel, WordPress.com, Pressidium...), the WP_CACHE / drop-in / config / server-rules checks are replaced by an info line, and the `[file]` column shows "host cache".

**WP Rocket integration**: `PWR_WPRocket_Adapter` is read-only: `is_active()`, `version()`, `get_option()` (uses `get_rocket_option()` so WP Rocket's `pre_get_rocket_option_*` filters apply, e.g. Delay JS disabling Combine JS), `cache_lifespan()` and `cache_root()`.

**Admin UI**: `PWR_Admin_Page::render()` renders, in order: environment checklist (`<details>`, opens when anything is not OK), Inspect URL panel (when `?pwr_inspect=<url>`), Site Health, Cron Status, filters, a column legend and `PWR_List_Table`. Refresh links (`?pwr_refresh_health`, `?pwr_refresh_cron`) carry a `pwr_refresh` nonce; the raw `$wpdb->last_error` is only shown with `WP_DEBUG` or `?pwr_debug=1`. Dates are converted with `PWR_List_Table::format_datetime()` (accepts GMT DB strings or Unix timestamps) → `wp_date()` in the site timezone. There is no admin JavaScript.

**Cron status**: `PWR_Cron_Status::get_status()` combines WP-Cron (next run per hook matching `pwr_cron_hook_pattern`, flagged overdue only after `max(10 min, recurrence)`) and Action Scheduler read directly from `{prefix}actionscheduler_actions` (+ groups): history aggregated per hook/status over `pwr_cron_window_days` (default 7), and a backlog per group with pending, running and overdue counts (`pwr_cron_past_due_grace`, default 10 min). All LIKE params are passed inside a single `prepare()` call per query. Memoized per request and cached in the `pwr_cron_status` transient (`?pwr_refresh_cron=1`). WP Rocket's groups seen in practice: `rocket-preload`, `rocket-rucss`, `rocket-cdn`, `rocket`.

## Known quirks

- **Version bumps need two places**: the plugin header `Version:` in `processing-wp-rocket.php` and the `PWR_VERSION` constant defined just below it must be kept in sync manually. `PWR_VERSION` is used for asset cache-busting.
- **Cache path assumptions**: the inspector reproduces WP Rocket's path logic for anonymous visitors only. Logged-in caches (`<host>-<user>-<key>`), query-string caches and multi-server setups (each node has its own files) are not covered.
- **"Enabled but missing" is not always a bug**: some markers are only written when the page had matching content (e.g. images without dimensions), and none are written when WP Rocket white label or `rocket_disable_meta_generator` is active.

## Extensibility filters

These are the public filters other code (or debugging) can hook:
- `pwr_status_labels` — customize status badge labels.
- `pwr_datetime_format` — change the date format (default `Y-m-d H:i:s`).
- `pwr_tables` — override/extend which WP Rocket tables are scanned.
- `pwr_min_capability` — capability required to access the screen (default `manage_options`).
- `pwr_table_per_page` — rows per page in the admin list table (default `20`).
- `pwr_repository_items` — short-circuit `PWR_Repository::query_items()` entirely with custom data (list table only, not the Site Health panel).
- `pwr_health_cache_ttl` — cache lifetime in seconds for the Site Health panel (default `5 * MINUTE_IN_SECONDS`).
- `pwr_health_field_labels` — customize the per-layer labels shown in the Site Health panel.
- `pwr_cron_cache_ttl` — cache lifetime in seconds for the Cron Status panel (default `5 * MINUTE_IN_SECONDS`).
- `pwr_cron_hook_pattern` — substring used to auto-detect WP Rocket's cron hooks and Action Scheduler actions (default `rocket`).
- `pwr_cron_window_days` — days of Action Scheduler history aggregated per hook (default `7`).
- `pwr_cron_past_due_grace` — seconds before a pending Action Scheduler action counts as overdue (default `10 * MINUTE_IN_SECONDS`).
- `pwr_inspect_allowed_hosts` — hosts whose cache folders the inspector may read (default: home/site URL hosts).
- `pwr_inspect_max_bytes` — maximum bytes read from a cache file in the inspector (default `8 * MB_IN_BYTES`).

## i18n

Text domain is `processing-wp-rocket`; translations live in `languages/` (`.pot` source, `pt_BR` `.po`/`.mo` included). When adding user-facing strings, use the existing `__()`/`esc_html__()` calls with this text domain and keep the `.pot` file in sync if regenerating translations.
