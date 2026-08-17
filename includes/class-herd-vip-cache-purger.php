<?php
/**
 * Cache invalidation for Herd WordPress VIP applications.
 *
 * @package HerdVIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purge public URLs from WordPress VIP and, when configured, Cloudflare.
 */
final class Herd_VIP_Cache_Purger {
	/**
	 * Register content lifecycle hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'save_post', array( __CLASS__, 'purge_saved_post' ), 100, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'purge_deleted_post' ) );
	}

	/**
	 * Purge a public post after it is created or updated.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post update.
	 * @return void
	 */
	public static function purge_saved_post( $post_id, $post, $update ) {
		if ( ! self::should_purge_post( $post_id, $post ) ) {
			return;
		}

		self::purge_post_urls( $post, $update ? 'update' : 'create' );
	}

	/**
	 * Purge a public post before it is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function purge_deleted_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! self::should_purge_post( $post_id, $post ) ) {
			return;
		}

		self::purge_post_urls( $post, 'delete' );
	}

	/**
	 * Determine whether a post should trigger cache invalidation.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return bool
	 */
	private static function should_purge_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}

		return (bool) apply_filters( 'herd_vip_should_purge_post', true, $post_id, $post );
	}

	/**
	 * Build the standard URL list for a changed post and purge it.
	 *
	 * Themes can add URLs for dynamic listings through herd_vip_cache_purge_urls.
	 *
	 * @param WP_Post $post    Post object.
	 * @param string  $context Change context.
	 * @return void
	 */
	private static function purge_post_urls( $post, $context ) {
		$urls      = array( home_url( '/' ) );
		$permalink = get_permalink( $post );
		$archive   = get_post_type_archive_link( $post->post_type );

		if ( $permalink ) {
			$urls[] = $permalink;
		}

		if ( $archive ) {
			$urls[] = $archive;
		}

		$urls = apply_filters( 'herd_vip_cache_purge_urls', $urls, $post, $context );

		self::purge_urls( $urls );
	}

	/**
	 * Purge a list of URLs from configured cache layers.
	 *
	 * @param array $urls URLs to purge.
	 * @return void
	 */
	public static function purge_urls( $urls ) {
		$urls = array_values(
			array_unique(
				array_filter(
					array_map( 'esc_url_raw', $urls )
				)
			)
		);

		if ( empty( $urls ) ) {
			return;
		}

		self::purge_vip_urls( $urls );
		self::purge_cloudflare_urls( $urls );

		do_action( 'herd_vip_cache_purged', $urls );
	}

	/**
	 * Purge URLs from the WordPress VIP edge cache.
	 *
	 * @param array $urls URLs to purge.
	 * @return void
	 */
	private static function purge_vip_urls( $urls ) {
		if ( ! function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
			return;
		}

		foreach ( $urls as $url ) {
			wpcom_vip_purge_edge_cache_for_url( $url );
		}
	}

	/**
	 * Purge URLs from Cloudflare when credentials are configured.
	 *
	 * @param array $urls URLs to purge.
	 * @return void
	 */
	private static function purge_cloudflare_urls( $urls ) {
		$api_token = getenv( 'CLOUDFLARE_API_TOKEN' );
		$zone_id   = getenv( 'CLOUDFLARE_ZONE_ID' );

		if ( empty( $api_token ) || empty( $zone_id ) ) {
			return;
		}

		$response = wp_remote_post(
			sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache', rawurlencode( $zone_id ) ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'files' => $urls,
					)
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			do_action( 'herd_vip_cloudflare_purge_failed', $response, $urls );
		}
	}
}
