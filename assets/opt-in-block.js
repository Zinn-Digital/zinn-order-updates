/**
 * Zinn® Order Updates: the `zinn-ou/opt-in` block in the editor (docs/1297 §8.2, §15.1).
 *
 * Plain script, no build step. Every string arrives translated from PHP (`window.zinnOuOptIn`,
 * Zinn_Order_Updates_Optin::editor_data()), so the plugin's catalogues carry them all. The block
 * is dynamic: the editor previews the server's own markup. When PBS Pro forms are present, two
 * consent-field variations of `pbs/form-field` are added (feature-detected, never assumed).
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

	wp.blocks.registerBlockType( 'zinn-ou/opt-in', {
		apiVersion: 3,
		title: data.title,
		description: data.description,
		category: 'zinn',
		icon: 'smartphone',
		attributes: {
			channel: { type: 'string', default: 'sms' },
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
						el( c.SelectControl, {
							label: data.channel,
							value: props.attributes.channel,
							options: data.channels,
							onChange: function ( v ) {
								props.setAttributes( { channel: v } );
							},
						} )
					)
				),
				el( ServerSideRender, { block: 'zinn-ou/opt-in', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.domReady( function () {
		if ( ! data.pbs || ! data.pbs.length || ! wp.blocks.getBlockType( 'pbs/form-field' ) ) {
			return;
		}
		data.pbs.forEach( function ( v ) {
			wp.blocks.registerBlockVariation( 'pbs/form-field', v );
		} );
	} );
} )( window.wp, window.zinnOuOptIn );
