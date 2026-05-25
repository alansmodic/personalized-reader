# Personalized Reader

A WordPress plugin that puts a conversational guide to your publication's archive
in front of anonymous visitors. The reader asks questions in natural language;
the agent searches the archive, summarizes findings, and links to source articles
— never fabricating, always citing.

Built on the WordPress [Agents API](https://github.com/Automattic/agents-api),
the [Abilities API](https://github.com/WordPress/abilities-api) shipping in core,
and the WordPress 7.0+ AI client.

> **Status:** MVP. Pilot-ready for small publishers with a sample-archive backend.
> Production deployments will want to wire a real semantic/vector backend via the
> filters described below.

---

## What it does

When a reader types into the widget on your site:

1. The agent receives the question along with the conversation history.
2. It calls one or more of four read-only abilities:
   - `search-archive` — find published articles matching a topic
   - `get-article` — pull the full text of a specific post
   - `check-subscription` — read the visitor's paywall state (free-articles remaining)
   - `recommend` — suggest articles given a set of topics
3. It composes a reply that **only cites articles the tools actually returned**
   and **distinguishes authority tiers**: "our reporting found" for original work,
   "according to AP" for wire content, "our columnist argues" for opinion.

Citations appear under the assistant message as a clickable list with the
authority tier rendered as a small tag.

### Three ways to embed

```
[personalized_reader]                          ← inline shortcode
[personalized_reader mode="floating"]          ← floating launcher button
```

Or insert the **Reader Chat** block (under Widgets) and configure layout in
the block sidebar. Or call from a template:

```php
echo do_shortcode( '[personalized_reader]' );
```

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│  Reader's browser                                                   │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │  pr-widget (vanilla JS, no build step)                         │ │
│  │  • mints session via POST /v1/session                          │ │
│  │  • streams turns via SSE → POST /personalized-reader/chat-stream│ │
│  │  • falls back to POST /v1/send (buffered) after 3s             │ │
│  │  • renders markdown safely (no innerHTML on model output)      │ │
│  └────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
                                  │ HTTPS
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  WordPress                                                          │
│                                                                     │
│  Conversation_Runner ──► WP_Agent_Conversation_Loop::run()          │
│       │                       │  (agents-api substrate)             │
│       │                       │                                     │
│       │                       │  • Multi-turn sequencing            │
│       │                       │  • Tool-call mediation              │
│       │                       │  • Transcript persistence           │
│       │                       │  • Lifecycle events                 │
│       │                       │                                     │
│       │                       ├─► turn_runner (our closure)         │
│       │                       │     wp_ai_client_prompt()           │
│       │                       │        ->using_abilities(...)       │
│       │                       │        ->generate_text_result()     │
│       │                       │                                     │
│       │                       └─► Tool_Executor (our adapter)       │
│       │                              wp_get_ability()->execute()    │
│       │                                                             │
│  Abilities API ◄─── 4 read-only abilities (search/get/sub/recommend)│
│       │                                                             │
│       ▼                                                             │
│  Pluggable backends via filters:                                    │
│   - personalized_reader_search_archive                              │
│   - personalized_reader_recommendations                             │
│   - personalized_reader_subscription_status                         │
│   - personalized_reader_system_prompt                               │
│                                                                     │
│  Transcript_Store ─► implements WP_Agent_Transcript_Persister       │
│    (transients, session-token keyed, 24h TTL)                       │
└─────────────────────────────────────────────────────────────────────┘
```

### Code layout

```
personalized-reader/
├── personalized-reader.php           # Plugin header + activation/deactivation hooks
├── assets/
│   ├── css/widget.css                # Scoped widget styles + markdown elements
│   └── js/widget.js                  # Vanilla JS widget + safe markdown renderer
├── blocks/reader-chat/
│   ├── block.json                    # Dynamic block, apiVersion 3
│   ├── edit.js                       # Editor controls (globals-only, no build)
│   ├── edit.asset.php                # Hand-maintained dependency manifest
│   └── editor.css                    # Editor preview styles
└── includes/
    ├── class-autoloader.php          # PSR-ish: Foo\Bar_Baz → foo/class-bar-baz.php
    ├── class-plugin.php              # Bootstrap
    ├── abilities/class-abilities.php # Category + four abilities + classify_authority()
    ├── admin/class-admin-page.php    # Settings → Personalized Reader
    ├── agent/class-reader-agent.php  # wp_register_agent
    ├── chat/
    │   ├── class-conversation-lock.php   # WP_Agent_Conversation_Lock impl (options-table)
    │   └── class-transcript-store.php    # WP_Agent_Transcript_Persister + session-keyed loader
    ├── cli/class-cli-command.php     # wp personalized-reader chat | transcript | clear
    ├── cli/class-cli-event-sink.php  # Event_Sink that prints to stdout
    ├── compat/class-dependencies.php # Runtime dep checks
    ├── conversation/
    │   ├── class-context-composer.php     # System prompt (override → filter → default)
    │   ├── class-conversation-runner.php  # Thin orchestration over WP_Agent_Conversation_Loop
    │   └── class-usage-tracker.php        # Cumulative token-usage option storage
    ├── frontend/
    │   ├── class-block.php           # register_block_type
    │   └── class-widget.php          # enqueue + shortcode + shared render_markup()
    ├── integrations/
    │   └── class-wpvdb-backend.php   # Auto-detects WPVDB, routes search to its vector index
    ├── rest/class-chat-controller.php  # REST routes (session, transcript, clear, send)
    ├── settings/class-settings.php   # Single option, sanitize, get()/all()
    ├── tools/class-tool-executor.php  # WP_Agent_Tool_Executor adapter → wp_get_ability()->execute()
    ├── streaming/
    │   ├── class-buffering-event-sink.php  # in-memory (REST fallback)
    │   ├── class-chat-stream-endpoint.php  # SSE via parse_request rewrite
    │   ├── class-event-emitter.php        # SSE writer
    │   └── class-event-sink.php           # Interface
    └── utils/class-rate-limiter.php  # Transient-backed limiter (session-keyed)
```

---

## Requirements

| Component | Version / Notes |
|---|---|
| WordPress | 7.0+ (for the bundled AI client) **or** 6.x + the [wp-ai-client](https://github.com/WordPress/php-ai-client) shim |
| PHP | 8.1+ |
| [Agents API](https://github.com/Automattic/agents-api) plugin | Active |
| Abilities API | Bundled in WP core 7.0+, otherwise the standalone plugin |
| An AI provider | The site needs a working `wp_ai_client_prompt()` — i.e. a provider plugin (e.g. `ai-provider-for-anthropic`) configured with an API key |

---

## Install

```bash
git clone https://github.com/alansmodic/personalized-reader.git \
  wp-content/plugins/personalized-reader

wp plugin activate agents-api ai-provider-for-anthropic personalized-reader
wp rewrite flush   # the SSE endpoint needs the rewrite registered
```

Verify everything came up:

```
wp eval 'var_dump( wp_get_agent("personalized-reader") );'
```

Or open **Settings → Personalized Reader** in `wp-admin`. The Status panel should
show five green checks (AI client, Abilities API, Agents API, four abilities
registered, agent registered).

---

## Configuration

### From `wp-admin`

**Settings → Personalized Reader.** Four sections:

- **Editorial voice** — system prompt override (with `{publication}` placeholder),
  widget title, input placeholder.
- **Layout & display** — default mode (inline vs floating launcher).
- **Runtime limits** — max tool rounds per message (1–8), rate limit (req/min),
  free articles before paywall.
- **Authority tier classification** — category slug for opinion content,
  comma-separated tag slugs for wire content.

A **Quick test** form on the same page sends a message through the buffered REST
endpoint so you can verify the agent without leaving the admin.

### From code (filters always win over stored options)

```php
// Replace the default WP_Query archive search with your real backend.
add_filter( 'personalized_reader_search_archive', function ( $default, $args ) {
    return my_vector_backend()->search( $args['query'], $args );
}, 10, 2 );

// Same for recommendations.
add_filter( 'personalized_reader_recommendations', function ( $default, $topics, $exclude_ids ) {
    return my_vector_backend()->recommend( $topics, $exclude_ids );
}, 10, 3 );

// Wire your subscription system.
add_filter( 'personalized_reader_subscription_status', function ( $default, $session_token ) {
    return my_paywall_status_for( $session_token );
}, 10, 2 );

// Override the system prompt entirely (the {publication} token is already substituted).
add_filter( 'personalized_reader_system_prompt', function ( $prompt, $publication ) {
    return my_custom_prompt( $publication );
}, 10, 2 );

// Force-enqueue the widget on every page (e.g. when rendering via a template tag).
add_filter( 'personalized_reader_enqueue_assets', '__return_true' );
```

### Semantic search via WPVDB (optional, recommended)

The default `personalized_reader_search_archive` backend is a `WP_Query`
keyword search (`s=`) — fine for a demo, blunt for production. Install
[Automattic/wpvdb](https://github.com/Automattic/wpvdb) and the plugin
will detect it and route both `search-archive` and `recommend` through
WPVDB's `vdb_vector_query` automatically.

```bash
wp plugin install --activate https://github.com/Automattic/wpvdb/archive/refs/heads/main.zip
# Configure your embedding API key in WPVDB settings → embed your archive
```

When WPVDB is active and **Use WPVDB when available** is checked under
Settings → Personalized Reader → Search backend, the agent retrieves
articles by semantic similarity instead of literal keyword matches. The
adapter lives at `includes/integrations/class-wpvdb-backend.php` — it's
a thin wrapper around:

```php
$q = new WP_Query( array(
    'post_type'        => 'post',
    'post_status'      => 'publish',
    'posts_per_page'   => $args['limit'] ?? 10,
    'vdb_vector_query' => $args['query'],
) );
```

If you don't want the built-in adapter, uncheck the setting and
register your own filter handler — `personalized_reader_search_archive`
still wins.

#### Proven contrast (live test)

Same agent, same archive of 4 published posts (city housing plan,
tenant union eviction suit, climate emissions miss, opinion piece on
zoning), same question:

> *"What do you cover on urban inequality and displacement?"*

None of the words `urban`, `inequality`, `displacement`,
`gentrification`, `housing`, or `cities` literally appear in any post
body. The agent's behavior diverged sharply between backends:

| Backend | Result |
|---|---|
| **WPVDB on (semantic)** | Found three relevant posts on the first search. Grouped them by authority tier — reporting vs. opinion — and offered adjacent topics as a follow-up. |
| **WPVDB off (keyword)** | Empty results across four query variations (the model expanded `urban inequality` → `gentrification` → `affordable housing evictions` → `poverty inequality`). The agent honestly concluded: *"I'm not finding anything in our archive on urban inequality, displacement, gentrification, housing, or related angles."* |

The no-fabrication guardrail held in both runs — the difference is
purely in what the retrieval layer can surface.

**Precedence:** Filter > Stored option > Built-in default. So you can pin
`personalized_reader_system_prompt` via code on a multisite/VIP install and the
admin field becomes a no-op for that site.

---

## WP-CLI

Bypasses the HTTP layer — fastest feedback loop while iterating on the prompt
or ability schemas.

```bash
wp personalized-reader chat "What have you written about housing?"
# Minted session: <token>
# — stream open —
# [turn 1]
# → tool_call wpab__personalized-reader__search-archive {"query":"housing"}
# ← tool_result 2 results
# [turn 2]
# Here's what we have on housing: …
# — done —

wp personalized-reader chat "Tell me more about the second one" --session=<token>
wp personalized-reader transcript --session=<token> --format=table
wp personalized-reader clear --session=<token>
```

`--quiet` suppresses intermediate events and prints only the final assistant
text — useful in CI.

---

## REST + SSE surface

```
POST /wp-json/personalized-reader/v1/session
  → { session_token, nonce, stream_url, send_url }

GET  /wp-json/personalized-reader/v1/transcript?session_token=<token>
  → { messages: [{ role, content, ts, meta }] }

POST /wp-json/personalized-reader/v1/clear
  body { session_token }

POST /wp-json/personalized-reader/v1/send
  body { session_token?, message, request_id? }
  → { session_token, events: [{ event, data }], done }
  (buffered fallback — same Event_Sink shape as the SSE stream)

POST /personalized-reader/chat-stream
  body { _wpnonce, session_token?, message, request_id? }
  → text/event-stream with frames:
       turn_started, assistant_chunk, tool_call, tool_result, done, error
```

All endpoints are public; nonces and the per-session rate limiter are the
guardrails. Session tokens are opaque UUIDs minted by `/v1/session`. The
widget stores them in `sessionStorage` so they survive a page reload but
not a new tab.

---

## Development

```bash
composer install            # one-time, installs phpcs + WPCS

composer lint               # WordPress Coding Standards (errors fail)
composer lint:fix           # auto-fix what phpcbf knows how to fix
composer lint:syntax        # php -l across the tree

# Validate block manifest
php -r 'json_decode(file_get_contents("blocks/reader-chat/block.json"), true, 512, JSON_THROW_ON_ERROR);'
```

`composer.lock` pins the dev-tool versions in-repo so CI installs are
deterministic. The full ruleset lives in `phpcs.xml.dist`.

### CI

`.github/workflows/lint.yml` runs on every push to `main` and every PR:

- `php -l` syntax check on PHP 8.1 and 8.3
- `phpcs` against the WordPress + WordPress.Security + PHPCompatibilityWP
  rulesets, annotating violations inline on the PR diff via `cs2pr`
- `block.json` schema sanity check

The matrix doubles up to catch deprecations that appear in later PHP
versions but not in our minimum.

### Testing against a real WordPress site

Two options depending on what you want to exercise.

**Studio (fastest, agent only).** Studio is SQLite-only, which means
the agent layer works fine but the WPVDB integration cannot be tested
(WPVDB needs MySQL/MariaDB). Use Studio when iterating on prompts,
abilities, or the conversation runner:

```bash
studio site create --name="personalized-reader-test"

cp -R /path/to/agents-api                ~/Studio/personalized-reader-test/wp-content/plugins/
cp -R /path/to/ai-provider-for-anthropic ~/Studio/personalized-reader-test/wp-content/plugins/
ln -sfn $PWD ~/Studio/personalized-reader-test/wp-content/plugins/personalized-reader

cd ~/Studio/personalized-reader-test
studio wp plugin activate agents-api ai-provider-for-anthropic personalized-reader
studio wp option update connectors_ai_anthropic_api_key '<your-key>'
studio wp personalized-reader chat "What have you written about housing?"
```

**LocalWP (MySQL — needed for WPVDB).** When testing the semantic
search path, use LocalWP. Create a site with MySQL 8.x, then:

```bash
brew install --cask local       # if you don't have LocalWP yet
# Create the site in the Local GUI: PHP 8.1+, MySQL 8.0+, name it whatever.

SITE=~/Local\ Sites/personalized-reader/app/public

# Symlink this plugin, copy the deps
ln -sfn /path/to/personalized-reader "$SITE/wp-content/plugins/personalized-reader"
cp -R /path/to/agents-api                "$SITE/wp-content/plugins/"
cp -R /path/to/ai-provider-for-anthropic "$SITE/wp-content/plugins/"
cp -R /path/to/action-scheduler          "$SITE/wp-content/plugins/"

# Fetch WPVDB
cd "$SITE/wp-content/plugins"
curl -sL https://github.com/Automattic/wpvdb/archive/refs/heads/main.zip -o wpvdb.zip \
  && unzip -q wpvdb.zip && mv wpvdb-main wpvdb && rm wpvdb.zip

# Local's MySQL is socket-only. Point wp-config at it (one-time):
SITE_ID=$(ls ~/Library/Application\ Support/Local/run/ | head -1)
SOCK="$HOME/Library/Application Support/Local/run/$SITE_ID/mysql/mysqld.sock"
sed -i.bak "s|define( 'DB_HOST', 'localhost' );|define( 'DB_HOST', 'localhost:$SOCK' );|" "$SITE/wp-config.php"

cd "$SITE"
wp plugin activate agents-api action-scheduler ai-provider-for-anthropic wpvdb personalized-reader
wp option update connectors_ai_anthropic_api_key '<your-anthropic-key>'
```

#### WPVDB on MySQL 8.x — two workarounds you'll need

WPVDB targets MySQL 8.0.32+ (with native `VECTOR`) or MariaDB 11.7+.
Oracle MySQL didn't actually ship the `VECTOR` type until 9.0, but
WPVDB's version check accepts any 8.0.32+ as compatible. On MySQL 8.x
the embeddings table fails to create. Two pieces unblock this:

1. **Opt into JSON-fallback storage** via a mu-plugin:

   ```php
   // wp-content/mu-plugins/00-wpvdb-fallbacks.php
   <?php
   /**
    * Plugin Name: WPVDB Fallback Storage
    */
   add_filter( 'wpvdb_enable_fallbacks', '__return_true' );
   ```

2. **Patch WPVDB to honor that filter when picking the column type.**
   `Database::has_native_vector_support()` doesn't currently consult
   `are_fallbacks_enabled()`, so even with the filter set the schema
   still tries to use `VECTOR(...)`. Add a one-line short-circuit at
   the top of the method in
   `wp-content/plugins/wpvdb/includes/class-wpvdb-database.php`:

   ```php
   public function has_native_vector_support() {
       if ( $this->are_fallbacks_enabled() ) {
           return false;
       }
       // … existing body unchanged
   }
   ```

   This is a local-only edit (it's in WPVDB's source, not in this
   plugin). On a host with real native `VECTOR` (MySQL 9.0+, MariaDB
   11.7+, MySQL HeatWave) neither workaround is needed.

#### Configure WPVDB and embed the archive

```bash
cd ~/Local\ Sites/personalized-reader/app/public

# OpenAI key (cheapest path; ~$0.001 per short post)
echo "define( 'WPVDB_OPENAI_API_KEY', 'sk-...' );" >> wp-config.php

# Point WPVDB at OpenAI's text-embedding-3-small
wp option update wpvdb_settings --format=json \
  '{"active_provider":"openai","provider":"openai","default_model":"text-embedding-3-small"}'

# Embed everything
wp eval '
$posts = get_posts( array( "post_type" => "post", "posts_per_page" => -1 ) );
foreach ( $posts as $p ) {
    \WPVDB\WPVDB_Queue::process_item( array(
        "post_id" => $p->ID,
        "model"   => "text-embedding-3-small",
        "provider" => "openai",
    ) );
}
echo $GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM wp_wpvdb_embeddings") . " embeddings\n";
'

# Run a semantic query
wp personalized-reader chat "What do you cover on urban inequality?"
```

If you see results despite the query terms not appearing in any post,
the vector path is working.

---

## Roadmap / known limitations

- **No live token streaming.** The current WP AI client surface doesn't expose
  per-token callbacks. Each tool round emits one full `assistant_chunk` event.
  SSE plumbing is in place for when it lands.
- **WP_Query is the stub backend.** The default search uses `s=` keyword
  matching, which is fine for a demo but misses semantic intent. Install
  [Automattic/wpvdb](https://github.com/Automattic/wpvdb) — the plugin
  detects it automatically and routes search/recommendations through
  WPVDB's vector index. See the "Semantic search via WPVDB" section above.
  Other backends (Enterprise Search, pgvector, Pinecone, …) can be wired
  via the `personalized_reader_search_archive` and `…_recommendations`
  filters.
- **WPVDB on MySQL 8.x needs two workarounds.** WPVDB's native-vector
  detection accepts any MySQL 8.0.32+ as compatible, but Oracle MySQL
  didn't ship `VECTOR` until 9.0. On 8.x the schema creation silently
  fails. See the "Testing against a real WordPress site" section for
  the mu-plugin + one-line source patch that fixes it. Production
  hosts running MySQL 9.0+ or MariaDB 11.7+ don't need either.
- **Anonymous sessions only.** Transcripts are session-token keyed in transients
  with a 24-hour TTL. Logged-in subscribers don't get longer-lived history yet.
- **Markdown subset.** The widget's renderer covers paragraphs, links, bold,
  italic, lists, blockquote, and inline code. Headings, code blocks, and tables
  fall through as plain text by design.
- **No "reset to defaults" button** in the admin form.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
