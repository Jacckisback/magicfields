/**
 * MagicFields — admin app: field group builder, options pages, tools.
 */
( function ( wp ) {
	'use strict';

	const cfg = window.magicfieldsBuilder;
	const GF = window.MagicFields;
	if ( ! cfg || ! GF ) {
		return;
	}
	const { createElement: h, useState, useEffect, useRef, Fragment } = wp.element;
	const C = wp.components;
	const { __, sprintf, _n } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const TD = GF.TD;

	const TYPES = cfg.types;
	const newKey = ( prefix ) => prefix + '_' + Math.random().toString( 36 ).slice( 2, 10 ) + Date.now().toString( 36 ).slice( -4 );
	const slugify = ( text ) =>
		String( text || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9_-]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
	const clone = ( x ) => JSON.parse( JSON.stringify( x ) );

	// Groups known to the app, for the clone field picker.
	let knownGroups = [];

	/** Applies fn to a field and to every field nested in it (sub fields, layouts). */
	function mapDeep( field, fn ) {
		const out = fn( Object.assign( {}, field ) );
		if ( out.sub_fields ) {
			out.sub_fields = out.sub_fields.map( ( f ) => mapDeep( f, fn ) );
		}
		if ( out.layouts ) {
			out.layouts = out.layouts.map( ( l ) => Object.assign( {}, l, { sub_fields: ( l.sub_fields || [] ).map( ( f ) => mapDeep( f, fn ) ) } ) );
		}
		return out;
	}

	const LOCATION_PARAMS = [
		{ value: 'post_type', label: __( 'Post type', TD ) },
		{ value: 'page_template', label: __( 'Page template', TD ) },
		{ value: 'post', label: __( 'Specific post (ID)', TD ) },
		{ value: 'taxonomy', label: __( 'Taxonomy term', TD ) },
		{ value: 'user_role', label: __( 'User', TD ) },
		{ value: 'options_page', label: __( 'Options page', TD ) },
		{ value: 'block', label: __( 'Block', TD ) },
	];

	function locationValues( param, pages ) {
		const all = { value: 'all', label: __( 'All', TD ) };
		switch ( param ) {
			case 'post_type':
				return [ all ].concat( cfg.postTypes );
			case 'page_template':
				return cfg.templates;
			case 'taxonomy':
				return [ all ].concat( cfg.taxonomies );
			case 'user_role':
				return [ { value: 'all', label: __( 'All users', TD ) } ].concat( cfg.roles );
			case 'options_page':
				return pages.map( ( p ) => ( { value: p.slug, label: p.title } ) );
			case 'block':
				return [ { value: 'all', label: __( 'All blocks', TD ) } ].concat( cfg.blockTypes );
			default:
				return null;
		}
	}

	function newField( type ) {
		const field = { key: newKey( 'field' ), label: '', name: '', type: type || 'text', instructions: '', required: false, width: 0, conditions: [], _open: true, _auto: true };
		( TYPES[ field.type ].settings || [] ).forEach( ( s ) => {
			if ( s.default !== undefined ) {
				field[ s.name ] = s.default;
			}
		} );
		if ( TYPES[ field.type ].nested ) {
			field.sub_fields = [];
		}
		if ( TYPES[ field.type ].layouts ) {
			field.layouts = [];
		}
		return field;
	}

	/** Drops the editor-only flags (_open, _auto) before saving. */
	function cleanFields( fields ) {
		return ( fields || [] ).map( ( field ) =>
			mapDeep( field, ( f ) => {
				Object.keys( f ).forEach( ( k ) => k[ 0 ] === '_' && delete f[ k ] );
				return f;
			} )
		);
	}

	/** Groups and top-level fields a clone field can point at. */
	function CloneControl( { value, onChange } ) {
		const list = value || [];
		const options = [];
		knownGroups.forEach( ( g ) => {
			/* translators: %s: field group title */
			options.push( { value: g.key, label: sprintf( __( 'All fields of "%s"', TD ), g.title ) } );
			( g.fields || [] ).forEach( ( f ) => {
				if ( f.type !== 'clone' && f.type !== 'tab' ) {
					options.push( { value: f.key, label: g.title + ' › ' + ( f.label || f.name ) } );
				}
			} );
		} );
		if ( ! options.length ) {
			return h( 'p', { className: 'description' }, __( 'Save another field group first, then pick it here.', TD ) );
		}
		return h(
			'div',
			{ className: 'gfb-multicheck' },
			options.map( ( o ) =>
				h( C.CheckboxControl, {
					key: o.value,
					__nextHasNoMarginBottom: true,
					label: o.label,
					checked: list.includes( o.value ),
					onChange: ( on ) => onChange( on ? list.concat( o.value ) : list.filter( ( v ) => v !== o.value ) ),
				} )
			)
		);
	}

	/** Layouts of a flexible content field: each is a named set of sub fields. */
	function LayoutsEditor( { layouts, onChange, depth } ) {
		const set = ( i, patch ) => onChange( layouts.map( ( l, x ) => ( x === i ? Object.assign( {}, l, patch ) : l ) ) );
		return h(
			'div',
			null,
			layouts.length === 0 && h( 'p', { className: 'gfb-empty' }, __( 'No layouts yet. A layout is a kind of row the editor can add.', TD ) ),
			layouts.map( ( layout, i ) =>
				h(
					'div',
					{ key: layout.key, className: 'gfb-layout' },
					h(
						'div',
						{ className: 'gfb-layout__head' },
						h( Text, { value: layout.label, placeholder: __( 'Layout label', TD ), 'aria-label': __( 'Layout label', TD ), onChange: ( v ) => set( i, layout._auto ? { label: v, name: slugify( v ).replace( /-/g, '_' ) } : { label: v } ) } ),
						h( Text, { value: layout.name, placeholder: __( 'Name', TD ), 'aria-label': __( 'Name', TD ), className: 'components-text-control__input gfb-mono', onChange: ( v ) => set( i, { name: slugify( v ), _auto: false } ) } ),
						h( C.Button, { size: 'small', icon: 'arrow-up-alt2', label: __( 'Move up', TD ), disabled: i === 0, onClick: () => { const n = layouts.slice(); n.splice( i - 1, 0, n.splice( i, 1 )[ 0 ] ); onChange( n ); } } ),
						h( C.Button, { size: 'small', icon: 'arrow-down-alt2', label: __( 'Move down', TD ), disabled: i === layouts.length - 1, onClick: () => { const n = layouts.slice(); n.splice( i + 1, 0, n.splice( i, 1 )[ 0 ] ); onChange( n ); } } ),
						h( C.Button, { size: 'small', icon: 'trash', label: __( 'Delete', TD ), isDestructive: true, onClick: () => onChange( layouts.filter( ( l, x ) => x !== i ) ) } )
					),
					h( FieldsEditor, { fields: layout.sub_fields || [], onChange: ( sub_fields ) => set( i, { sub_fields } ), depth: depth + 1 } )
				)
			),
			h( C.Button, { variant: 'secondary', icon: 'plus-alt2', onClick: () => onChange( layouts.concat( [ { key: newKey( 'layout' ), label: '', name: '', sub_fields: [], _auto: true } ] ) ) }, __( 'Add layout', TD ) )
		);
	}

	/* ---------- Small form helpers ---------- */

	function Row( { label, help, children, wide } ) {
		return h(
			'div',
			{ className: 'gfb-row' + ( wide ? ' gfb-row--wide' : '' ) },
			h( 'div', { className: 'gfb-row__label' }, h( 'strong', null, label ), help && h( 'p', null, help ) ),
			h( 'div', { className: 'gfb-row__control' }, children )
		);
	}

	const Text = ( props ) => h( 'input', Object.assign( { type: 'text', className: 'components-text-control__input' }, props, { onChange: ( e ) => props.onChange( e.target.value ) } ) );
	const Select = ( { value, options, onChange, ...rest } ) =>
		h(
			'select',
			Object.assign( { className: 'components-select-control__input gfb-select', value, onChange: ( e ) => onChange( e.target.value ) }, rest ),
			options.map( ( o ) => h( 'option', { key: o.value, value: o.value }, o.label ) )
		);

	function MultiCheck( { options, value, onChange } ) {
		const list = value || [];
		return h(
			'div',
			{ className: 'gfb-multicheck' },
			options.map( ( o ) =>
				h( C.CheckboxControl, {
					key: o.value,
					__nextHasNoMarginBottom: true,
					label: o.label,
					checked: list.includes( o.value ),
					onChange: ( on ) => onChange( on ? list.concat( o.value ) : list.filter( ( v ) => v !== o.value ) ),
				} )
			)
		);
	}

	/** Choices are edited as text, one per line: "value : Label". */
	function ChoicesControl( { value, onChange } ) {
		const toText = ( list ) => ( list || [] ).map( ( c ) => ( c.value === c.label ? c.value : c.value + ' : ' + c.label ) ).join( '\n' );
		const [ text, setText ] = useState( () => toText( value ) );
		return h( 'textarea', {
			className: 'components-textarea-control__input',
			rows: 5,
			value: text,
			placeholder: 'red : Red\nblue : Blue',
			onChange: ( e ) => {
				setText( e.target.value );
				onChange(
					e.target.value
						.split( /\r?\n/ )
						.map( ( line ) => {
							const parts = line.split( ' : ' );
							const v = parts[ 0 ].trim();
							return { value: v, label: ( parts.slice( 1 ).join( ' : ' ) || v ).trim() };
						} )
						.filter( ( c ) => c.value !== '' )
				);
			},
		} );
	}

	function SettingControl( { setting, value, onChange } ) {
		switch ( setting.control ) {
			case 'toggle':
				return h( C.ToggleControl, { __nextHasNoMarginBottom: true, label: '', 'aria-label': setting.label, checked: !! value, onChange } );
			case 'number':
				return h( 'input', { type: 'number', step: 'any', className: 'components-text-control__input gfb-short', value: value === undefined || value === null ? '' : value, onChange: ( e ) => onChange( e.target.value ) } );
			case 'textarea':
				return h( 'textarea', { className: 'components-textarea-control__input', rows: 3, value: value || '', onChange: ( e ) => onChange( e.target.value ) } );
			case 'select':
				return h( Select, { value: value || setting.default || '', options: Object.keys( setting.options ).map( ( k ) => ( { value: k, label: setting.options[ k ] } ) ), onChange } );
			case 'choices':
				return h( ChoicesControl, { value, onChange } );
			case 'clone':
				return h( CloneControl, { value, onChange } );
			case 'post_types':
				return h( MultiCheck, { options: cfg.postTypes, value, onChange } );
			case 'roles':
				return h( MultiCheck, { options: cfg.roles, value, onChange } );
			case 'taxonomy':
				return h( Select, { value: value || 'category', options: cfg.taxonomies, onChange } );
			default:
				return h( Text, { value: value === undefined || value === null ? '' : value, onChange } );
		}
	}

	/* ---------- Field editor ---------- */

	function typeOptions() {
		const groups = {};
		Object.keys( TYPES ).forEach( ( name ) => {
			const cat = TYPES[ name ].category;
			( groups[ cat ] = groups[ cat ] || [] ).push( h( 'option', { key: name, value: name }, TYPES[ name ].label ) );
		} );
		return Object.keys( groups ).map( ( cat ) => h( 'optgroup', { key: cat, label: cat }, groups[ cat ] ) );
	}

	function Conditions( { field, siblings, onChange } ) {
		const rules = field.conditions || [];
		const targets = siblings.filter( ( s ) => s.key !== field.key && GF.hasValue( s ) && s.name && ! [ 'repeater', 'flexible', 'group' ].includes( s.type ) );
		const ops = [
			{ value: '==', label: __( 'is equal to', TD ) },
			{ value: '!=', label: __( 'is not equal to', TD ) },
			{ value: 'contains', label: __( 'contains', TD ) },
			{ value: 'empty', label: __( 'is empty', TD ) },
			{ value: 'not_empty', label: __( 'has a value', TD ) },
		];
		const set = ( i, patch ) => onChange( rules.map( ( r, x ) => ( x === i ? Object.assign( {}, r, patch ) : r ) ) );
		if ( ! targets.length ) {
			return h( 'p', { className: 'description' }, __( 'Add another field at this level to show this one conditionally.', TD ) );
		}
		return h(
			'div',
			{ className: 'gfb-rules' },
			rules.map( ( rule, i ) => {
				const target = targets.find( ( t ) => t.name === rule.field );
				const choices = target && target.choices && target.choices.length ? target.choices : target && target.type === 'true_false' ? [ { value: '1', label: __( 'On', TD ) }, { value: '0', label: __( 'Off', TD ) } ] : null;
				return h(
					'div',
					{ key: i, className: 'gfb-rule' },
					h( Select, { value: rule.field, options: targets.map( ( t ) => ( { value: t.name, label: t.label || t.name } ) ), onChange: ( v ) => set( i, { field: v } ), 'aria-label': __( 'Field', TD ) } ),
					h( Select, { value: rule.operator, options: ops, onChange: ( v ) => set( i, { operator: v } ), 'aria-label': __( 'Condition', TD ) } ),
					[ 'empty', 'not_empty' ].includes( rule.operator )
						? null
						: choices
						? h( Select, { value: rule.value, options: [ { value: '', label: '—' } ].concat( choices.map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) ), onChange: ( v ) => set( i, { value: v } ), 'aria-label': __( 'Value', TD ) } )
						: h( Text, { value: rule.value, onChange: ( v ) => set( i, { value: v } ), 'aria-label': __( 'Value', TD ) } ),
					h( C.Button, { icon: 'no-alt', label: __( 'Remove rule', TD ), onClick: () => onChange( rules.filter( ( r, x ) => x !== i ) ) } )
				);
			} ),
			h(
				C.Button,
				{ variant: 'tertiary', onClick: () => onChange( rules.concat( [ { field: targets[ 0 ].name, operator: '==', value: '' } ] ) ) },
				rules.length ? __( 'And…', TD ) : __( 'Add a condition', TD )
			)
		);
	}

	function FieldEditor( { field, siblings, onChange, onRemove, onDuplicate, onMove, index, count, drag, depth } ) {
		const type = TYPES[ field.type ] || TYPES.text;
		const set = ( patch ) => onChange( Object.assign( {}, field, patch ) );
		const [ tab, setTab ] = useState( 'general' );
		const valued = type.has_value;
		const dupe = valued && field.name && siblings.some( ( s ) => s.key !== field.key && s.name === field.name && GF.hasValue( s ) );

		const setLabel = ( label ) => set( field._auto ? { label, name: slugify( label ).replace( /-/g, '_' ) } : { label } );
		const setType = ( next ) => {
			const patch = { type: next };
			( TYPES[ next ].settings || [] ).forEach( ( s ) => {
				if ( field[ s.name ] === undefined && s.default !== undefined ) {
					patch[ s.name ] = s.default;
				}
			} );
			if ( TYPES[ next ].nested && ! field.sub_fields ) {
				patch.sub_fields = [];
			}
			if ( TYPES[ next ].layouts && ! field.layouts ) {
				patch.layouts = [];
			}
			set( patch );
		};

		const tabs = [ { id: 'general', label: __( 'General', TD ) } ];
		if ( valued ) {
			tabs.push( { id: 'presentation', label: __( 'Presentation', TD ) } );
		}
		tabs.push( { id: 'conditions', label: __( 'Conditions', TD ) + ( ( field.conditions || [] ).length ? ' (' + field.conditions.length + ')' : '' ) } );
		if ( valued && depth === 0 ) {
			tabs.push( { id: 'code', label: __( 'Display', TD ) } );
		}

		return h(
			'div',
			{
				className: 'gfb-field' + ( field._open ? ' is-open' : '' ) + ( drag.over === index ? ' is-drop' : '' ),
				onDragOver: ( e ) => {
					if ( drag.active() ) {
						e.preventDefault();
						e.stopPropagation();
						drag.setOver( index );
					}
				},
				onDrop: ( e ) => {
					if ( drag.active() ) {
						e.preventDefault();
						e.stopPropagation();
						drag.drop( index );
					}
				},
			},
			h(
				'div',
				{ className: 'gfb-field__head' },
				h( 'span', {
					className: 'gfb-field__handle dashicons dashicons-menu',
					draggable: true,
					title: __( 'Drag to reorder', TD ),
					onDragStart: ( e ) => {
						e.stopPropagation();
						e.dataTransfer.effectAllowed = 'move';
						e.dataTransfer.setData( 'text/plain', field.key );
						drag.start( index );
					},
					onDragEnd: drag.end,
				} ),
				h(
					'button',
					{ type: 'button', className: 'gfb-field__title', 'aria-expanded': !! field._open, onClick: () => set( { _open: ! field._open } ) },
					h( 'span', { className: 'gfb-field__label' }, field.label || __( '(no label)', TD ) ),
					valued && h( 'code', null, field.name || '—' ),
					h( 'span', { className: 'gfb-field__type' }, type.label ),
					field.required && h( 'span', { className: 'gfb-badge' }, __( 'Required', TD ) )
				),
				h( C.Button, { size: 'small', icon: 'arrow-up-alt2', label: __( 'Move up', TD ), disabled: index === 0, onClick: () => onMove( -1 ) } ),
				h( C.Button, { size: 'small', icon: 'arrow-down-alt2', label: __( 'Move down', TD ), disabled: index === count - 1, onClick: () => onMove( 1 ) } ),
				h( C.Button, { size: 'small', icon: 'admin-page', label: __( 'Duplicate', TD ), onClick: onDuplicate } ),
				h( C.Button, { size: 'small', icon: 'trash', label: __( 'Delete', TD ), isDestructive: true, onClick: onRemove } )
			),
			field._open &&
				h(
					'div',
					{ className: 'gfb-field__body' },
					h(
						'div',
						{ className: 'gfb-subtabs', role: 'tablist' },
						tabs.map( ( t ) => h( 'button', { key: t.id, type: 'button', role: 'tab', 'aria-selected': tab === t.id, className: tab === t.id ? 'is-active' : '', onClick: () => setTab( t.id ) }, t.label ) )
					),
					tab === 'general' &&
						h(
							Fragment,
							null,
							h( Row, { label: __( 'Type', TD ) }, h( 'select', { className: 'components-select-control__input gfb-select', value: field.type, onChange: ( e ) => setType( e.target.value ) }, typeOptions() ) ),
							h( Row, { label: __( 'Label', TD ) }, h( Text, { value: field.label, onChange: setLabel, autoFocus: ! field.label } ) ),
							( valued || field.type === 'clone' ) &&
								h(
									Row,
									{ label: __( 'Name', TD ), help: __( 'Used in code and as the meta key. Lowercase letters, numbers and underscores.', TD ) },
									h( Text, { value: field.name, onChange: ( v ) => set( { name: slugify( v ), _auto: false } ), className: 'components-text-control__input gfb-mono' } ),
									dupe && h( 'p', { className: 'gfb-error' }, __( 'Another field at this level already uses this name.', TD ) )
								),
							type.settings.map( ( s ) => h( Row, { key: s.name, label: s.label, help: s.help }, h( SettingControl, { setting: s, value: field[ s.name ], onChange: ( v ) => set( { [ s.name ]: v } ) } ) ) ),
							type.nested &&
								h(
									Row,
									{ label: __( 'Sub fields', TD ), wide: true },
									h( FieldsEditor, { fields: field.sub_fields || [], onChange: ( sub_fields ) => set( { sub_fields } ), depth: depth + 1 } )
								),
							type.layouts &&
								h(
									Row,
									{ label: __( 'Layouts', TD ), wide: true },
									h( LayoutsEditor, { layouts: field.layouts || [], onChange: ( layouts ) => set( { layouts } ), depth } )
								)
						),
					tab === 'presentation' &&
						h(
							Fragment,
							null,
							h( Row, { label: __( 'Instructions', TD ), help: __( 'Shown to the person filling in the field.', TD ) }, h( 'textarea', { className: 'components-textarea-control__input', rows: 2, value: field.instructions || '', onChange: ( e ) => set( { instructions: e.target.value } ) } ) ),
							h( Row, { label: __( 'Required', TD ) }, h( C.ToggleControl, { __nextHasNoMarginBottom: true, label: '', 'aria-label': __( 'Required', TD ), checked: !! field.required, onChange: ( required ) => set( { required } ) } ) ),
							h(
								Row,
								{ label: __( 'Width', TD ), help: __( 'Percentage of the row, to place fields side by side. Empty for full width.', TD ) },
								h( 'input', { type: 'number', min: 10, max: 100, step: 5, className: 'components-text-control__input gfb-short', value: field.width || '', onChange: ( e ) => set( { width: Number( e.target.value ) || 0 } ) } )
							)
						),
					tab === 'conditions' && h( Row, { label: __( 'Show this field if', TD ), help: __( 'All conditions must be met.', TD ) }, h( Conditions, { field, siblings, onChange: ( conditions ) => set( { conditions } ) } ) ),
					tab === 'code' &&
						h(
							Fragment,
							null,
							h( Row, { label: __( 'Block', TD ) }, h( 'p', null, __( 'Insert the "Field value" block and pick this field, or connect a Paragraph, Heading, Button or Image block to it from the block Attributes panel.', TD ) ) ),
							h( Row, { label: __( 'Shortcode', TD ) }, h( 'code', { className: 'gfb-code' }, '[magicfield name="' + field.name + '"]' ) ),
							h(
								Row,
								{ label: 'PHP' },
								h( 'code', { className: 'gfb-code' }, "<?php magicfields_the( '" + field.name + "' ); ?>" ),
								h( 'code', { className: 'gfb-code' }, "<?php $value = magicfields_get( '" + field.name + "' ); ?>" )
							)
						)
				)
		);
	}

	function FieldsEditor( { fields, onChange, depth } ) {
		const from = useRef( null );
		const [ over, setOver ] = useState( null );
		const drag = {
			over,
			active: () => from.current !== null,
			start: ( i ) => ( from.current = i ),
			end: () => {
				from.current = null;
				setOver( null );
			},
			setOver: ( i ) => over !== i && setOver( i ),
			drop: ( to ) => {
				const f = from.current;
				from.current = null;
				setOver( null );
				if ( f === null || f === to ) {
					return;
				}
				const next = fields.slice();
				const [ item ] = next.splice( f, 1 );
				next.splice( to, 0, item );
				onChange( next );
			},
		};
		const move = ( i, d ) => {
			const next = fields.slice();
			const [ item ] = next.splice( i, 1 );
			next.splice( i + d, 0, item );
			onChange( next );
		};
		const rekey = ( field ) => mapDeep( field, ( f ) => Object.assign( f, { key: newKey( 'field' ) } ) );
		return h(
			'div',
			{ className: 'gfb-fields' + ( depth ? ' gfb-fields--nested' : '' ) },
			fields.length === 0 && h( 'p', { className: 'gfb-empty' }, depth ? __( 'No sub fields yet.', TD ) : __( 'No fields yet. Add your first field.', TD ) ),
			fields.map( ( field, i ) =>
				h( FieldEditor, {
					key: field.key,
					field,
					siblings: fields,
					index: i,
					count: fields.length,
					drag,
					depth,
					onChange: ( next ) => onChange( fields.map( ( f, x ) => ( x === i ? next : f ) ) ),
					onRemove: () => onChange( fields.filter( ( f, x ) => x !== i ) ),
					onMove: ( d ) => move( i, d ),
					onDuplicate: () => {
						const copy = rekey( clone( field ) );
						copy.name = field.name ? field.name + '_copy' : '';
						copy._auto = false;
						onChange( fields.slice( 0, i + 1 ).concat( [ copy ], fields.slice( i + 1 ) ) );
					},
				} )
			),
			h( C.Button, { variant: depth ? 'secondary' : 'primary', icon: 'plus-alt2', onClick: () => onChange( fields.concat( [ newField() ] ) ) }, depth ? __( 'Add sub field', TD ) : __( 'Add field', TD ) )
		);
	}

	/* ---------- Location rules ---------- */

	function LocationEditor( { location, onChange, pages } ) {
		const sets = location.length ? location : [];
		const blank = () => ( { param: 'post_type', operator: '==', value: 'post' } );
		const setRule = ( si, ri, patch ) =>
			onChange(
				sets.map( ( set, s ) =>
					s !== si
						? set
						: set.map( ( rule, r ) => {
								if ( r !== ri ) {
									return rule;
								}
								const next = Object.assign( {}, rule, patch );
								if ( patch.param ) {
									const opts = locationValues( patch.param, pages );
									next.value = opts ? ( opts[ 0 ] ? opts[ 0 ].value : '' ) : '';
								}
								return next;
						  } )
				)
			);
		return h(
			'div',
			{ className: 'gfb-location' },
			sets.length === 0 && h( 'p', { className: 'gfb-empty' }, __( 'This group is not shown anywhere yet.', TD ) ),
			sets.map( ( set, si ) =>
				h(
					Fragment,
					{ key: si },
					si > 0 && h( 'div', { className: 'gfb-or' }, __( 'or', TD ) ),
					h(
						'div',
						{ className: 'gfb-ruleset' },
						set.map( ( rule, ri ) => {
							const opts = locationValues( rule.param, pages );
							return h(
								'div',
								{ key: ri, className: 'gfb-rule' },
								h( 'span', { className: 'gfb-rule__and' }, ri === 0 ? __( 'Show if', TD ) : __( 'and', TD ) ),
								h( Select, { value: rule.param, options: LOCATION_PARAMS, onChange: ( v ) => setRule( si, ri, { param: v } ), 'aria-label': __( 'Rule', TD ) } ),
								h( Select, { value: rule.operator, options: [ { value: '==', label: __( 'is', TD ) }, { value: '!=', label: __( 'is not', TD ) } ], onChange: ( v ) => setRule( si, ri, { operator: v } ), 'aria-label': __( 'Condition', TD ) } ),
								opts
									? opts.length
										? h( Select, { value: rule.value, options: opts.some( ( o ) => o.value === rule.value ) ? opts : [ { value: rule.value, label: rule.value || '—' } ].concat( opts ), onChange: ( v ) => setRule( si, ri, { value: v } ), 'aria-label': __( 'Value', TD ) } )
										: h( 'em', null, __( 'Create an options page first (Options pages tab).', TD ) )
									: h( 'input', { type: 'number', min: 1, className: 'components-text-control__input gfb-short', value: rule.value, placeholder: 'ID', onChange: ( e ) => setRule( si, ri, { value: e.target.value } ) } ),
								h( C.Button, {
									icon: 'no-alt',
									label: __( 'Remove rule', TD ),
									onClick: () => onChange( sets.map( ( s, x ) => ( x === si ? s.filter( ( r, y ) => y !== ri ) : s ) ).filter( ( s ) => s.length ) ),
								} )
							);
						} ),
						h( C.Button, { variant: 'tertiary', onClick: () => onChange( sets.map( ( s, x ) => ( x === si ? s.concat( [ blank() ] ) : s ) ) ) }, __( 'And…', TD ) )
					)
				)
			),
			h( C.Button, { variant: 'secondary', onClick: () => onChange( sets.concat( [ [ blank() ] ] ) ) }, sets.length ? __( 'Or…', TD ) : __( 'Add a location', TD ) )
		);
	}

	function locationSummary( group, pages ) {
		const parts = ( group.location || [] ).map( ( set ) =>
			set
				.map( ( rule ) => {
					const param = ( LOCATION_PARAMS.find( ( p ) => p.value === rule.param ) || {} ).label || rule.param;
					const opts = locationValues( rule.param, pages );
					const value = opts ? ( opts.find( ( o ) => o.value === rule.value ) || {} ).label || rule.value : '#' + rule.value;
					return param + ( rule.operator === '!=' ? ' ≠ ' : ': ' ) + value;
				} )
				.join( ' + ' )
		);
		return parts.length ? parts.join( ' | ' ) : '—';
	}

	/* ---------- Screens ---------- */

	function Notice( { notice, onDismiss } ) {
		if ( ! notice ) {
			return null;
		}
		return h( C.Notice, { status: notice.status, onRemove: onDismiss, className: 'gfb-notice' }, notice.text, notice.list && h( 'ul', null, notice.list.map( ( l, i ) => h( 'li', { key: i }, l ) ) ) );
	}

	function countFields( fields ) {
		return ( fields || [] ).reduce( ( n, f ) => n + 1 + countFields( f.sub_fields ), 0 );
	}

	function GroupList( { groups, pages, onEdit, onNew, onDelete, onDuplicate, onToggle } ) {
		if ( groups === null ) {
			return h( C.Spinner );
		}
		if ( ! groups.length ) {
			return h(
				'div',
				{ className: 'gfb-card gfb-welcome' },
				h( 'h2', null, __( 'Create your first field group', TD ) ),
				h( 'p', null, __( 'A field group is a set of custom fields shown on a post type, a taxonomy, user profiles or an options page.', TD ) ),
				h( C.Button, { variant: 'primary', onClick: onNew }, __( 'Add field group', TD ) )
			);
		}
		return h(
			'table',
			{ className: 'wp-list-table widefat striped gfb-table' },
			h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'Title', TD ) ), h( 'th', null, __( 'Fields', TD ) ), h( 'th', null, __( 'Location', TD ) ), h( 'th', null, __( 'Status', TD ) ) ) ),
			h(
				'tbody',
				null,
				groups.map( ( g ) =>
					h(
						'tr',
						{ key: g.key },
						h(
							'td',
							null,
							g.local
								? h( 'strong', null, g.title )
								: h( 'strong', null, h( 'a', { href: '#', onClick: ( e ) => { e.preventDefault(); onEdit( g ); } }, g.title ) ),
							h(
								'div',
								{ className: 'row-actions visible' },
								g.local
									? h( 'span', null, __( 'Registered in code', TD ) )
									: h(
											Fragment,
											null,
											h( 'a', { href: '#', onClick: ( e ) => { e.preventDefault(); onEdit( g ); } }, __( 'Edit', TD ) ),
											' | ',
											h( 'a', { href: '#', onClick: ( e ) => { e.preventDefault(); onDuplicate( g ); } }, __( 'Duplicate', TD ) ),
											' | ',
											h( 'a', { href: '#', onClick: ( e ) => { e.preventDefault(); onToggle( g ); } }, g.active ? __( 'Deactivate', TD ) : __( 'Activate', TD ) ),
											' | ',
											h( 'a', { href: '#', className: 'gfb-danger', onClick: ( e ) => { e.preventDefault(); onDelete( g ); } }, __( 'Delete', TD ) )
									  )
							)
						),
						h( 'td', null, countFields( g.fields ) ),
						h( 'td', null, locationSummary( g, pages ) ),
						h( 'td', null, h( 'span', { className: 'gfb-status' + ( g.active ? ' is-active' : '' ) }, g.active ? __( 'Active', TD ) : __( 'Inactive', TD ) ) )
					)
				)
			)
		);
	}

	function GroupEditor( { initial, pages, onSaved, onBack, notify } ) {
		const [ group, setGroup ] = useState( initial );
		const [ saving, setSaving ] = useState( false );
		const [ dirty, setDirty ] = useState( false );
		const set = ( patch ) => {
			setGroup( ( g ) => Object.assign( {}, g, patch ) );
			setDirty( true );
		};

		useEffect( () => {
			const warn = ( e ) => {
				if ( dirty ) {
					e.preventDefault();
					e.returnValue = '';
				}
			};
			window.addEventListener( 'beforeunload', warn );
			return () => window.removeEventListener( 'beforeunload', warn );
		}, [ dirty ] );

		const save = () => {
			setSaving( true );
			apiFetch( { path: 'magicfields/v1/groups', method: 'POST', data: Object.assign( {}, group, { fields: cleanFields( group.fields ) } ) } )
				.then( ( saved ) => {
					// Keep which fields are open across the save.
					const open = {};
					group.fields.forEach( ( field ) => mapDeep( field, ( f ) => { open[ f.key ] = f._open; return f; } ) );
					const restore = ( list ) => ( list || [] ).map( ( field ) => mapDeep( field, ( f ) => Object.assign( f, { _open: !! open[ f.key ] } ) ) );
					setGroup( Object.assign( {}, saved, { fields: restore( saved.fields ) } ) );
					setDirty( false );
					setSaving( false );
					notify( { status: 'success', text: __( 'Field group saved.', TD ) } );
					onSaved( saved );
				} )
				.catch( ( err ) => {
					setSaving( false );
					notify( { status: 'error', text: err.message || __( 'The field group could not be saved.', TD ) } );
				} );
		};

		const back = () => {
			if ( ! dirty || window.confirm( __( 'Leave without saving your changes?', TD ) ) ) {
				onBack();
			}
		};

		return h(
			'div',
			{ className: 'gfb-editor' },
			h(
				'div',
				{ className: 'gfb-editor__main' },
				h( 'input', {
					type: 'text',
					className: 'gfb-title',
					placeholder: __( 'Field group title', TD ),
					'aria-label': __( 'Field group title', TD ),
					value: group.title,
					onChange: ( e ) => set( { title: e.target.value } ),
				} ),
				h( 'div', { className: 'gfb-card' }, h( 'h2', null, __( 'Fields', TD ) ), h( FieldsEditor, { fields: group.fields, onChange: ( fields ) => set( { fields } ), depth: 0 } ) ),
				h(
					'div',
					{ className: 'gfb-card' },
					h( 'h2', null, __( 'Location', TD ) ),
					h( 'p', { className: 'description' }, __( 'Choose where this field group appears.', TD ) ),
					h( LocationEditor, { location: group.location, pages, onChange: ( location ) => set( { location } ) } )
				),
				h( BlockSettings, { group, onChange: ( block ) => set( { block } ) } )
			),
			h(
				'div',
				{ className: 'gfb-editor__side' },
				h(
					'div',
					{ className: 'gfb-card' },
					h( 'h2', null, __( 'Publish', TD ) ),
					h( C.ToggleControl, { __nextHasNoMarginBottom: true, label: __( 'Active', TD ), checked: !! group.active, onChange: ( active ) => set( { active } ) } ),
					h(
						'div',
						{ className: 'gfb-actions' },
						h( C.Button, { variant: 'tertiary', onClick: back }, __( 'Back to the list', TD ) ),
						h( C.Button, { variant: 'primary', isBusy: saving, disabled: saving || ! group.title.trim(), onClick: save }, __( 'Save', TD ) )
					)
				),
				h(
					'div',
					{ className: 'gfb-card' },
					h( 'h2', null, __( 'Settings', TD ) ),
					h( 'label', { className: 'gfb-label' }, __( 'Position in the editor', TD ) ),
					h( Select, {
						value: group.position,
						options: [ { value: 'normal', label: __( 'Below the content', TD ) }, { value: 'side', label: __( 'Sidebar', TD ) } ],
						onChange: ( position ) => set( { position } ),
					} ),
					h( 'label', { className: 'gfb-label' }, __( 'Order', TD ) ),
					h( 'input', { type: 'number', className: 'components-text-control__input gfb-short', value: group.order, onChange: ( e ) => set( { order: Number( e.target.value ) || 0 } ) } ),
					h( 'label', { className: 'gfb-label' }, __( 'Description', TD ) ),
					h( 'textarea', { className: 'components-textarea-control__input', rows: 3, value: group.description || '', onChange: ( e ) => set( { description: e.target.value } ) } ),
					h( 'label', { className: 'gfb-label' }, __( 'REST API', TD ) ),
					h( C.ToggleControl, {
						__nextHasNoMarginBottom: true,
						label: __( 'Show values in the REST API', TD ),
						help: __( 'Adds the values to the public "magicfields" property of posts, terms and users, for headless sites and apps.', TD ),
						checked: !! group.show_in_rest,
						onChange: ( show_in_rest ) => set( { show_in_rest } ),
					} )
				)
			)
		);
	}

	/** "Fields as blocks": turns the group into a block with its own markup. */
	function BlockSettings( { group, onChange } ) {
		const block = Object.assign( { enabled: false, slug: '', icon: 'block-default', category: 'widgets', description: '', template: '' }, group.block || {} );
		const set = ( patch ) => onChange( Object.assign( {}, block, patch ) );
		const names = ( group.fields || [] ).filter( ( f ) => GF.hasValue( f ) && f.name );
		return h(
			'div',
			{ className: 'gfb-card' },
			h( 'h2', null, __( 'Block', TD ) ),
			h( C.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Make this field group a block', TD ),
				help: __( 'The block appears in the inserter. Its fields are filled in the block settings and stored in the block itself, so it can be used several times on a page.', TD ),
				checked: !! block.enabled,
				onChange: ( enabled ) => set( enabled && ! block.slug ? { enabled, slug: slugify( group.title ).replace( /_/g, '-' ) } : { enabled } ),
			} ),
			block.enabled &&
				h(
					Fragment,
					null,
					h( Row, { label: __( 'Block name', TD ), help: __( 'Lowercase letters, numbers and hyphens. Changing it later disconnects blocks already inserted.', TD ) }, h( Text, { value: block.slug, className: 'components-text-control__input gfb-mono', onChange: ( v ) => set( { slug: slugify( v ).replace( /_/g, '-' ) } ) } ) ),
					h( Row, { label: __( 'Category', TD ) }, h( Select, { value: block.category, options: cfg.categories, onChange: ( category ) => set( { category } ) } ) ),
					h( Row, { label: __( 'Icon (Dashicon)', TD ) }, h( Text, { value: block.icon, placeholder: 'block-default', className: 'components-text-control__input gfb-mono', onChange: ( icon ) => set( { icon } ) } ) ),
					h( Row, { label: __( 'Description', TD ) }, h( Text, { value: block.description, onChange: ( description ) => set( { description } ) } ) ),
					h(
						Row,
						{ label: __( 'Template', TD ), help: __( 'The HTML of the block. Leave empty to list the fields one under the other.', TD ), wide: true },
						h( 'textarea', {
							className: 'components-textarea-control__input gfb-mono gfb-template',
							rows: 8,
							value: block.template,
							placeholder: '<h3>{{title}}</h3>\n{{#items}}<p>{{label}}</p>{{/items}}',
							onChange: ( e ) => set( { template: e.target.value } ),
						} ),
						h(
							'p',
							{ className: 'gfb-hint' },
							h( 'code', null, '{{name}}' ), ' ', __( 'the field as safe HTML', TD ), ' · ',
							h( 'code', null, '{{name|raw}}' ), ' ', __( 'the stored value, for attributes (image URL, color…)', TD ), ' · ',
							h( 'code', null, '{{#name}}…{{/name}}' ), ' ', __( 'repeats for each row, or shows the content if the field is filled', TD ), ' · ',
							h( 'code', null, '{{^name}}…{{/name}}' ), ' ', __( 'shows the content if the field is empty', TD )
						),
						names.length > 0 && h( 'p', { className: 'gfb-hint' }, __( 'Fields:', TD ), ' ', names.map( ( f ) => h( Fragment, { key: f.key }, h( 'code', null, '{{' + f.name + '}}' ), ' ' ) ) )
					)
				)
		);
	}

	function OptionsPages( { pages, setPages, notify } ) {
		const [ list, setList ] = useState( () => pages.filter( ( p ) => ! p.local ).map( ( p ) => Object.assign( { _id: newKey( 'p' ) }, p ) ) );
		const [ saving, setSaving ] = useState( false );
		const local = pages.filter( ( p ) => p.local );
		const set = ( i, patch ) => setList( list.map( ( p, x ) => ( x === i ? Object.assign( {}, p, patch ) : p ) ) );
		const save = () => {
			setSaving( true );
			apiFetch( { path: 'magicfields/v1/options-pages', method: 'POST', data: list.map( ( p ) => Object.assign( {}, p, { slug: p.slug || slugify( p.title ) } ) ) } )
				.then( ( saved ) => {
					setSaving( false );
					setList( saved.map( ( p ) => Object.assign( { _id: newKey( 'p' ) }, p ) ) );
					setPages( local.concat( saved ) );
					notify( { status: 'success', text: __( 'Options pages saved. Reload the page to see them in the menu.', TD ) } );
				} )
				.catch( ( err ) => {
					setSaving( false );
					notify( { status: 'error', text: err.message } );
				} );
		};
		return h(
			'div',
			{ className: 'gfb-card' },
			h( 'h2', null, __( 'Options pages', TD ) ),
			h( 'p', { className: 'description' }, __( 'An options page is an admin screen for site-wide values (phone number, social links, footer text…). Create a page, then set a field group location to it.', TD ) ),
			local.map( ( p ) => h( 'p', { key: p.slug }, h( 'strong', null, p.title ), ' — ', __( 'Registered in code', TD ) ) ),
			list.map( ( p, i ) =>
				h(
					'div',
					{ key: p._id, className: 'gfb-page' },
					h( 'div', null, h( 'label', { className: 'gfb-label' }, __( 'Title', TD ) ), h( Text, { value: p.title || '', onChange: ( v ) => set( i, p._new ? { title: v, slug: slugify( v ) } : { title: v } ) } ) ),
					h( 'div', null, h( 'label', { className: 'gfb-label' }, __( 'Slug', TD ) ), h( Text, { value: p.slug || '', className: 'components-text-control__input gfb-mono', onChange: ( v ) => set( i, { slug: slugify( v ), _new: false } ) } ) ),
					h(
						'div',
						null,
						h( 'label', { className: 'gfb-label' }, __( 'Menu position', TD ) ),
						h( Select, {
							value: p.parent || '',
							options: cfg.menuParents.concat( list.filter( ( o ) => o !== p && o.slug && ! o.parent ).map( ( o ) => ( { value: o.slug, label: sprintf( /* translators: %s: page title */ __( 'Under "%s"', TD ), o.title ) } ) ) ),
							onChange: ( v ) => set( i, { parent: v } ),
						} )
					),
					h( 'div', null, h( 'label', { className: 'gfb-label' }, __( 'Icon (Dashicon)', TD ) ), h( Text, { value: p.icon || '', placeholder: 'dashicons-admin-generic', className: 'components-text-control__input gfb-mono', onChange: ( v ) => set( i, { icon: v } ) } ) ),
					h( C.Button, { icon: 'trash', isDestructive: true, label: __( 'Delete', TD ), onClick: () => setList( list.filter( ( o, x ) => x !== i ) ) } )
				)
			),
			h(
				'div',
				{ className: 'gfb-actions gfb-actions--start' },
				h( C.Button, { variant: 'secondary', onClick: () => setList( list.concat( [ { _id: newKey( 'p' ), _new: true, title: '', slug: '', parent: '', icon: 'dashicons-admin-generic', capability: 'manage_options' } ] ) ) }, __( 'Add options page', TD ) ),
				h( C.Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: save }, __( 'Save', TD ) )
			)
		);
	}

	function Tools( { groups, reload, notify } ) {
		const own = ( groups || [] ).filter( ( g ) => ! g.local );
		const [ picked, setPicked ] = useState( {} );
		const [ text, setText ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const exportJson = () => {
			const chosen = own.filter( ( g ) => picked[ g.key ] );
			const out = ( chosen.length ? chosen : own ).map( ( g ) => {
				const copy = clone( g );
				delete copy.id;
				return copy;
			} );
			const blob = new Blob( [ JSON.stringify( { generator: 'magicfields', groups: out }, null, 2 ) ], { type: 'application/json' } );
			const a = document.createElement( 'a' );
			a.href = URL.createObjectURL( blob );
			a.download = 'magicfields-export.json';
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( a.href );
		};
		const importJson = () => {
			let parsed;
			try {
				parsed = JSON.parse( text );
			} catch ( e ) {
				notify( { status: 'error', text: __( 'This is not valid JSON.', TD ) } );
				return;
			}
			const list = Array.isArray( parsed ) ? parsed : parsed.groups || parsed;
			setBusy( true );
			apiFetch( { path: 'magicfields/v1/import', method: 'POST', data: { groups: list } } )
				.then( ( res ) => {
					setBusy( false );
					setText( '' );
					reload();
					notify( {
						status: res.warnings.length ? 'warning' : 'success',
						text: sprintf( /* translators: %d: number of groups */ _n( '%d field group imported.', '%d field groups imported.', res.imported.length, TD ), res.imported.length ),
						list: res.warnings,
					} );
				} )
				.catch( ( err ) => {
					setBusy( false );
					notify( { status: 'error', text: err.message } );
				} );
		};
		return h(
			Fragment,
			null,
			h(
				'div',
				{ className: 'gfb-card' },
				h( 'h2', null, __( 'Import', TD ) ),
				h( 'p', { className: 'description' }, __( 'Paste or load a JSON export from MagicFields or from Advanced Custom Fields (ACF). ACF field groups are converted; your existing content stays readable because values keep the same meta keys.', TD ) ),
				h( 'input', {
					type: 'file',
					accept: '.json,application/json',
					onChange: ( e ) => {
						const file = e.target.files[ 0 ];
						if ( file ) {
							file.text().then( setText );
						}
					},
				} ),
				h( 'textarea', { className: 'components-textarea-control__input gfb-mono', rows: 8, value: text, placeholder: '[ { "key": "group_…", "title": "…", "fields": [ … ] } ]', onChange: ( e ) => setText( e.target.value ) } ),
				h( 'div', { className: 'gfb-actions gfb-actions--start' }, h( C.Button, { variant: 'primary', isBusy: busy, disabled: busy || ! text.trim(), onClick: importJson }, __( 'Import', TD ) ) )
			),
			h(
				'div',
				{ className: 'gfb-card' },
				h( 'h2', null, __( 'Export', TD ) ),
				own.length === 0
					? h( 'p', null, __( 'Nothing to export yet.', TD ) )
					: h(
							Fragment,
							null,
							h( 'p', { className: 'description' }, __( 'Select the field groups to export. With nothing selected, all groups are exported.', TD ) ),
							own.map( ( g ) => h( C.CheckboxControl, { key: g.key, __nextHasNoMarginBottom: true, label: g.title, checked: !! picked[ g.key ], onChange: ( on ) => setPicked( Object.assign( {}, picked, { [ g.key ]: on } ) ) } ) ),
							h( 'div', { className: 'gfb-actions gfb-actions--start' }, h( C.Button, { variant: 'secondary', onClick: exportJson }, __( 'Download JSON', TD ) ) )
					  )
			)
		);
	}

	function App() {
		const [ view, setView ] = useState( 'groups' );
		const [ groups, setGroups ] = useState( null );
		const [ editing, setEditing ] = useState( null );
		const [ pages, setPages ] = useState( cfg.optionsPages || [] );
		const [ notice, setNotice ] = useState( null );

		const load = () =>
			apiFetch( { path: 'magicfields/v1/groups' } )
				.then( setGroups )
				.catch( ( err ) => {
					setGroups( [] );
					setNotice( { status: 'error', text: err.message } );
				} );
		useEffect( () => {
			load();
		}, [] );

		const saveGroup = ( group ) => apiFetch( { path: 'magicfields/v1/groups', method: 'POST', data: group } ).then( load );
		knownGroups = ( groups || [] ).filter( ( g ) => ! editing || g.key !== editing.key );
		const blank = () => ( { key: '', title: '', active: true, order: 0, position: 'normal', description: '', show_in_rest: false, block: { enabled: false, slug: '', icon: 'block-default', category: 'widgets', description: '', template: '' }, fields: [], location: [ [ { param: 'post_type', operator: '==', value: 'post' } ] ] } );

		const tabs = [
			{ id: 'groups', label: __( 'Field groups', TD ) },
			{ id: 'pages', label: __( 'Options pages', TD ) },
			{ id: 'tools', label: __( 'Import / Export', TD ) },
		];
		const go = ( id ) => {
			setEditing( null );
			setNotice( null );
			setView( id );
		};

		return h(
			'div',
			{ className: 'gfb' },
			h(
				'div',
				{ className: 'gfb-header' },
				h( 'h1', null, 'MagicFields' ),
				! editing && view === 'groups' && h( C.Button, { variant: 'primary', onClick: () => setEditing( blank() ) }, __( 'Add field group', TD ) )
			),
			! editing &&
				h(
					'nav',
					{ className: 'nav-tab-wrapper gfb-nav' },
					tabs.map( ( t ) => h( 'a', { key: t.id, href: '#', className: 'nav-tab' + ( view === t.id ? ' nav-tab-active' : '' ), onClick: ( e ) => { e.preventDefault(); go( t.id ); } }, t.label ) )
				),
			h( Notice, { notice, onDismiss: () => setNotice( null ) } ),
			editing
				? h( GroupEditor, { key: editing.key || 'new', initial: editing, pages, notify: setNotice, onSaved: load, onBack: () => { setEditing( null ); setNotice( null ); } } )
				: view === 'groups'
				? h( GroupList, {
						groups,
						pages,
						onNew: () => setEditing( blank() ),
						onEdit: ( g ) => { setNotice( null ); setEditing( clone( g ) ); },
						onToggle: ( g ) => saveGroup( Object.assign( {}, g, { active: ! g.active } ) ),
						onDuplicate: ( g ) => {
							const copy = clone( g );
							const rekey = ( field ) => mapDeep( field, ( f ) => Object.assign( f, { key: newKey( 'field' ) } ) );
							saveGroup( Object.assign( copy, { key: '', id: undefined, title: sprintf( /* translators: %s: group title */ __( '%s (copy)', TD ), g.title ), active: false, block: Object.assign( {}, copy.block, { enabled: false } ), fields: copy.fields.map( rekey ) } ) );
						},
						onDelete: ( g ) => {
							/* translators: %s: group title */
							if ( window.confirm( sprintf( __( 'Delete the field group "%s"? The values already saved in your content are kept.', TD ), g.title ) ) ) {
								apiFetch( { path: 'magicfields/v1/groups/' + g.key, method: 'DELETE' } ).then( load );
							}
						},
				  } )
				: view === 'pages'
				? h( OptionsPages, { pages, setPages, notify: setNotice } )
				: h( Tools, { groups, reload: load, notify: setNotice } )
		);
	}

	const host = document.getElementById( 'magicfields-builder' );
	if ( host ) {
		if ( wp.element.createRoot ) {
			wp.element.createRoot( host ).render( h( App ) );
		} else {
			wp.element.render( h( App ), host );
		}
	}
} )( window.wp );
