# Herd VIP

Shared cache-invalidation helpers for Herd WordPress VIP applications.

## What it does

For public post types, this plugin purges the homepage, the changed post URL, and its post-type archive when content is created, updated, or deleted. It skips revisions and autosaves.

Every URL is purged from WordPress VIP using `wpcom_vip_purge_edge_cache_for_url()`. Cloudflare purging is optional: it runs only when both of these VIP environment variables are present:

- `CLOUDFLARE_API_TOKEN` — a Zone-scoped Cloudflare token with only Cache Purge permission.
- `CLOUDFLARE_ZONE_ID` — the zone ID for the configured domain.

## Install

Install the plugin as an always-active plugin in each VIP codebase. For example, place this repository under `client-mu-plugins/herd-vip/` and require it from a root-level loader file:

```php
<?php
require_once WPMU_PLUGIN_DIR . '/herd-vip/herd-vip.php';
```

Alternatively, install it as a conventional plugin and activate it through WordPress.

## Coding standards

The plugin is checked against the WordPress VIP Go standard, plus `WordPress-Extra`, `WordPress-Docs`, and `PHPCompatibilityWP`. The ruleset lives in `.phpcs.xml.dist`.

```bash
composer install
composer phpcs   # scan
composer phpcbf  # auto-fix
composer lint    # php -l
```

These are development dependencies only; `vendor/` is not needed at runtime and should not be deployed.

## Theme-specific URLs

Themes can extend the target list for dynamic content, such as a directory page populated by a custom post type:

```php
add_filter(
	'herd_vip_cache_purge_urls',
	function ( $urls, $post, $context ) {
		if ( 'example-profile' !== $post->post_type ) {
			return $urls;
		}

		$urls[] = home_url( '/team/' );
		return $urls;
	},
	10,
	3
);
```

The filter receives the initial URL array, the `WP_Post`, and a context of `create`, `update`, or `delete`.

The `herd_vip_should_purge_post` filter can opt out of generic purges for a post when needed.
