# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`herd-vip`) that centralizes cache invalidation for Marshall University's Herd WordPress VIP applications. It is designed to be dropped into each VIP codebase as an always-active mu-plugin under `client-mu-plugins/herd-vip/` and required from a root loader:

```php
require_once WPMU_PLUGIN_DIR . '/herd-vip/herd-vip.php';
```

It can also be activated as a conventional plugin.

## Commands

```bash
composer install          # install the dev toolchain (PHPCS + standards)
composer phpcs            # scan against the VIP ruleset — must stay clean
composer phpcbf           # auto-fix the mechanical violations
composer lint             # php -l over every file outside vendor/
```

Scan or fix a single file by passing a path: `./vendor/bin/phpcs includes/class-herd-vip-cache-purger.php`. Add `-s` to print sniff codes, which you need before writing any ignore comment.

There is no test suite and no build step — the plugin is plain PHP loaded directly by WordPress. PHPCS is the only automated gate, so behavior changes are verified by running the plugin inside a WordPress/VIP environment.

## PHPCS / VIP standards

`.phpcs.xml.dist` layers four standards, and the split is deliberate:

- `WordPress-VIP-Go` — platform and security rules (restricted functions, uncached remote requests, filesystem limits). This is what VIP itself scans with; **never weaken it**.
- `WordPress-Extra` (pulls in `WordPress-Core`) and `WordPress-Docs` — house style and docblock coverage: tabs, `array()` long syntax, Yoda conditions, spaces inside parentheses, a docblock on every file/class/method.
- `PHPCompatibilityWP` — flags syntax unsupported on the PHP versions VIP runs.

Two config values track the deployment target and should be updated when it moves: `minimum_wp_version` (6.4) and `testVersion` (`8.0-`, matching the `php` constraint in `composer.json`). `WordPress.NamingConventions.PrefixAllGlobals` is configured with the `herd_vip` / `Herd_VIP` prefixes — anything new added to the global namespace must use one of them or the scan fails.

The codebase currently passes clean. Keep it that way rather than adding `phpcs:ignore` comments; when an ignore is genuinely warranted, scope it to the single sniff code and explain why on the same line.

`vendor/` is gitignored; `composer.lock` is committed so lint results are reproducible.

## Architecture

Two files, and the split matters:

- `herd-vip.php` — plugin header, guard, single `require_once`, and one call to `Herd_VIP_Cache_Purger::bootstrap()`. Keep it a thin loader.
- `includes/class-herd-vip-cache-purger.php` — all behavior, in one `final` class of static methods. `bootstrap()` is the only place hooks are registered.

Flow: `save_post` (priority 100) / `before_delete_post` → `should_purge_post()` gate → `purge_post_urls()` builds the URL list → `purge_urls()` normalizes and fans out to each cache layer.

The gate rejects revisions, autosaves, and non-viewable post types before anything else runs. The default URL list is always homepage + permalink + post-type archive; `get_permalink()` and `get_post_type_archive_link()` can return false, so both are conditionally appended.

### Two cache layers, both optional at runtime

`purge_urls()` calls both layers unconditionally, and each one no-ops itself if unavailable:

- **VIP edge** — skipped unless `wpcom_vip_purge_edge_cache_for_url()` exists. This function-exists guard is what lets the plugin load outside VIP (local, CI, non-VIP installs) without fataling. Keep it.
- **Cloudflare** — skipped unless both `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` are readable via `getenv()`. The token is expected to be Zone-scoped with Cache Purge permission only. The request uses a 3-second timeout so a slow Cloudflare API never stalls an editor's save; failures are reported through an action, never thrown or echoed.

New cache layers should follow the same shape: a private `purge_*_urls()` method that self-checks its own availability and returns early, called from `purge_urls()`.

## Public extension surface

These hooks are the contract themes depend on — treat their names, signatures, and semantics as API. Changing them breaks consuming themes silently.

- `herd_vip_cache_purge_urls` (filter, 3 args: `$urls`, `WP_Post $post`, `string $context`) — themes add URLs for dynamic listings a post appears on (e.g. a directory page fed by a CPT). `$context` is `create`, `update`, or `delete`.
- `herd_vip_should_purge_post` (filter, 3 args) — per-post opt-out of the generic purge.
- `herd_vip_cache_purged` (action, 1 arg) — fires after a successful purge with the final URL list.
- `herd_vip_cloudflare_purge_failed` (action, 2 args) — fires on WP_Error or non-200 from Cloudflare; logging/alerting belongs here rather than inside the class.

`purge_urls()` is the one intentionally `public` method beyond the hook callbacks, so callers can purge an arbitrary URL list. Everything else stays `private`.

## Constraints to preserve

- Every file starts with `defined( 'ABSPATH' ) || exit;`.
- URLs are run through `esc_url_raw`, deduped, and emptied-filtered in `purge_urls()` before any network call — do not bypass that path.
- The plugin must never fatal or block a save when VIP functions or Cloudflare credentials are absent.
