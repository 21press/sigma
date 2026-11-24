<?php
/**
 * Import the public docs from a Delta site's REST API into a local site (WP-CLI).
 *
 *   wp eval-file tests/bin/import-docs.php [source] [type ...]
 *   wp eval-file "$PWD/tests/bin/import-docs.php" https://docs.example.com doc integration
 *
 * Upserts by slug: title, content (raw block HTML as rendered), menu order, modified date. Needs
 * the post types registered (tests/fixtures/mu-plugins/delta-assistant-dev-docs.php). Local sites
 * only: it refuses to run unless WP_ENVIRONMENT_TYPE is local or development.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	WP_CLI::error( 'Refusing to import: this is not a local or development site.' );
}

$da_source = rtrim( (string) ( $args[0] ?? '' ), '/' );
if ( '' === $da_source ) {
	WP_CLI::error( 'Pass the source site URL as the first argument.' );
}
$da_types  = array_slice( (array) $args, 1 ) ?: array( 'doc', 'integration' );

foreach ( $da_types as $da_type ) {
	if ( ! post_type_exists( $da_type ) ) {
		WP_CLI::error( "Post type {$da_type} is not registered here." );
	}
	$da_page  = 1;
	$da_count = 0;
	do {
		$da_url      = add_query_arg(
			array(
				'per_page' => 100,
				'page'     => $da_page,
				'_fields'  => 'id,slug,title,content,menu_order,modified_gmt,date_gmt,status',
			),
			"{$da_source}/wp-json/wp/v2/{$da_type}"
		);
		$da_response = wp_remote_get( $da_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $da_response ) || 200 !== wp_remote_retrieve_response_code( $da_response ) ) {
			WP_CLI::error( "Could not read {$da_url}" );
		}
		$da_items = json_decode( wp_remote_retrieve_body( $da_response ), true );
		foreach ( (array) $da_items as $da_item ) {
			$da_existing = get_page_by_path( (string) $da_item['slug'], OBJECT, $da_type );
			$da_post     = array(
				'post_type'         => $da_type,
				'post_status'       => 'publish',
				'post_name'         => (string) $da_item['slug'],
				'post_title'        => html_entity_decode( (string) $da_item['title']['rendered'], ENT_QUOTES, 'UTF-8' ),
				'post_content'      => (string) $da_item['content']['rendered'],
				'menu_order'        => (int) ( $da_item['menu_order'] ?? 0 ),
				'post_date_gmt'     => (string) $da_item['date_gmt'],
				'post_modified_gmt' => (string) $da_item['modified_gmt'],
			);
			if ( $da_existing ) {
				$da_post['ID'] = $da_existing->ID;
			}
			kses_remove_filters();
			$da_id = wp_insert_post( wp_slash( $da_post ), true );
			kses_init_filters();
			if ( is_wp_error( $da_id ) ) {
				WP_CLI::warning( "{$da_item['slug']}: " . $da_id->get_error_message() );
				continue;
			}
			++$da_count;
		}
		$da_pages = (int) wp_remote_retrieve_header( $da_response, 'x-wp-totalpages' );
		++$da_page;
	} while ( $da_page <= $da_pages );
	WP_CLI::success( "{$da_count} {$da_type} posts imported from {$da_source}." );
}
flush_rewrite_rules( false );
