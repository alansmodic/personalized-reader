<?php
/**
 * Bridge from the agents-api substrate to our Abilities API.
 *
 * The substrate's WP_Agent_Conversation_Loop calls into our executor when
 * the turn runner returns one or more `tool_calls`. We dispatch each call
 * to the matching ability via wp_get_ability()->execute().
 *
 * The substrate handles all the bookkeeping around tool execution (audit
 * events, tool_call IDs, transcript entries) — we just need to actually
 * run the thing and return a `{success, result}` / `{success: false, error}`
 * shape.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Tools;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;

defined( 'ABSPATH' ) || exit;

final class Tool_Executor implements WP_Agent_Tool_Executor {

	/**
	 * @param array $tool_call       Normalized prepared tool call (name, parameters, …).
	 * @param array $tool_definition Tool declaration selected for the call.
	 * @param array $context         Host runtime context for this invocation.
	 * @return array {success: bool, result?: mixed, error?: string}
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$slug   = (string) ( $tool_call['name'] ?? $tool_call['tool_name'] ?? '' );
		$params = (array) ( $tool_call['parameters'] ?? array() );

		if ( '' === $slug ) {
			return array( 'success' => false, 'error' => 'missing_tool_name' );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array( 'success' => false, 'error' => 'abilities_api_unavailable' );
		}

		$ability = wp_get_ability( $slug );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			return array( 'success' => false, 'error' => 'unknown_ability:' . $slug );
		}

		try {
			$result = $ability->execute( $params );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'error' => $result->get_error_message() );
		}

		return array(
			'success' => true,
			'result'  => is_array( $result ) ? $result : array( 'value' => $result ),
		);
	}
}
