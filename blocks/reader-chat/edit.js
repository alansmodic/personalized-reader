/**
 * Editor-side registration for the Reader Chat block.
 *
 * Deliberately no JSX, no build step — uses the wp.* globals directly so
 * the file can be enqueued as-is. The block is dynamic: the front-end
 * markup is rendered by PHP via render_callback, so all this file needs
 * to do is provide a recognizable preview in the editor + an inspector
 * control for the `mode` attribute.
 */
( function ( wp ) {
	var registerBlockType = wp.blocks && wp.blocks.registerBlockType;
	if ( ! registerBlockType ) return;

	var el          = wp.element.createElement;
	var Fragment    = wp.element.Fragment;
	var __          = wp.i18n.__;
	var blockEditor = wp.blockEditor || wp.editor;
	var components  = wp.components;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps     = blockEditor.useBlockProps;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;
	var TextControl       = components.TextControl;

	registerBlockType( 'personalized-reader/reader-chat', {
		edit: function ( props ) {
			var attributes  = props.attributes;
			var setAttrs    = props.setAttributes;
			var blockProps  = useBlockProps( { className: 'pr-block-preview' } );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Layout', 'personalized-reader' ), initialOpen: true },
						el( SelectControl, {
							label:    __( 'Display mode', 'personalized-reader' ),
							value:    attributes.mode || 'inline',
							options:  [
								{ label: __( 'Inline (embedded)', 'personalized-reader' ), value: 'inline' },
								{ label: __( 'Floating button', 'personalized-reader' ), value: 'floating' },
							],
							onChange: function ( v ) { setAttrs( { mode: v } ); },
							help:     __( 'Inline renders the chat in-place; Floating shows a launcher button anchored to the bottom-right of every page that contains the block.', 'personalized-reader' ),
						} ),
						el( TextControl, {
							label:    __( 'Input placeholder', 'personalized-reader' ),
							value:    attributes.placeholder || '',
							onChange: function ( v ) { setAttrs( { placeholder: v } ); },
							help:     __( 'Leave blank to use the default ("Ask about our coverage…").', 'personalized-reader' ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'div', { className: 'pr-block-preview__icon' }, '💬' ),
					el( 'div', { className: 'pr-block-preview__label' },
						__( 'Reader Chat', 'personalized-reader' )
					),
					el( 'div', { className: 'pr-block-preview__meta' },
						( attributes.mode === 'floating' )
							? __( 'Floating launcher — appears on the published page only.', 'personalized-reader' )
							: __( 'Inline — renders here on the published page.', 'personalized-reader' )
					)
				)
			);
		},

		// Dynamic block: PHP renders the front-end markup. Returning null
		// from save tells Gutenberg not to serialize anything in post_content
		// beyond the block comment + attributes.
		save: function () { return null; },
	} );
} )( window.wp );
