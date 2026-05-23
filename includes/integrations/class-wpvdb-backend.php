<?php
/**
 * Optional integration with the WordPress Vector Database (WPVDB) plugin.
 *
 * https://github.com/Automattic/wpvdb
 *
 * When WPVDB is active and the admin setting is on, this class hooks the
 * two backend filters our abilities expose and routes them through
 * WPVDB's `vdb_vector_query` WP_Query argument. That swaps the default
 * keyword-only `WP_Query` stub for semantic similarity search — without
 * touching the agent layer, the abilities, the runner, or anything
 * downstream.
 *
 * If WPVDB isn't installed or the setting is off, this class is a no-op.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Integrations;

use PersonalizedReader\Abilities\Abilities;
use PersonalizedReader\Settings\Settings;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class WPVDB_Backend {

	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_wire' ), 20 );
	}

	public function maybe_wire(): void {
		if ( ! self::is_available() ) {
			return;
		}
		if ( ! (bool) Settings::get( 'wpvdb_integration' ) ) {
			return;
		}

		add_filter( 'personalized_reader_search_archive', array( $this, 'search' ), 10, 2 );
		add_filter( 'personalized_reader_recommendations', array( $this, 'recommend' ), 10, 3 );
	}

	/**
	 * WPVDB ships either as a namespaced plugin (`WPVDB\Core`) or
	 * registers the `vdb_vector_query` arg via a filter. We check both —
	 * the class lookup catches the standard install; the function check
	 * is a backstop for forks that expose the API differently.
	 */
	public static function is_available(): bool {
		return class_exists( '\\WPVDB\\Core' ) || function_exists( 'wpvdb_register' );
	}

	/**
	 * @param array|null $default Previous filter return; null to use built-in fallback.
	 * @param array      $args    Search arguments (query, limit, date filters, authority, …).
	 * @return array|null Results in our standard schema, or null to fall through.
	 */
	public function search( $default, array $args ) {
		if ( null !== $default ) {
			return $default;
		}

		$query = (string) ( $args['query'] ?? '' );
		if ( '' === trim( $query ) ) {
			return $default;
		}

		$limit = (int) ( $args['limit'] ?? 10 );
		$posts = $this->wpvdb_query( $query, $limit, array() );

		return $this->shape_results( $posts, $args );
	}

	/**
	 * @param array|null $default
	 * @param array<int, string> $topics
	 * @param array<int, int>    $exclude_ids
	 * @return array|null
	 */
	public function recommend( $default, array $topics, array $exclude_ids ) {
		if ( null !== $default ) {
			return $default;
		}

		$query = trim( implode( ' ', array_filter( array_map( 'strval', $topics ) ) ) );
		if ( '' === $query ) {
			return $default;
		}

		$posts = $this->wpvdb_query( $query, 5, $exclude_ids );

		// Recommendations omit the `relevance` field and use a tighter
		// excerpt, so shape them separately.
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'post_id'   => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'excerpt'   => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 24 ),
				'authority' => Abilities::classify_authority( $post ),
			);
		}
		return $out;
	}

	/**
	 * @param array<int, int> $exclude_ids
	 * @return array<int, WP_Post>
	 */
	private function wpvdb_query( string $query, int $limit, array $exclude_ids ): array {
		$wp_args = array(
			'post_type'           => array( 'post' ),
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 20, $limit ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'vdb_vector_query'    => $query,
		);
		if ( ! empty( $exclude_ids ) ) {
			$wp_args['post__not_in'] = array_map( 'intval', $exclude_ids );
		}

		$q = new WP_Query( $wp_args );
		return is_array( $q->posts ) ? $q->posts : array();
	}

	/**
	 * @param array<int, WP_Post>   $posts
	 * @param array<string, mixed>  $args
	 * @return array<int, array<string, mixed>>
	 */
	private function shape_results( array $posts, array $args ): array {
		$out                = array();
		$authority_filter   = (string) ( $args['authority'] ?? '' );
		foreach ( $posts as $post ) {
			$authority = Abilities::classify_authority( $post );
			if ( '' !== $authority_filter && 'any' !== $authority_filter && $authority !== $authority_filter ) {
				continue;
			}
			$out[] = array(
				'post_id'   => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'excerpt'   => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40 ),
				'author'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				'published' => (string) get_post_time( 'c', true, $post ),
				'authority' => $authority,
				// WPVDB doesn't surface its similarity score on the post
				// object yet; use posts_per_page order as a coarse proxy.
				'relevance' => 1.0,
			);
		}
		return $out;
	}
}
