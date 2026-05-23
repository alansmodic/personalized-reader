<?php
/**
 * Ability category + ability registrations for the reader agent.
 *
 * All four abilities are read-only and safe for anonymous visitors —
 * permission_callback always returns true. The search and recommend
 * backends are pluggable via filters so a real vector backend
 * (Enterprise Search, pgvector, etc.) can replace the WP_Query stub
 * without touching the agent surface.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Abilities;

use PersonalizedReader\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public const CATEGORY = 'personalized-reader';

	public const ABILITY_SLUGS = array(
		'personalized-reader/search-archive',
		'personalized-reader/get-article',
		'personalized-reader/check-subscription',
		'personalized-reader/recommend',
	);

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' )
			|| ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) )
		) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Personalized Reader', 'personalized-reader' ),
				'description' => __( 'Read-only abilities for the reader-facing conversational agent.', 'personalized-reader' ),
			)
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_search_archive();
		$this->register_get_article();
		$this->register_check_subscription();
		$this->register_recommend();
	}

	private function register_search_archive(): void {
		wp_register_ability(
			'personalized-reader/search-archive',
			array(
				'label'       => __( 'Search the publication archive', 'personalized-reader' ),
				'description' => __( 'Search published articles by topic, keyword, date range, author, or authority tier (original reporting, wire, opinion).', 'personalized-reader' ),
				'category'    => self::CATEGORY,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array( 'type' => 'string', 'description' => 'Natural-language search query.' ),
						'date_from' => array( 'type' => 'string', 'description' => 'ISO 8601 start date filter.' ),
						'date_to'   => array( 'type' => 'string', 'description' => 'ISO 8601 end date filter.' ),
						'author'    => array( 'type' => 'string', 'description' => 'Filter by author display name.' ),
						'authority' => array(
							'type'        => 'string',
							'enum'        => array( 'original-reporting', 'wire', 'opinion', 'any' ),
							'description' => 'Filter by authority tier.',
						),
						'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ),
					),
					'required'   => array( 'query' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'results' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'   => array( 'type' => 'integer' ),
									'title'     => array( 'type' => 'string' ),
									'url'       => array( 'type' => 'string' ),
									'excerpt'   => array( 'type' => 'string' ),
									'author'    => array( 'type' => 'string' ),
									'published' => array( 'type' => 'string' ),
									'authority' => array( 'type' => 'string' ),
									'relevance' => array( 'type' => 'number' ),
								),
							),
						),
						'total'   => array( 'type' => 'integer' ),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => array( $this, 'execute_search_archive' ),
			)
		);
	}

	private function register_get_article(): void {
		wp_register_ability(
			'personalized-reader/get-article',
			array(
				'label'       => __( 'Retrieve a published article', 'personalized-reader' ),
				'description' => __( 'Return the title, body, author, and authority tier for a published article. Only published posts are returned; drafts and private posts are rejected.', 'personalized-reader' ),
				'category'    => self::CATEGORY,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'author'    => array( 'type' => 'string' ),
						'published' => array( 'type' => 'string' ),
						'authority' => array( 'type' => 'string' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => array( $this, 'execute_get_article' ),
			)
		);
	}

	private function register_check_subscription(): void {
		wp_register_ability(
			'personalized-reader/check-subscription',
			array(
				'label'       => __( 'Check reader subscription status', 'personalized-reader' ),
				'description' => __( 'Return the current visitor\'s subscription status and metered access counters. For anonymous visitors the result is keyed by a session token.', 'personalized-reader' ),
				'category'    => self::CATEGORY,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'session_token' => array( 'type' => 'string', 'description' => 'Opaque session identifier supplied by the chat endpoint.' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'is_subscriber'  => array( 'type' => 'boolean' ),
						'articles_read'  => array( 'type' => 'integer' ),
						'free_remaining' => array( 'type' => 'integer' ),
						'plan'           => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => array( $this, 'execute_check_subscription' ),
			)
		);
	}

	private function register_recommend(): void {
		wp_register_ability(
			'personalized-reader/recommend',
			array(
				'label'       => __( 'Recommend articles', 'personalized-reader' ),
				'description' => __( 'Recommend articles relevant to a set of topics, excluding any post IDs already shown in the current conversation.', 'personalized-reader' ),
				'category'    => self::CATEGORY,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'topics'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'exclude_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
					'required'   => array( 'topics' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'recommendations' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'   => array( 'type' => 'integer' ),
									'title'     => array( 'type' => 'string' ),
									'url'       => array( 'type' => 'string' ),
									'excerpt'   => array( 'type' => 'string' ),
									'authority' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => array( $this, 'execute_recommend' ),
			)
		);
	}

	// -- Executors -----------------------------------------------------

	public function execute_search_archive( array $args ): array {
		$query = (string) ( $args['query'] ?? '' );
		$limit = (int) ( $args['limit'] ?? 10 );

		/**
		 * Filter: replace the WP_Query stub with a real vector/semantic backend.
		 *
		 * Return null to fall through to the WP_Query default. Return an array
		 * shaped like the output_schema's `results` to short-circuit.
		 *
		 * @param array|null $results Backend results, or null to use default.
		 * @param array      $args    Normalized search arguments.
		 */
		$results = apply_filters( 'personalized_reader_search_archive', null, $args );

		if ( ! is_array( $results ) ) {
			$results = $this->wp_query_search( $query, $args, $limit );
		}

		return array(
			'results' => array_slice( $results, 0, $limit ),
			'total'   => count( $results ),
		);
	}

	public function execute_get_article( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! $post_id && ! empty( $args['url'] ) ) {
			$post_id = (int) url_to_postid( (string) $args['url'] );
		}

		if ( ! $post_id ) {
			return array( 'error' => 'Article not found.' );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return array( 'error' => 'Article not available.' );
		}

		return array(
			'post_id'   => $post->ID,
			'title'     => get_the_title( $post ),
			'content'   => wp_strip_all_tags( (string) $post->post_content ),
			'url'       => (string) get_permalink( $post ),
			'author'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'published' => (string) get_post_time( 'c', true, $post ),
			'authority' => $this->classify_authority( $post ),
		);
	}

	public function execute_check_subscription( array $args ): array {
		$default = array(
			'is_subscriber'  => false,
			'articles_read'  => 0,
			'free_remaining' => (int) Settings::get( 'free_articles' ),
			'plan'           => null,
		);

		/**
		 * Filter: integrate with the publisher's subscription system.
		 *
		 * @param array  $status        Default status payload.
		 * @param string $session_token Opaque session id (may be empty).
		 */
		return (array) apply_filters(
			'personalized_reader_subscription_status',
			$default,
			(string) ( $args['session_token'] ?? '' )
		);
	}

	public function execute_recommend( array $args ): array {
		$topics      = (array) ( $args['topics'] ?? array() );
		$exclude_ids = array_map( 'intval', (array) ( $args['exclude_ids'] ?? array() ) );
		$limit       = (int) ( $args['limit'] ?? 5 );

		/**
		 * Filter: provide recommendation results from a real backend.
		 *
		 * @param array|null $recs        Recommendations, or null to use default.
		 * @param array      $topics      Topic strings.
		 * @param int[]      $exclude_ids Post IDs to exclude.
		 */
		$recs = apply_filters( 'personalized_reader_recommendations', null, $topics, $exclude_ids );

		if ( ! is_array( $recs ) ) {
			$recs = $this->wp_query_search(
				implode( ' ', $topics ),
				array( 'exclude_ids' => $exclude_ids ),
				$limit
			);
		}

		return array( 'recommendations' => array_slice( $recs, 0, $limit ) );
	}

	// -- Default backend ----------------------------------------------

	private function wp_query_search( string $query, array $args, int $limit ): array {
		$wp_args = array(
			'post_type'           => array( 'post' ),
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 20, $limit ) ),
			's'                   => $query,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		if ( ! empty( $args['date_from'] ) || ! empty( $args['date_to'] ) ) {
			$wp_args['date_query'] = array(
				array(
					'after'     => $args['date_from'] ?? '',
					'before'    => $args['date_to'] ?? '',
					'inclusive' => true,
				),
			);
		}

		if ( ! empty( $args['author'] ) ) {
			$wp_args['author_name'] = sanitize_title( (string) $args['author'] );
		}

		if ( ! empty( $args['exclude_ids'] ) ) {
			$wp_args['post__not_in'] = array_map( 'intval', (array) $args['exclude_ids'] );
		}

		$posts   = get_posts( $wp_args );
		$results = array();
		foreach ( $posts as $post ) {
			$authority = $this->classify_authority( $post );
			if ( ! empty( $args['authority'] ) && 'any' !== $args['authority'] && $authority !== $args['authority'] ) {
				continue;
			}
			$results[] = array(
				'post_id'   => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'excerpt'   => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40 ),
				'author'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				'published' => (string) get_post_time( 'c', true, $post ),
				'authority' => $authority,
				'relevance' => 1.0,
			);
		}

		return $results;
	}

	private function classify_authority( \WP_Post $post ): string {
		$opinion_cat  = (string) Settings::get( 'authority_opinion_cat' );
		$wire_tags    = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'authority_wire_tags' ) ) ) );

		$category_slugs = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
		if ( '' !== $opinion_cat && in_array( $opinion_cat, (array) $category_slugs, true ) ) {
			return 'opinion';
		}
		foreach ( $wire_tags as $tag ) {
			if ( '' !== $tag && has_tag( $tag, $post->ID ) ) {
				return 'wire';
			}
		}
		return 'original-reporting';
	}
}
