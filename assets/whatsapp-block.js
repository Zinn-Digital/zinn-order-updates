/**
 * Zinn® Order Updates: the `zinn-ou/whatsapp-button` block in the editor (docs/1297 §15.1).
 *
 * Plain script, no build step. Strings arrive translated from PHP (`window.zinnOuWhatsapp`,
 * Zinn_Order_Updates_Whatsapp::editor_data()). The block is dynamic: the editor previews the
 * server's own markup, and a store with no connected number sees a notice, never a dead button.
 *
 * @package ZinnOrderUpdates
 */
( function ( wp, data ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! data ) {
		return;
	}
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'zinn-ou/whatsapp-button', {
		apiVersion: 3,
		title: data.title,
		description: data.description,
		category: 'zinn',
		icon: 'format-chat',
		attributes: {
			label: { type: 'string', default: '' },
			showTo: { type: 'string', default: 'everyone' },
		},
		supports: { html: false },
		edit: function ( props ) {
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: data.settings },
						el( c.TextControl, {
							label: data.label,
							placeholder: data.placeholder,
							value: props.attributes.label,
							onChange: function ( v ) {
								props.setAttributes( { label: v } );
							},
						} ),
						el( c.SelectControl, {
							label: data.showTo,
							value: props.attributes.showTo,
							options: data.showOptions,
							onChange: function ( v ) {
								props.setAttributes( { showTo: v } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'zinn-ou/whatsapp-button',
					attributes: props.attributes,
					EmptyResponsePlaceholder: function () {
						return el( c.Notice, { status: 'info', isDismissible: false }, data.noNumber );
					},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.zinnOuWhatsapp );
