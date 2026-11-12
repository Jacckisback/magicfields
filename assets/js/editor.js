/**
 * MagicFields — block editor integration.
 *
 * - Field groups: values live in the post entity (REST field "magicfields"), so
 *   they are saved, undone and marked dirty together with the post.
 * - "Field value" block and block bindings source to display values.
 */
( function ( wp ) {
	'use strict';

	const data = Object.assign( { groups: [], fields: [], blocks: [], blockGroups: [] }, window.magicfieldsEditor || {} );
	const GF = window.MagicFields;
	if ( ! GF ) {
		return;
	}
	const { createElement: h, useEffect, createPortal, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { useSelect, useDispatch } = wp.data;
	const C = wp.components;
	const TD = GF.TD;
	const SOURCE = 'magicfields/field';

	const fieldByName = {};
	data.fields.forEach( ( f ) => ( fieldByName[ f.name ] = f ) );
	const fieldOptions = [ { value: '', label: __( '— Select a field —', TD ) } ].concat(
		data.fields.map( ( f ) => ( { value: f.name, label: f.label + ' (' + f.group + ')' } ) )
	);

	/* ---------- Field groups ---------- */

	function matches( group, info ) {
		return ( group.location || [] ).some(
			( set ) =>
				set.length > 0 &&
				set.every( ( rule ) => {
					let hit;
					switch ( rule.param ) {
						case 'post_type':
							hit = rule.value === 'all' || rule.value === info.postType;
							break;
						case 'page_template':
							hit = rule.value === info.template;
							break;
						case 'post':
							hit = Number( rule.value ) > 0 && Number( rule.value ) === Number( info.postId );
							break;
						default:
							return false;
					}
					return rule.operator === '!=' ? ! hit : hit;
				} )
		);
	}

	function Groups() {
		const info = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				values: editor.getEditedPostAttribute( 'magicfields' ),
				template: editor.getEditedPostAttribute( 'template' ) || 'default',
				postType: editor.getCurrentPostType(),
				postId: editor.getCurrentPostId(),
			};
		}, [] );
		const { editPost, lockPostSaving, unlockPostSaving } = useDispatch( 'core/editor' );
		const values = info.values || {};
		const visible = data.groups.filter( ( group ) => matches( group, info ) );

		const onChange = ( name, value ) => {
			// Read the latest edits: two controls may change in the same tick.
			const latest = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'magicfields' ) || {};
			editPost( { magicfields: Object.assign( {}, latest, { [ name ]: value } ) } );
		};

		const missing = visible.reduce( ( all, group ) => all.concat( GF.validate( group.fields, values ) ), [] );
		const blocked = missing.length > 0 && info.values !== undefined;
		useEffect( () => {
			if ( blocked ) {
				lockPostSaving( 'magicfields' );
			} else {
				unlockPostSaving( 'magicfields' );
			}
		}, [ blocked ] );

		// Meta boxes of groups whose rules stop matching (template change) are hidden.
		const visibleKeys = visible.map( ( g ) => g.key ).join( ',' );
		useEffect( () => {
			data.groups.forEach( ( group ) => {
				const box = document.getElementById( 'magicfields-' + group.key );
				if ( box ) {
					box.style.display = visible.includes( group ) ? '' : 'none';
				}
			} );
		}, [ visibleKeys ] );

		const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

		return h(
			Fragment,
			null,
			visible.map( ( group ) => {
				const content = h( GF.GroupFields, { group, values, onChange, showMissing: true } );
				if ( group.position === 'side' ) {
					return Panel ? h( Panel, { key: group.key, name: 'magicfields-' + group.key, title: group.title }, content ) : null;
				}
				const node = document.getElementById( 'magicfields-mount-' + group.key );
				return node ? createPortal( content, node, group.key ) : null;
			} )
		);
	}

	/* ---------- Displaying values ---------- */

	function textOf( field, raw ) {
		if ( GF.isEmpty( raw ) ) {
			return '';
		}
		if ( Array.isArray( raw ) ) {
			return raw.join( ', ' );
		}
		if ( typeof raw === 'object' ) {
			return raw.title || raw.url || '';
		}
		if ( field && field.type === 'true_false' ) {
			return Number( raw ) ? __( 'Yes', TD ) : __( 'No', TD );
		}
		return String( raw );
	}

	function registerBindings() {
		if ( ! wp.blocks.registerBlockBindingsSource ) {
			return;
		}
		try {
			wp.blocks.registerBlockBindingsSource( {
				name: SOURCE,
				label: __( 'Custom field (MagicFields)', TD ),
				usesContext: [ 'postId', 'postType' ],
				getValues( { select, bindings } ) {
					const editor = select( 'core/editor' );
					const values = ( editor && editor.getEditedPostAttribute( 'magicfields' ) ) || {};
					const out = {};
					Object.keys( bindings ).forEach( ( attribute ) => {
						const key = bindings[ attribute ].args && bindings[ attribute ].args.key;
						const field = fieldByName[ key ];
						const raw = values[ key ];
						if ( field && [ 'image', 'file' ].includes( field.type ) ) {
							const media = raw ? select( 'core' ).getMedia( Number( raw ), { context: 'view' } ) : null;
							out[ attribute ] = attribute === 'id' ? Number( raw ) || undefined : attribute === 'alt' ? ( media && media.alt_text ) || '' : ( media && media.source_url ) || '';
						} else if ( field && field.type === 'link' && raw && typeof raw === 'object' ) {
							out[ attribute ] = [ 'url', 'href' ].includes( attribute ) ? raw.url : raw.title || raw.url;
						} else {
							out[ attribute ] = textOf( field, raw ) || ( field ? field.label : key );
						}
					} );
					return out;
				},
				canUserEditValue: () => false,
				getFieldsList() {
					return data.fields.map( ( f ) => ( {
						label: f.label,
						type: 'string',
						args: { key: f.name },
					} ) );
				},
			} );
		} catch ( e ) {
			// The source may already be fully registered.
		}
	}

	function registerBlock() {
		const { InspectorControls, useBlockProps } = wp.blockEditor;
		const ServerSideRender = wp.serverSideRender;
		wp.blocks.registerBlockType( 'magicfields/field', {
			edit( { attributes, setAttributes, context } ) {
				const blockProps = useBlockProps();
				const field = fieldByName[ attributes.name ];
				const live = useSelect(
					( select ) => {
						const editor = select( 'core/editor' );
						if ( ! editor || attributes.source === 'option' || attributes.postId ) {
							return undefined;
						}
						const values = editor.getEditedPostAttribute( 'magicfields' );
						return values ? values[ attributes.name ] : undefined;
					},
					[ attributes.name, attributes.source, attributes.postId ]
				);
				const simple = field && ! [ 'flexible', 'image', 'gallery', 'file', 'wysiwyg', 'post_object', 'taxonomy', 'user', 'select', 'checkbox', 'radio', 'button_group', 'date', 'datetime', 'time', 'email', 'url', 'link', 'textarea' ].includes( field.type );
				let preview;
				if ( ! attributes.name ) {
					preview = h( C.Placeholder, { icon: 'feedback', label: __( 'Field value', TD ), instructions: __( 'Choose the field to display.', TD ) },
						h( C.SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							value: '',
							options: fieldOptions,
							onChange: ( name ) => setAttributes( { name } ),
						} )
					);
				} else if ( simple && live !== undefined ) {
					preview = textOf( field, live ) || h( 'span', { className: 'magicfields-block-empty' }, '[' + ( field ? field.label : attributes.name ) + ']' );
				} else {
					preview = h( ServerSideRender, {
						block: 'magicfields/field',
						attributes: Object.assign( {}, attributes, { postId: attributes.postId || Number( context.postId ) || 0 } ),
						EmptyResponsePlaceholder: () => h( 'span', { className: 'magicfields-block-empty' }, '[' + ( field ? field.label : attributes.name ) + ']' ),
					} );
				}
				return h(
					Fragment,
					null,
					h(
						InspectorControls,
						null,
						h(
							C.PanelBody,
							{ title: __( 'Field', TD ) },
							h( C.SelectControl, {
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								label: __( 'Field', TD ),
								value: attributes.name,
								options: fieldOptions,
								onChange: ( name ) => setAttributes( { name } ),
							} ),
							h( C.SelectControl, {
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								label: __( 'Read the value from', TD ),
								value: attributes.source,
								options: [
									{ value: 'post', label: __( 'The current post', TD ) },
									{ value: 'option', label: __( 'An options page', TD ) },
								],
								onChange: ( source ) => setAttributes( { source } ),
							} ),
							h( C.SelectControl, {
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								label: __( 'HTML element', TD ),
								value: attributes.tag,
								options: [ 'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( ( t ) => ( { value: t, label: t } ) ),
								onChange: ( tag ) => setAttributes( { tag } ),
							} )
						)
					),
					// Text previews use the chosen element; server previews bring their own markup.
					h( attributes.name && simple && live !== undefined ? attributes.tag || 'div' : 'div', blockProps, preview )
				);
			},
			save: () => null,
		} );
	}

	/* ---------- Fields as blocks: a field group used as a block ---------- */

	function registerCustomBlocks() {
		const { InspectorControls, useBlockProps } = wp.blockEditor;
		const ServerSideRender = wp.serverSideRender;
		data.blocks.forEach( ( { name, group } ) => {
			if ( wp.blocks.getBlockType( name ) ) {
				return;
			}
			wp.blocks.registerBlockType( name, {
				edit( { attributes, setAttributes } ) {
					const blockProps = useBlockProps();
					const values = Object.assign( GF.defaultsFor( group.fields ), attributes.data || {} );
					return h(
						Fragment,
						null,
						h(
							InspectorControls,
							null,
							h(
								C.PanelBody,
								{ title: group.title, initialOpen: true },
								h(
									'div',
									{ className: 'magicfields-inspector' },
									h( GF.GroupFields, {
										group: Object.assign( {}, group, { position: 'side' } ),
										values,
										showMissing: true,
										onChange: ( field, value ) => setAttributes( { data: Object.assign( {}, values, { [ field ]: value } ) } ),
									} )
								)
							)
						),
						h(
							'div',
							blockProps,
							h( ServerSideRender, {
								block: name,
								attributes: { data: values },
								httpMethod: 'POST',
								EmptyResponsePlaceholder: () =>
									h( C.Placeholder, { icon: group.block.icon, label: group.title, instructions: __( 'Fill in the fields in the block settings.', TD ) } ),
							} )
						)
					);
				},
				save: () => null,
			} );
		} );
	}

	/* ---------- Fields on blocks: extra fields in the settings of existing blocks ---------- */

	function groupsForBlock( name ) {
		return data.blockGroups.filter( ( group ) =>
			( group.location || [] ).some(
				( set ) =>
					set.length > 0 &&
					set.every( ( rule ) => {
						if ( rule.param !== 'block' ) {
							return false;
						}
						const hit = rule.value === 'all' || rule.value === name;
						return rule.operator === '!=' ? ! hit : hit;
					} )
			)
		);
	}

	if ( data.blockGroups.length && wp.hooks && wp.compose ) {
		// Registered right away: block types are filtered as they register.
		wp.hooks.addFilter( 'blocks.registerBlockType', 'magicfields/attribute', ( settings, name ) => {
			if ( name.indexOf( 'magicfields/' ) === 0 || ! groupsForBlock( name ).length ) {
				return settings;
			}
			return Object.assign( {}, settings, { attributes: Object.assign( {}, settings.attributes, { magicfields: { type: 'object' } } ) } );
		} );
		wp.hooks.addFilter(
			'editor.BlockEdit',
			'magicfields/block-fields',
			wp.compose.createHigherOrderComponent(
				( BlockEdit ) => ( props ) => {
					const groups = props.isSelected ? groupsForBlock( props.name ) : [];
					if ( ! groups.length ) {
						return h( BlockEdit, props );
					}
					const { InspectorControls } = wp.blockEditor;
					const stored = props.attributes.magicfields || {};
					return h(
						Fragment,
						null,
						h( BlockEdit, props ),
						h(
							InspectorControls,
							null,
							groups.map( ( group ) =>
								h(
									C.PanelBody,
									{ key: group.key, title: group.title, initialOpen: false },
									h(
										'div',
										{ className: 'magicfields-inspector' },
										h( GF.GroupFields, {
											group: Object.assign( {}, group, { position: 'side' } ),
											values: Object.assign( GF.defaultsFor( group.fields ), stored ),
											onChange: ( field, value ) => props.setAttributes( { magicfields: Object.assign( {}, stored, { [ field ]: value } ) } ),
										} )
									)
								)
							)
						)
					);
				},
				'withMagicFields'
			)
		);
	}

	wp.domReady( () => {
		if ( wp.blocks && wp.blockEditor && ! wp.blocks.getBlockType( 'magicfields/field' ) ) {
			registerBlock();
		}
		if ( wp.blocks && wp.blockEditor ) {
			registerCustomBlocks();
		}
		if ( wp.blocks ) {
			registerBindings();
		}
		if ( data.groups.length && wp.plugins && wp.data.select( 'core/editor' ) ) {
			wp.plugins.registerPlugin( 'magicfields', { render: Groups } );
			// Recent editors keep the meta box pane closed until the user opens it:
			// open it once so the fields are visible, then respect the user's choice.
			const prefs = wp.data.select( 'core/preferences' );
			if (
				prefs &&
				data.groups.some( ( g ) => g.position !== 'side' ) &&
				prefs.get( 'core/edit-post', 'metaBoxesMainIsOpen' ) === undefined
			) {
				wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'metaBoxesMainIsOpen', true );
				if ( prefs.get( 'core/edit-post', 'metaBoxesMainOpenHeight' ) === undefined ) {
					// Leave the content visible above the fields.
					wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'metaBoxesMainOpenHeight', Math.round( window.innerHeight * 0.4 ) );
				}
			}
		}
	} );
} )( window.wp );
