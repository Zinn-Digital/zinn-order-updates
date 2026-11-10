/**
 * Zinn® Order Updates: the `zinn-ou/preferences` block in the editor (docs/1297 §8.3).
 *
 * Plain script, no build step. Strings arrive translated from PHP (`window.zinnOuPreferences`,
 * Zinn_Order_Updates_Preferences::editor_data()). The block is dynamic: the editor previews the
 * server's own markup for the signed-in editor.
 *
 * @package ZinnOrderUpdates
 */
( function ( wp, data ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! data ) {
		return;
	}
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'zinn-ou/preferences', {
		apiVersion: 3,
		title: data.title,
		description: data.description,
		category: 'zinn',
		icon: 'email-alt',
		supports: { html: false },
		edit: function () {
			return el(
				'div',
				wp.blockEditor.useBlockProps(),
				el( ServerSideRender, { block: 'zinn-ou/preferences' } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.zinnOuPreferences );
