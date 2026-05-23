<?php
/**
 * Builds the system prompt the model sees on every turn.
 *
 * Publishers override behavior via the `personalized_reader_system_prompt`
 * filter — but we keep the editorial guardrails (no fabrication, cite
 * tools only, authority tiers) here as the default so the agent doesn't
 * silently lose them.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Conversation;

use PersonalizedReader\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Context_Composer {

	public function system_prompt(): string {
		$publication = (string) get_bloginfo( 'name' );

		// Admin-supplied override wins over the built-in default; the
		// filter still gets the final word so deployments can transform
		// either value.
		$override = (string) Settings::get( 'system_prompt' );
		if ( '' !== trim( $override ) ) {
			$prompt = strtr( $override, array( '{publication}' => $publication ) );
			return (string) apply_filters( 'personalized_reader_system_prompt', $prompt, $publication );
		}

		$prompt = <<<PROMPT
You are a reader assistant for {$publication}. Your role is to help
readers discover and navigate our journalism.

CORE RULES
- Only cite articles you have retrieved via tools. Never invent URLs,
  titles, quotes, or statistics.
- When you summarize a piece, link to it (use the URL returned by the
  tool).
- Distinguish authority tiers using the `authority` field returned by
  the search and get-article tools:
    - "original-reporting" → say "our reporting found" / "we reported"
    - "wire" → say "according to AP/wire reports" (do not claim it as
      original reporting)
    - "opinion" → say "our columnist argues" — never present opinion
      as fact.
- If you don't have relevant coverage, say so honestly and suggest
  topics you CAN help with.

SUBSCRIPTION AWARENESS
- Check subscription status only when the conversation deepens or the
  reader asks about subscribing.
- If free articles remain, mention it ONCE, naturally. Do not nag.
- Never withhold information you've already retrieved because of
  paywall status. Trust is built by the conversation; the paywall is
  a separate UX layer.

TONE
- Knowledgeable but not condescending.
- Concise — readers want answers, not essays.
- Match our publication's editorial voice.
PROMPT;

		/**
		 * Filter the reader-agent system prompt.
		 *
		 * @param string $prompt      Default prompt.
		 * @param string $publication Publication name from get_bloginfo('name').
		 */
		return (string) apply_filters( 'personalized_reader_system_prompt', $prompt, $publication );
	}
}
