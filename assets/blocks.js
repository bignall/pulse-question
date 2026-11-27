/* Pulse Question blocks: Question of the day, Past questions. Rendered on the server. */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ToggleControl, RangeControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'pulse-question/today', {
		apiVersion: 3,
		title: __( 'Question of the day', 'pulse-question' ),
		description: __( "Today's question, large, with its background text.", 'pulse-question' ),
		icon: 'format-chat',
		category: 'widgets',
		attributes: { background: { type: 'boolean', default: true }, answers: { type: 'number', default: -1 } },
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'pulse-question' ) },
						el( ToggleControl, {
							label: __( 'Show background text', 'pulse-question' ),
							checked: attributes.background,
							onChange: ( v ) => setAttributes( { background: v } ),
						} ),
						el( ToggleControl, {
							label: __( 'Top answers: use the number in Settings', 'pulse-question' ),
							checked: attributes.answers < 0,
							onChange: ( v ) => setAttributes( { answers: v ? -1 : 3 } ),
						} ),
						attributes.answers >= 0 &&
							el( RangeControl, {
								label: __( 'Top answers to show', 'pulse-question' ),
								value: attributes.answers,
								min: 0,
								max: 10,
								onChange: ( v ) => setAttributes( { answers: v ?? 0 } ),
							} )
					)
				),
				el( ServerSideRender, { block: 'pulse-question/today', attributes } )
			);
		},
		save: () => null,
	} );

	wp.blocks.registerBlockType( 'pulse-question/past', {
		apiVersion: 3,
		title: __( 'Past questions', 'pulse-question' ),
		description: __( 'Cards of the questions asked before today, newest first.', 'pulse-question' ),
		icon: 'grid-view',
		category: 'widgets',
		attributes: { count: { type: 'number', default: 0 }, paged: { type: 'boolean', default: true } },
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'pulse-question' ) },
						el( RangeControl, {
							label: __( 'Questions per page (0 = the setting)', 'pulse-question' ),
							value: attributes.count,
							min: 0,
							max: 60,
							onChange: ( v ) => setAttributes( { count: v || 0 } ),
						} ),
						el( ToggleControl, {
							label: __( 'Page links to older questions', 'pulse-question' ),
							checked: attributes.paged,
							onChange: ( v ) => setAttributes( { paged: v } ),
						} )
					)
				),
				el( ServerSideRender, { block: 'pulse-question/past', attributes } )
			);
		},
		save: () => null,
	} );
} )( window.wp );
