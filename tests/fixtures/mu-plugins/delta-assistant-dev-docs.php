<?php
/**
 * Plugin Name: Delta Assistant dev: doc and integration post types
 * Description: Local test sites only. Registers the `doc` and `integration` post types that deltatheme.com has, with the same URLs (/docs/…, /integrations/…), so docs imported from staging (tests/bin/import-docs.php) live where they do on the real site.
 *
 * Copy to wp-content/mu-plugins/ of a local site. Never ships: tests/ is not in the release zip.
 *
 * @package TwentyOnePress\Delta_Assistant\Tests
 */

declare( strict_types=1 );

add_action(
	'init',
	static function (): void {
		$types = array(
			'doc'         => array( 'Docs', 'Doc', 'docs' ),
			'integration' => array( 'Integrations', 'Integration', 'integrations' ),
		);
		foreach ( $types as $type => [ $plural, $singular, $slug ] ) {
			if ( post_type_exists( $type ) ) {
				continue;
			}
			register_post_type(
				$type,
				array(
					'labels'       => array(
						'name'          => $plural,
						'singular_name' => $singular,
					),
					'public'       => true,
					'show_in_rest' => true,
					'has_archive'  => $slug,
					'rewrite'      => array(
						'slug'       => $slug,
						'with_front' => false,
					),
					'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'page-attributes' ),
				)
			);
		}
	}
);
