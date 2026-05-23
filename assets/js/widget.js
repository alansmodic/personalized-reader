/**
 * Personalized Reader — frontend chat widget.
 *
 * Vanilla JS on purpose: no build step, no React/runtime dependency, no
 * @wordpress/api-fetch — the widget is meant to be cacheable on every
 * public page and shouldn't drag in editor-side bundles.
 *
 * Transport strategy mirrors editorial-assistant's streamReader.js:
 *   1. Try Server-Sent Events at PR.streamUrl.
 *   2. If no event arrives within FALLBACK_TIMEOUT_MS, abort and switch
 *      to the buffered REST fallback at PR.sendUrl. Same event shape on
 *      either path, so the renderer doesn't care which one fired.
 *
 * Bootstrap data lives on window.PersonalizedReader (set via
 * wp_localize_script): { sessionUrl, streamUrl, sendUrl, nonce, strings }.
 */
( function () {
	'use strict';

	var FALLBACK_TIMEOUT_MS = 3000;
	var STORAGE_KEY         = 'personalized_reader_session';

	var PR = window.PersonalizedReader || {};
	var strings = PR.strings || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var containers = document.querySelectorAll( '.pr-widget' );
		Array.prototype.forEach.call( containers, function ( el ) {
			new ReaderWidget( el ).mount();
		} );
	} );

	function ReaderWidget( root ) {
		this.root          = root;
		this.sessionToken  = readSession();
		this.currentAbort  = null;
		this.pendingAssist = null; // DOM node accumulating the in-flight assistant chunk
		this.citations     = [];
	}

	ReaderWidget.prototype.mount = function () {
		this.mode = ( this.root.getAttribute( 'data-mode' ) === 'floating' ) ? 'floating' : 'inline';
		this.root.classList.add( 'pr-widget--' + this.mode );

		if ( this.mode === 'floating' ) {
			this.mountFloating();
		} else {
			this.root.innerHTML = template( this.root.getAttribute( 'data-placeholder' ) );
		}

		this.messagesEl = this.root.querySelector( '.pr-widget__messages' );
		this.formEl     = this.root.querySelector( '.pr-widget__form' );
		this.inputEl    = this.root.querySelector( '.pr-widget__input' );
		this.submitEl   = this.root.querySelector( '.pr-widget__submit' );

		this.formEl.addEventListener( 'submit', this.onSubmit.bind( this ) );

		if ( ! this.sessionToken ) {
			this.mintSession();
		} else if ( PR.nonce ) {
			this.loadTranscript();
		}
	};

	ReaderWidget.prototype.mountFloating = function () {
		// Floating mode: a launcher button + a hidden panel. Click toggles
		// the panel; the panel contains the same inline template.
		this.root.innerHTML =
			'<button type="button" class="pr-widget__launcher" aria-label="' + esc( strings.openChat || 'Open reader chat' ) + '" aria-expanded="false">' +
				launcherIcon() +
			'</button>' +
			'<div class="pr-widget__panel" role="dialog" aria-label="' + esc( strings.dialogLabel || 'Reader chat' ) + '" hidden>' +
				'<header class="pr-widget__panel-header">' +
					'<span class="pr-widget__panel-title">' + esc( strings.title || 'Ask the newsroom' ) + '</span>' +
					'<button type="button" class="pr-widget__close" aria-label="' + esc( strings.closeChat || 'Close chat' ) + '">×</button>' +
				'</header>' +
				template( this.root.getAttribute( 'data-placeholder' ) ) +
			'</div>';

		var launcher = this.root.querySelector( '.pr-widget__launcher' );
		var panel    = this.root.querySelector( '.pr-widget__panel' );
		var closeBtn = this.root.querySelector( '.pr-widget__close' );
		var self     = this;

		launcher.addEventListener( 'click', function () {
			var open = ! panel.hidden;
			panel.hidden = open;
			launcher.setAttribute( 'aria-expanded', String( ! open ) );
			if ( ! open ) {
				// Focus the input when opening for keyboard users.
				setTimeout( function () {
					var input = self.root.querySelector( '.pr-widget__input' );
					if ( input ) input.focus();
				}, 0 );
			}
		} );

		function closePanel() {
			panel.hidden = true;
			launcher.setAttribute( 'aria-expanded', 'false' );
			launcher.focus();
		}

		closeBtn.addEventListener( 'click', closePanel );

		// ESC closes the panel when it has focus inside.
		this.root.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Escape' && ! panel.hidden ) {
				ev.stopPropagation();
				closePanel();
			}
		} );
	};

	ReaderWidget.prototype.mintSession = function () {
		var self = this;
		fetch( PR.sessionUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: '{}',
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.session_token ) {
					self.sessionToken = data.session_token;
					writeSession( data.session_token );
				}
				if ( data && data.nonce ) {
					PR.nonce = data.nonce;
				}
			} )
			.catch( function () { /* swallow — user can still try to send */ } );
	};

	ReaderWidget.prototype.loadTranscript = function () {
		var url = PR.transcriptUrl + '?session_token=' + encodeURIComponent( this.sessionToken );
		var self = this;
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data || ! Array.isArray( data.messages ) ) return;
				data.messages.forEach( function ( m ) {
					self.appendMessage( m.role, m.content || '' );
				} );
			} )
			.catch( function () { /* non-fatal */ } );
	};

	ReaderWidget.prototype.onSubmit = function ( ev ) {
		ev.preventDefault();
		var text = ( this.inputEl.value || '' ).trim();
		if ( ! text ) return;
		this.inputEl.value = '';
		this.setBusy( true );

		this.appendMessage( 'user', text );
		this.pendingAssist     = null;
		this.pendingAssistText = '';
		this.citations         = [];

		var body = {
			session_token: this.sessionToken || '',
			message:       text,
			request_id:    randomId(),
			_wpnonce:      PR.nonce || '',
		};

		this.stream( body );
	};

	ReaderWidget.prototype.stream = function ( body ) {
		var self = this;
		var controller = new AbortController();
		this.currentAbort = controller;

		var sawFirstEvent = false;
		var fallbackTimer = setTimeout( function () {
			if ( sawFirstEvent ) return;
			controller.abort();
			self.fallback( body );
		}, FALLBACK_TIMEOUT_MS );

		fetch( PR.streamUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
			signal: controller.signal,
		} )
			.then( function ( response ) {
				if ( ! response.ok || ! response.body ) {
					clearTimeout( fallbackTimer );
					self.fallback( body );
					return;
				}

				// If the server minted a fresh session token, capture it.
				var minted = response.headers.get( 'X-Personalized-Reader-Session' );
				if ( minted && minted !== self.sessionToken ) {
					self.sessionToken = minted;
					writeSession( minted );
				}

				var reader  = response.body.getReader();
				var decoder = new TextDecoder();
				var buffer  = '';
				var sawDone = false;

				function pump() {
					return reader.read().then( function ( chunk ) {
						if ( chunk.done ) {
							if ( ! sawDone ) self.onDone();
							return;
						}
						buffer += decoder.decode( chunk.value, { stream: true } );

						var idx;
						while ( ( idx = buffer.indexOf( '\n\n' ) ) !== -1 ) {
							var raw = buffer.slice( 0, idx );
							buffer  = buffer.slice( idx + 2 );

							if ( raw.charAt( 0 ) === ':' ) continue;

							var parsed = parseEvent( raw );
							if ( ! parsed ) continue;

							if ( ! sawFirstEvent ) {
								sawFirstEvent = true;
								clearTimeout( fallbackTimer );
							}

							if ( parsed.event === 'done' )  { sawDone = true; self.onDone(); continue; }
							if ( parsed.event === 'error' ) { self.onError( parsed.data && parsed.data.message ); continue; }
							self.onEvent( parsed );
						}
						return pump();
					} );
				}

				return pump();
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) return;
				clearTimeout( fallbackTimer );
				if ( ! sawFirstEvent ) {
					self.fallback( body );
				} else {
					self.onError( err && err.message );
				}
			} );
	};

	ReaderWidget.prototype.fallback = function ( body ) {
		var self = this;
		fetch( PR.sendUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   PR.restNonce || '',
			},
			body: JSON.stringify( {
				session_token: body.session_token,
				message:       body.message,
				request_id:    body.request_id,
			} ),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.session_token ) {
					self.sessionToken = data.session_token;
					writeSession( data.session_token );
				}
				if ( data && Array.isArray( data.events ) ) {
					data.events.forEach( function ( ev ) {
						if ( ev.event === 'done' )  { self.onDone(); return; }
						if ( ev.event === 'error' ) { self.onError( ev.data && ev.data.message ); return; }
						self.onEvent( ev );
					} );
				}
				if ( data && ! data.done ) self.onDone();
			} )
			.catch( function ( err ) { self.onError( err && err.message ); } );
	};

	ReaderWidget.prototype.onEvent = function ( ev ) {
		switch ( ev.event ) {
			case 'assistant_chunk':
				this.appendAssistantChunk( ( ev.data && ev.data.text ) || '' );
				break;
			case 'tool_result':
				this.collectCitations( ev.data && ev.data.result );
				break;
			// turn_started, user_message_persisted, tool_call: ignored in the
			// reader UI — they're useful for debugging but not for end users.
		}
	};

	ReaderWidget.prototype.onDone = function () {
		if ( this.pendingAssist && this.citations.length ) {
			this.renderCitations( this.pendingAssist, this.citations );
		}
		this.pendingAssist     = null;
		this.pendingAssistText = '';
		this.setBusy( false );
	};

	ReaderWidget.prototype.onError = function ( message ) {
		this.appendMessage( 'system', strings.error || 'Something went wrong.' );
		if ( message && window.console ) console.warn( '[personalized-reader]', message );
		this.pendingAssist = null;
		this.setBusy( false );
	};

	ReaderWidget.prototype.setBusy = function ( busy ) {
		this.submitEl.disabled = !! busy;
		this.inputEl.disabled  = !! busy;
		this.root.classList.toggle( 'is-busy', !! busy );

		// Append a real spinner element on busy, remove on idle. Keeping it
		// inside the messages list means it scrolls with the conversation
		// instead of floating over the input.
		var existing = this.messagesEl.querySelector( '.pr-widget__spinner' );
		if ( busy ) {
			if ( ! existing ) {
				var spinner = document.createElement( 'div' );
				spinner.className = 'pr-widget__spinner';
				spinner.setAttribute( 'role', 'status' );
				spinner.setAttribute( 'aria-label', strings.thinking || 'Thinking…' );
				spinner.innerHTML =
					'<span class="pr-widget__spinner-dot"></span>' +
					'<span class="pr-widget__spinner-dot"></span>' +
					'<span class="pr-widget__spinner-dot"></span>';
				this.messagesEl.appendChild( spinner );
				this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
			}
		} else if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
	};

	ReaderWidget.prototype.appendMessage = function ( role, content ) {
		var el = document.createElement( 'div' );
		el.className = 'pr-widget__message pr-widget__message--' + role;

		// User and system messages are shown as-is (plain text). Assistant
		// messages can contain markdown produced by the model and need to
		// be rendered as DOM so links are clickable.
		if ( 'assistant' === role ) {
			el.appendChild( renderMarkdown( content || '' ) );
		} else {
			el.textContent = content;
		}

		this.messagesEl.appendChild( el );
		this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
		return el;
	};

	ReaderWidget.prototype.appendAssistantChunk = function ( text ) {
		if ( ! this.pendingAssist ) {
			this.pendingAssist = this.appendMessage( 'assistant', '' );
			this.pendingAssistText = '';
		}
		this.pendingAssistText = ( this.pendingAssistText || '' ) + text;
		// Re-render markdown each time so links/lists materialize correctly
		// even when text streams in across chunks.
		while ( this.pendingAssist.firstChild ) {
			this.pendingAssist.removeChild( this.pendingAssist.firstChild );
		}
		this.pendingAssist.appendChild( renderMarkdown( this.pendingAssistText ) );
		this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
	};

	ReaderWidget.prototype.collectCitations = function ( result ) {
		if ( ! result || ! result.success || ! result.data ) return;
		var data = result.data;
		var items = [];
		if ( Array.isArray( data.results ) )         items = items.concat( data.results );
		if ( Array.isArray( data.recommendations ) ) items = items.concat( data.recommendations );
		// Single-article get-article result.
		if ( data.post_id && data.title && data.url ) items.push( data );

		var self = this;
		items.forEach( function ( item ) {
			if ( ! item || ! item.url || ! item.title ) return;
			// De-dupe by URL.
			for ( var i = 0; i < self.citations.length; i++ ) {
				if ( self.citations[ i ].url === item.url ) return;
			}
			self.citations.push( {
				url:       item.url,
				title:     item.title,
				authority: item.authority || '',
			} );
		} );
	};

	ReaderWidget.prototype.renderCitations = function ( assistantEl, citations ) {
		var list = document.createElement( 'ul' );
		list.className = 'pr-widget__citations';
		citations.forEach( function ( c ) {
			var li = document.createElement( 'li' );
			var a  = document.createElement( 'a' );
			a.href = c.url;
			a.textContent = c.title;
			a.rel = 'noopener';
			li.appendChild( a );
			if ( c.authority ) {
				var tag = document.createElement( 'span' );
				tag.className = 'pr-widget__authority pr-widget__authority--' + c.authority;
				tag.textContent = c.authority;
				li.appendChild( tag );
			}
			list.appendChild( li );
		} );
		assistantEl.appendChild( list );
	};

	// -- helpers ----------------------------------------------------------

	function template( placeholder ) {
		var ph = placeholder || strings.placeholder || 'Ask about our coverage…';
		return (
			'<div class="pr-widget__messages" role="log" aria-live="polite"></div>' +
			'<form class="pr-widget__form">' +
				'<input type="text" class="pr-widget__input" ' +
					'placeholder="' + esc( ph ) + '" ' +
					'aria-label="' + esc( ph ) + '" />' +
				'<button type="submit" class="pr-widget__submit">' +
					esc( strings.send || 'Send' ) +
				'</button>' +
			'</form>'
		);
	}

	function launcherIcon() {
		// Inline SVG keeps us asset-free.
		return (
			'<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">' +
				'<path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2Z"/>' +
			'</svg>'
		);
	}

	function esc( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Convert a markdown string into a DocumentFragment.
	 *
	 * Why hand-rolled: we want exactly the subset an LLM emits in a chat
	 * answer (paragraphs, links, bold/italic, lists, blockquote, inline
	 * code) and zero risk of injecting raw HTML. Every node is built via
	 * createElement / createTextNode so model output cannot smuggle a
	 * <script> in even on the worst day.
	 *
	 * Not supported (intentional): headings, code blocks, tables, images,
	 * reference-style links, HTML passthrough. The model tends not to use
	 * these in chat answers; if it does, they fall through as plain text.
	 */
	function renderMarkdown( source ) {
		var fragment = document.createDocumentFragment();
		var text     = String( source || '' );
		if ( ! text ) return fragment;

		// Normalize line endings, split into blocks separated by blank lines.
		var blocks = text.replace( /\r\n?/g, '\n' ).split( /\n{2,}/ );

		for ( var i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ].replace( /^\n+|\n+$/g, '' );
			if ( ! block ) continue;

			var lines = block.split( '\n' );

			if ( isList( lines, /^\s*([-*])\s+/ ) ) {
				fragment.appendChild( renderList( lines, 'ul', /^\s*[-*]\s+/ ) );
			} else if ( isList( lines, /^\s*\d+\.\s+/ ) ) {
				fragment.appendChild( renderList( lines, 'ol', /^\s*\d+\.\s+/ ) );
			} else if ( /^\s*>\s?/.test( lines[ 0 ] ) ) {
				var bq = document.createElement( 'blockquote' );
				var stripped = lines.map( function ( l ) {
					return l.replace( /^\s*>\s?/, '' );
				} ).join( '\n' );
				renderInlineInto( bq, stripped );
				fragment.appendChild( bq );
			} else {
				var p = document.createElement( 'p' );
				renderInlineInto( p, lines.join( '\n' ) );
				fragment.appendChild( p );
			}
		}

		return fragment;
	}

	function isList( lines, marker ) {
		for ( var i = 0; i < lines.length; i++ ) {
			if ( ! marker.test( lines[ i ] ) ) return false;
		}
		return lines.length > 0;
	}

	function renderList( lines, tag, marker ) {
		var list = document.createElement( tag );
		for ( var i = 0; i < lines.length; i++ ) {
			var item = document.createElement( 'li' );
			renderInlineInto( item, lines[ i ].replace( marker, '' ) );
			list.appendChild( item );
		}
		return list;
	}

	/**
	 * Inline tokenizer: walks the string and emits text nodes + <a>,
	 * <strong>, <em>, <code> elements.
	 *
	 * Layering, outermost to innermost:
	 *
	 *   code → bold → italic → links → text
	 *
	 * Each layer extracts its own tokens and recurses INTO the matched
	 * region with the next layer down. That ordering lets bold wrap a
	 * link ("**[Title](url)**" — common in LLM output) without leaving
	 * the `**` markers stranded around the rendered <a> element.
	 *
	 * Code spans stay outermost because their content is opaque to
	 * everything else — `**not bold**` inside backticks must render
	 * literally.
	 */
	function renderInlineInto( parent, text ) {
		var codeRe = /`([^`\n]+)`/g;
		var lastIdx = 0;
		var match;
		while ( ( match = codeRe.exec( text ) ) !== null ) {
			if ( match.index > lastIdx ) {
				renderBoldInto( parent, text.slice( lastIdx, match.index ) );
			}
			var codeEl = document.createElement( 'code' );
			codeEl.textContent = match[ 1 ];
			parent.appendChild( codeEl );
			lastIdx = match.index + match[ 0 ].length;
		}
		if ( lastIdx < text.length ) {
			renderBoldInto( parent, text.slice( lastIdx ) );
		}
	}

	function renderBoldInto( parent, text ) {
		// Greedy matching with a non-greedy inner so `**a** **b**` stays
		// as two spans.
		var boldRe = /(\*\*|__)([^\n]+?)\1/g;
		var lastIdx = 0;
		var match;
		while ( ( match = boldRe.exec( text ) ) !== null ) {
			if ( match.index > lastIdx ) {
				renderItalicInto( parent, text.slice( lastIdx, match.index ) );
			}
			var strong = document.createElement( 'strong' );
			renderItalicInto( strong, match[ 2 ] );
			parent.appendChild( strong );
			lastIdx = match.index + match[ 0 ].length;
		}
		if ( lastIdx < text.length ) {
			renderItalicInto( parent, text.slice( lastIdx ) );
		}
	}

	function renderItalicInto( parent, text ) {
		var italRe = /(?<![*_])(\*|_)([^*_\s][^*_\n]*?)\1(?![*_])/g;
		var lastIdx = 0;
		var match;
		while ( ( match = italRe.exec( text ) ) !== null ) {
			if ( match.index > lastIdx ) {
				renderLinksInto( parent, text.slice( lastIdx, match.index ) );
			}
			var em = document.createElement( 'em' );
			renderLinksInto( em, match[ 2 ] );
			parent.appendChild( em );
			lastIdx = match.index + match[ 0 ].length;
		}
		if ( lastIdx < text.length ) {
			renderLinksInto( parent, text.slice( lastIdx ) );
		}
	}

	function renderLinksInto( parent, text ) {
		// [text](url) — only http(s) and relative URLs are accepted so a
		// hostile model can't slip a `javascript:` link past us.
		var linkRe = /\[([^\]]+)\]\(([^)\s]+)\)/g;
		var lastIdx = 0;
		var match;
		while ( ( match = linkRe.exec( text ) ) !== null ) {
			if ( match.index > lastIdx ) {
				parent.appendChild( document.createTextNode( text.slice( lastIdx, match.index ) ) );
			}
			var url = match[ 2 ];
			if ( /^(https?:\/\/|\/)/i.test( url ) ) {
				var a = document.createElement( 'a' );
				a.href   = url;
				a.rel    = 'noopener';
				a.target = '_blank';
				a.appendChild( document.createTextNode( match[ 1 ] ) );
				parent.appendChild( a );
			} else {
				parent.appendChild( document.createTextNode( match[ 0 ] ) );
			}
			lastIdx = match.index + match[ 0 ].length;
		}
		if ( lastIdx < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( lastIdx ) ) );
		}
	}

	function parseEvent( raw ) {
		var lines = raw.split( '\n' );
		var eventName = 'message';
		var dataStr   = '';
		for ( var i = 0; i < lines.length; i++ ) {
			var line = lines[ i ];
			if ( line.indexOf( 'event: ' ) === 0 ) {
				eventName = line.slice( 7 ).trim();
			} else if ( line.indexOf( 'data: ' ) === 0 ) {
				dataStr += ( dataStr ? '\n' : '' ) + line.slice( 6 );
			}
		}
		var data = {};
		if ( dataStr ) {
			try { data = JSON.parse( dataStr ); }
			catch ( e ) { data = { raw: dataStr }; }
		}
		return { event: eventName, data: data };
	}

	function randomId() {
		return 'req_' + Math.random().toString( 36 ).slice( 2, 10 ) + Date.now().toString( 36 );
	}

	function readSession() {
		try { return window.sessionStorage.getItem( STORAGE_KEY ) || ''; }
		catch ( e ) { return ''; }
	}

	function writeSession( token ) {
		try { window.sessionStorage.setItem( STORAGE_KEY, token ); }
		catch ( e ) { /* ignore — private browsing etc. */ }
	}
} )();
