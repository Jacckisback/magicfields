/**
 * MagicFields — field controls shared by every screen.
 *
 * Plain JavaScript on top of the React copy that ships with WordPress
 * (wp.element): no build step. Exposes window.MagicFields.
 */
( function ( wp ) {
	'use strict';

	const { createElement: h, useState, useEffect, useRef, Fragment } = wp.element;
	const C = wp.components;
	const { __, sprintf } = wp.i18n;
	const { useSelect } = wp.data;
	const apiFetch = wp.apiFetch;
	const { addQueryArgs } = wp.url;
	const TD = 'magicfields';

	let uid = 0;
	const nextId = () => 'magicfields-' + ++uid;

	const isEmpty = ( v ) => v === undefined || v === null || v === '' || ( Array.isArray( v ) && v.length === 0 );
	const toArray = ( v ) => ( Array.isArray( v ) ? v : isEmpty( v ) ? [] : [ v ] );
	const hasValue = ( field ) => field.type !== 'tab' && field.type !== 'message' && field.type !== 'clone';
	const layoutOf = ( field, row ) => ( field.layouts || [] ).find( ( l ) => row && l.name === row.acf_fc_layout );
	const isMultiple = ( field ) =>
		field.type === 'checkbox' ||
		field.type === 'gallery' ||
		( [ 'select', 'post_object', 'taxonomy', 'user' ].includes( field.type ) && !! field.multiple );

	function splitDefault( field ) {
		const allowed = ( field.choices || [] ).map( ( c ) => String( c.value ) );
		return String( field.default || '' )
			.split( /[\r\n,]+/ )
			.map( ( s ) => s.trim() )
			.filter( ( s ) => allowed.includes( s ) );
	}

	/** Default raw value of a field (used for new repeater rows). */
	function defaultValue( field ) {
		switch ( field.type ) {
			case 'true_false':
				return field.default ? 1 : 0;
			case 'repeater':
			case 'flexible':
				return [];
			case 'group':
				return defaultsFor( field.sub_fields || [] );
			case 'checkbox':
			case 'select':
				if ( isMultiple( field ) ) {
					return splitDefault( field );
				}
				return splitDefault( field )[ 0 ] || '';
			case 'radio':
			case 'button_group':
				return splitDefault( field )[ 0 ] || '';
			case 'gallery':
				return [];
			case 'image':
			case 'file':
			case 'link':
			case 'password':
				return '';
			case 'post_object':
			case 'taxonomy':
			case 'user':
				return isMultiple( field ) ? [] : '';
			default:
				return field.default === undefined || field.default === null ? '' : String( field.default );
		}
	}

	function defaultsFor( fields ) {
		const out = {};
		fields.forEach( ( f ) => {
			if ( hasValue( f ) ) {
				out[ f.name ] = defaultValue( f );
			}
		} );
		return out;
	}

	/** Conditional logic: every rule must pass. Rules point at sibling field names. */
	function isVisible( field, values ) {
		return ( field.conditions || [] ).every( ( rule ) => {
			const v = values ? values[ rule.field ] : undefined;
			const list = toArray( v ).map( String );
			switch ( rule.operator ) {
				case 'empty':
					return isEmpty( v ) || v === 0 || v === '0';
				case 'not_empty':
					return ! ( isEmpty( v ) || v === 0 || v === '0' );
				case '!=':
					return ! list.includes( String( rule.value ) );
				case 'contains':
					return list.some( ( s ) => s.toLowerCase().includes( String( rule.value ).toLowerCase() ) );
				default:
					return list.includes( String( rule.value ) );
			}
		} );
	}

	/** Labels of the required fields left empty (hidden fields are ignored). */
	function validate( fields, values, prefix ) {
		let missing = [];
		( fields || [] ).forEach( ( field ) => {
			if ( ! hasValue( field ) || ! isVisible( field, values ) ) {
				return;
			}
			const v = values ? values[ field.name ] : undefined;
			const label = ( prefix ? prefix + ' › ' : '' ) + field.label;
			if ( field.type === 'group' ) {
				missing = missing.concat( validate( field.sub_fields, v || {}, label ) );
			} else if ( field.type === 'repeater' || field.type === 'flexible' ) {
				const rows = toArray( v );
				if ( field.required && rows.length === 0 ) {
					missing.push( label );
				}
				rows.forEach( ( row, i ) => {
					const subs = field.type === 'flexible' ? ( layoutOf( field, row ) || {} ).sub_fields : field.sub_fields;
					missing = missing.concat( validate( subs, row || {}, label + ' #' + ( i + 1 ) ) );
				} );
			} else if ( field.required && ( isEmpty( v ) || ( field.type === 'true_false' && ! Number( v ) ) ) ) {
				missing.push( label );
			}
		} );
		return missing;
	}

	/* ------------------------------------------------------------------ */
	/* Controls                                                            */
	/* ------------------------------------------------------------------ */

	const controls = {};

	function TextInput( { field, value, onChange, id } ) {
		const types = { number: 'number', email: 'email', url: 'url', password: 'password', date: 'date', time: 'time', datetime: 'datetime-local' };
		const props = {
			id,
			className: 'components-text-control__input magicfields-input',
			type: types[ field.type ] || 'text',
			value: value === undefined || value === null ? '' : value,
			placeholder: field.placeholder || undefined,
			onChange: ( e ) => onChange( e.target.value ),
		};
		if ( field.type === 'number' ) {
			[ 'min', 'max', 'step' ].forEach( ( k ) => {
				if ( field[ k ] !== '' && field[ k ] !== undefined ) {
					props[ k ] = field[ k ];
				}
			} );
			if ( props.step === undefined ) {
				props.step = 'any';
			}
		}
		if ( field.type === 'text' && field.maxlength ) {
			props.maxLength = field.maxlength;
		}
		if ( field.type === 'password' ) {
			props.autoComplete = 'new-password';
		}
		if ( field.type === 'datetime' ) {
			props.value = String( props.value ).replace( ' ', 'T' ).slice( 0, 16 );
			props.onChange = ( e ) => onChange( e.target.value ? e.target.value.replace( 'T', ' ' ) + ':00' : '' );
		}
		if ( field.type === 'time' ) {
			props.value = String( props.value ).slice( 0, 5 );
			props.onChange = ( e ) => onChange( e.target.value ? e.target.value + ':00' : '' );
		}
		return h( 'input', props );
	}
	[ 'text', 'number', 'email', 'url', 'password', 'date', 'time', 'datetime' ].forEach( ( t ) => ( controls[ t ] = TextInput ) );

	controls.textarea = function ( { field, value, onChange, id } ) {
		return h( 'textarea', {
			id,
			className: 'components-textarea-control__input magicfields-input',
			rows: field.rows || 4,
			value: value || '',
			placeholder: field.placeholder || undefined,
			onChange: ( e ) => onChange( e.target.value ),
		} );
	};

	controls.range = function ( { field, value, onChange } ) {
		const num = ( v, d ) => ( v === '' || v === undefined || v === null ? d : Number( v ) );
		return h( C.RangeControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			value: value === '' || value === undefined || value === null ? undefined : Number( value ),
			min: num( field.min, 0 ),
			max: num( field.max, 100 ),
			step: num( field.step, 1 ),
			onChange: ( v ) => onChange( v === undefined ? '' : v ),
			allowReset: true,
		} );
	};

	controls.select = function ( { field, value, onChange, id } ) {
		const choices = field.choices || [];
		if ( isMultiple( field ) ) {
			const byLabel = {};
			const byValue = {};
			choices.forEach( ( c ) => {
				byLabel[ c.label ] = c.value;
				byValue[ c.value ] = c.label;
			} );
			return h( C.FormTokenField, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				__experimentalExpandOnFocus: true,
				__experimentalShowHowTo: false,
				label: '',
				value: toArray( value ).filter( ( v ) => byValue[ v ] !== undefined ).map( ( v ) => byValue[ v ] ),
				suggestions: choices.map( ( c ) => c.label ),
				onChange: ( tokens ) => onChange( tokens.filter( ( t ) => byLabel[ t ] !== undefined ).map( ( t ) => byLabel[ t ] ) ),
			} );
		}
		const known = choices.some( ( c ) => String( c.value ) === String( value ) );
		return h(
			'select',
			{ id, className: 'components-select-control__input magicfields-input', value: known ? value : '', onChange: ( e ) => onChange( e.target.value ) },
			( field.allow_null || ! known ) && h( 'option', { value: '' }, __( '— Select —', TD ) ),
			choices.map( ( c ) => h( 'option', { key: c.value, value: c.value }, c.label ) )
		);
	};

	controls.checkbox = function ( { field, value, onChange } ) {
		const list = toArray( value ).map( String );
		return h(
			'div',
			{ className: 'magicfields-choices' },
			( field.choices || [] ).map( ( c ) =>
				h( C.CheckboxControl, {
					key: c.value,
					__nextHasNoMarginBottom: true,
					label: c.label,
					checked: list.includes( String( c.value ) ),
					onChange: ( on ) =>
						onChange( ( field.choices || [] ).map( ( x ) => String( x.value ) ).filter( ( v ) => ( v === String( c.value ) ? on : list.includes( v ) ) ) ),
				} )
			)
		);
	};

	controls.radio = function ( { field, value, onChange, id } ) {
		return h(
			'div',
			{ className: 'magicfields-choices' },
			( field.choices || [] ).map( ( c ) =>
				h(
					'label',
					{ key: c.value, className: 'magicfields-radio' },
					h( 'input', {
						type: 'radio',
						name: id,
						checked: String( value ) === String( c.value ),
						onChange: () => onChange( c.value ),
					} ),
					' ',
					c.label
				)
			)
		);
	};

	controls.button_group = function ( { field, value, onChange } ) {
		return h(
			'div',
			{ className: 'magicfields-button-group', role: 'group' },
			( field.choices || [] ).map( ( c ) => {
				const on = String( value ) === String( c.value );
				return h(
					C.Button,
					{
						key: c.value,
						variant: on ? 'primary' : 'secondary',
						'aria-pressed': on,
						onClick: () => onChange( on && ! field.required ? '' : c.value ),
					},
					c.label
				);
			} )
		);
	};

	controls.true_false = function ( { field, value, onChange } ) {
		return h( C.ToggleControl, {
			__nextHasNoMarginBottom: true,
			label: field.message || '',
			checked: !! Number( value ),
			onChange: ( on ) => onChange( on ? 1 : 0 ),
		} );
	};

	controls.color = function ( { field, value, onChange } ) {
		return h(
			'div',
			{ className: 'magicfields-color' },
			h( C.Dropdown, {
				popoverProps: { placement: 'bottom-start' },
				renderToggle: ( { isOpen, onToggle } ) =>
					h(
						C.Button,
						{ variant: 'secondary', onClick: onToggle, 'aria-expanded': isOpen, className: 'magicfields-color__toggle' },
						h( 'span', { className: 'magicfields-color__swatch', style: { background: value || 'transparent' } } ),
						value || __( 'Choose a color', TD )
					),
				renderContent: () =>
					h( C.ColorPicker, { color: value || '#ffffff', enableAlpha: !! field.alpha, onChange: ( c ) => onChange( c ) } ),
			} ),
			value && h( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange( '' ) }, __( 'Clear', TD ) )
		);
	};

	function openMedia( options, onSelect ) {
		if ( ! wp.media ) {
			return;
		}
		const frame = wp.media( {
			title: options.title,
			multiple: options.multiple ? 'add' : false,
			library: options.type ? { type: options.type } : {},
			button: { text: __( 'Select', TD ) },
		} );
		frame.on( 'open', () => {
			const selection = frame.state().get( 'selection' );
			toArray( options.selected ).forEach( ( id ) => {
				const attachment = wp.media.attachment( id );
				attachment.fetch();
				selection.add( attachment );
			} );
		} );
		frame.on( 'select', () => onSelect( frame.state().get( 'selection' ).toJSON() ) );
		frame.open();
	}

	function useMedia( id ) {
		return useSelect( ( select ) => ( id ? select( 'core' ).getMedia( Number( id ), { context: 'view' } ) : null ), [ id ] );
	}

	function mediaThumb( media ) {
		if ( ! media ) {
			return '';
		}
		const sizes = ( media.media_details && media.media_details.sizes ) || {};
		return ( sizes.medium || sizes.thumbnail || {} ).source_url || media.source_url || '';
	}

	controls.image = function ( { field, value, onChange } ) {
		const media = useMedia( value );
		const pick = () =>
			openMedia( { title: field.label, type: 'image', selected: value }, ( items ) => items[ 0 ] && onChange( items[ 0 ].id ) );
		return h(
			'div',
			{ className: 'magicfields-media' },
			value
				? h( 'div', { className: 'magicfields-media__preview' }, media ? h( 'img', { src: mediaThumb( media ), alt: media.alt_text || '' } ) : h( C.Spinner ) )
				: null,
			h(
				'div',
				{ className: 'magicfields-media__actions' },
				h( C.Button, { variant: 'secondary', onClick: pick }, value ? __( 'Replace', TD ) : __( 'Choose an image', TD ) ),
				value && h( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange( '' ) }, __( 'Remove', TD ) )
			)
		);
	};

	controls.file = function ( { field, value, onChange } ) {
		const media = useMedia( value );
		const pick = () => openMedia( { title: field.label, selected: value }, ( items ) => items[ 0 ] && onChange( items[ 0 ].id ) );
		return h(
			'div',
			{ className: 'magicfields-media' },
			value
				? h(
						'div',
						{ className: 'magicfields-file' },
						media
							? h( 'a', { href: media.source_url, target: '_blank', rel: 'noreferrer' }, ( media.title && media.title.rendered ) || media.source_url )
							: h( C.Spinner )
				  )
				: null,
			h(
				'div',
				{ className: 'magicfields-media__actions' },
				h( C.Button, { variant: 'secondary', onClick: pick }, value ? __( 'Replace', TD ) : __( 'Choose a file', TD ) ),
				value && h( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange( '' ) }, __( 'Remove', TD ) )
			)
		);
	};

	function GalleryItem( { id, onRemove, onMove, first, last } ) {
		const media = useMedia( id );
		return h(
			'div',
			{ className: 'magicfields-gallery__item' },
			media ? h( 'img', { src: mediaThumb( media ), alt: media.alt_text || '' } ) : h( C.Spinner ),
			h(
				'div',
				{ className: 'magicfields-gallery__tools' },
				h( C.Button, { size: 'small', icon: 'arrow-left-alt2', label: __( 'Move left', TD ), disabled: first, onClick: () => onMove( -1 ) } ),
				h( C.Button, { size: 'small', icon: 'arrow-right-alt2', label: __( 'Move right', TD ), disabled: last, onClick: () => onMove( 1 ) } ),
				h( C.Button, { size: 'small', icon: 'no-alt', label: __( 'Remove', TD ), isDestructive: true, onClick: onRemove } )
			)
		);
	}

	controls.gallery = function ( { field, value, onChange } ) {
		const ids = toArray( value );
		const max = Number( field.max ) || 0;
		const pick = () =>
			openMedia( { title: field.label, type: 'image', multiple: true, selected: ids }, ( items ) => {
				const next = items.map( ( i ) => i.id );
				onChange( max ? next.slice( 0, max ) : next );
			} );
		const move = ( i, d ) => {
			const next = ids.slice();
			const [ item ] = next.splice( i, 1 );
			next.splice( i + d, 0, item );
			onChange( next );
		};
		return h(
			'div',
			{ className: 'magicfields-media' },
			ids.length > 0 &&
				h(
					'div',
					{ className: 'magicfields-gallery' },
					ids.map( ( id, i ) =>
						h( GalleryItem, {
							key: id,
							id,
							first: i === 0,
							last: i === ids.length - 1,
							onMove: ( d ) => move( i, d ),
							onRemove: () => onChange( ids.filter( ( x ) => x !== id ) ),
						} )
					)
				),
			h( C.Button, { variant: 'secondary', onClick: pick }, ids.length ? __( 'Edit gallery', TD ) : __( 'Add images', TD ) )
		);
	};

	controls.link = function ( { value, onChange, id } ) {
		const link = value && typeof value === 'object' ? value : { url: '', title: '', target: '' };
		const set = ( patch ) => {
			const next = Object.assign( {}, link, patch );
			onChange( next.url || next.title ? next : '' );
		};
		return h(
			'div',
			{ className: 'magicfields-link' },
			h( 'input', {
				id,
				type: 'url',
				className: 'components-text-control__input magicfields-input',
				placeholder: 'https://',
				value: link.url || '',
				onChange: ( e ) => set( { url: e.target.value } ),
			} ),
			h( 'input', {
				type: 'text',
				className: 'components-text-control__input magicfields-input',
				placeholder: __( 'Link text', TD ),
				'aria-label': __( 'Link text', TD ),
				value: link.title || '',
				onChange: ( e ) => set( { title: e.target.value } ),
			} ),
			h( C.CheckboxControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Open in a new tab', TD ),
				checked: link.target === '_blank',
				onChange: ( on ) => set( { target: on ? '_blank' : '' } ),
			} )
		);
	};

	/** Post / term / user picker backed by the plugin search endpoint. */
	function Picker( { field, value, onChange, id } ) {
		const multiple = isMultiple( field );
		const ids = toArray( value ).map( Number ).filter( Boolean );
		const [ labels, setLabels ] = useState( {} );
		const [ search, setSearch ] = useState( '' );
		const [ results, setResults ] = useState( null );
		const [ open, setOpen ] = useState( false );
		const box = useRef();

		const query = { kind: field.type === 'taxonomy' ? 'term' : field.type === 'user' ? 'user' : 'post' };
		if ( field.type === 'taxonomy' ) {
			query.taxonomy = field.taxonomy || 'category';
		} else if ( field.type === 'user' ) {
			query.roles = ( field.roles || [] ).join( ',' );
		} else {
			query.post_types = ( field.post_types || [] ).join( ',' );
		}
		const queryKey = JSON.stringify( query );

		const missing = ids.filter( ( i ) => labels[ i ] === undefined ).join( ',' );
		useEffect( () => {
			if ( ! missing ) {
				return;
			}
			let alive = true;
			apiFetch( { path: addQueryArgs( 'magicfields/v1/search', Object.assign( {}, query, { include: missing } ) ) } )
				.then( ( items ) => {
					if ( ! alive ) {
						return;
					}
					const next = {};
					missing.split( ',' ).forEach( ( i ) => ( next[ i ] = '#' + i ) );
					items.forEach( ( item ) => ( next[ item.id ] = item.label ) );
					setLabels( ( prev ) => Object.assign( {}, prev, next ) );
				} )
				.catch( () => {} );
			return () => {
				alive = false;
			};
		}, [ missing, queryKey ] );

		useEffect( () => {
			if ( ! open ) {
				return;
			}
			let alive = true;
			const timer = setTimeout( () => {
				apiFetch( { path: addQueryArgs( 'magicfields/v1/search', Object.assign( {}, query, { s: search } ) ) } )
					.then( ( items ) => alive && setResults( items ) )
					.catch( () => alive && setResults( [] ) );
			}, 250 );
			return () => {
				alive = false;
				clearTimeout( timer );
			};
		}, [ open, search, queryKey ] );

		useEffect( () => {
			const close = ( e ) => box.current && ! box.current.contains( e.target ) && setOpen( false );
			document.addEventListener( 'mousedown', close );
			return () => document.removeEventListener( 'mousedown', close );
		}, [] );

		const add = ( item ) => {
			setLabels( ( prev ) => Object.assign( {}, prev, { [ item.id ]: item.label } ) );
			if ( multiple ) {
				if ( ! ids.includes( item.id ) ) {
					onChange( ids.concat( item.id ) );
				}
			} else {
				onChange( item.id );
				setOpen( false );
			}
			setSearch( '' );
		};
		const remove = ( rid ) => onChange( multiple ? ids.filter( ( i ) => i !== rid ) : '' );

		return h(
			'div',
			{ className: 'magicfields-picker', ref: box },
			ids.length > 0 &&
				h(
					'div',
					{ className: 'magicfields-picker__chips' },
					ids.map( ( i ) =>
						h(
							'span',
							{ key: i, className: 'magicfields-chip' },
							labels[ i ] || '…',
							h( C.Button, { size: 'small', icon: 'no-alt', label: __( 'Remove', TD ), onClick: () => remove( i ) } )
						)
					)
				),
			h( 'input', {
				id,
				type: 'search',
				className: 'components-text-control__input magicfields-input',
				placeholder: __( 'Search…', TD ),
				value: search,
				onFocus: () => setOpen( true ),
				onChange: ( e ) => {
					setSearch( e.target.value );
					setOpen( true );
				},
				onKeyDown: ( e ) => e.key === 'Enter' && e.preventDefault(),
			} ),
			open &&
				h(
					'div',
					{ className: 'magicfields-picker__results' },
					results === null && h( C.Spinner ),
					results && results.length === 0 && h( 'p', { className: 'magicfields-picker__empty' }, __( 'Nothing found.', TD ) ),
					results &&
						results.map( ( item ) =>
							h(
								'button',
								{
									key: item.id,
									type: 'button',
									className: 'magicfields-picker__result' + ( ids.includes( item.id ) ? ' is-selected' : '' ),
									onClick: () => add( item ),
								},
								h( 'span', null, item.label ),
								h( 'small', null, item.sub )
							)
						)
				)
		);
	}
	controls.post_object = Picker;
	controls.taxonomy = Picker;
	controls.user = Picker;

	/** Classic rich text editor (TinyMCE), with a plain textarea fallback. */
	controls.wysiwyg = function ( { field, value, onChange } ) {
		const [ id ] = useState( () => nextId().replace( /-/g, '_' ) + '_wys' );
		const latest = useRef( onChange );
		latest.current = onChange;
		const initial = useRef( value || '' );

		useEffect( () => {
			const api = wp.oldEditor || wp.editor;
			if ( ! api || ! api.initialize || ! window.tinymce ) {
				return;
			}
			const push = ( content ) => latest.current( content );
			api.initialize( id, {
				tinymce: {
					wpautop: true,
					toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo,removeformat',
					setup( editor ) {
						editor.on( 'change keyup undo redo SetContent', () => editor.initialized && push( editor.getContent() ) );
					},
				},
				quicktags: true,
				mediaButtons: field.media_upload !== false,
			} );
			const textarea = document.getElementById( id );
			const onText = () => push( textarea.value );
			if ( textarea ) {
				textarea.addEventListener( 'input', onText );
			}
			return () => {
				if ( textarea ) {
					textarea.removeEventListener( 'input', onText );
				}
				api.remove( id );
			};
		}, [] );

		// Uncontrolled on purpose: TinyMCE owns the textarea once initialized.
		return h(
			'div',
			{ className: 'magicfields-wysiwyg' },
			h( 'textarea', {
				id,
				className: 'magicfields-input wp-editor-area',
				rows: 8,
				defaultValue: initial.current,
				onChange: ( e ) => onChange( e.target.value ),
			} )
		);
	};

	controls.group = function ( { field, value, onChange, pathId } ) {
		const obj = value && typeof value === 'object' && ! Array.isArray( value ) ? value : {};
		return h(
			'div',
			{ className: 'magicfields-group' },
			h( FieldList, {
				fields: field.sub_fields || [],
				values: obj,
				pathId,
				onChange: ( name, v ) => onChange( Object.assign( {}, obj, { [ name ]: v } ) ),
			} )
		);
	};

	controls.repeater = function ( { field, value, onChange, pathId } ) {
		const rows = Array.isArray( value ) ? value : [];
		const flexible = field.type === 'flexible';
		const layouts = field.layouts || [];
		const subsOf = ( row ) => ( flexible ? ( layoutOf( field, row ) || { sub_fields: [] } ).sub_fields : field.sub_fields || [] );
		// Structural changes remount the rows so index-based keys stay honest.
		const [ version, setVersion ] = useState( 0 );
		const [ collapsed, setCollapsed ] = useState( {} );
		const min = Number( field.min ) || 0;
		const max = Number( field.max ) || 0;

		const commit = ( next ) => {
			setVersion( ( v ) => v + 1 );
			setCollapsed( {} );
			onChange( next );
		};
		const move = ( i, d ) => {
			const next = rows.slice();
			const [ row ] = next.splice( i, 1 );
			next.splice( i + d, 0, row );
			commit( next );
		};
		const summary = ( row ) => {
			const first = subsOf( row ).find( ( s ) => [ 'text', 'textarea', 'email', 'url', 'number', 'select', 'radio' ].includes( s.type ) );
			const text = first && row ? row[ first.name ] : '';
			return typeof text === 'string' || typeof text === 'number' ? String( text ).slice( 0, 60 ) : '';
		};

		return h(
			'div',
			{ className: 'magicfields-repeater' },
			rows.map( ( row, i ) =>
				h(
					'div',
					{ key: version + '-' + i, className: 'magicfields-repeater__row' + ( collapsed[ i ] ? ' is-collapsed' : '' ) },
					h(
						'div',
						{ className: 'magicfields-repeater__head' },
						h(
							'button',
							{
								type: 'button',
								className: 'magicfields-repeater__title',
								'aria-expanded': ! collapsed[ i ],
								onClick: () => setCollapsed( Object.assign( {}, collapsed, { [ i ]: ! collapsed[ i ] } ) ),
							},
							h( 'span', { className: 'magicfields-repeater__num' }, i + 1 ),
							flexible && h( 'strong', { className: 'magicfields-repeater__layout' }, ( layoutOf( field, row ) || {} ).label || ( row && row.acf_fc_layout ) ),
							h( 'span', { className: 'magicfields-repeater__summary' }, summary( row ) )
						),
						h( C.Button, { size: 'small', icon: 'arrow-up-alt2', label: __( 'Move up', TD ), disabled: i === 0, onClick: () => move( i, -1 ) } ),
						h( C.Button, { size: 'small', icon: 'arrow-down-alt2', label: __( 'Move down', TD ), disabled: i === rows.length - 1, onClick: () => move( i, 1 ) } ),
						h( C.Button, {
							size: 'small',
							icon: 'admin-page',
							label: __( 'Duplicate', TD ),
							disabled: !! max && rows.length >= max,
							onClick: () => commit( rows.slice( 0, i + 1 ).concat( [ JSON.parse( JSON.stringify( row || {} ) ) ], rows.slice( i + 1 ) ) ),
						} ),
						h( C.Button, {
							size: 'small',
							icon: 'trash',
							label: __( 'Remove', TD ),
							isDestructive: true,
							disabled: rows.length <= min,
							onClick: () => commit( rows.filter( ( r, x ) => x !== i ) ),
						} )
					),
					! collapsed[ i ] &&
						h(
							'div',
							{ className: 'magicfields-repeater__body' },
							h( FieldList, {
								fields: subsOf( row ),
								values: row || {},
								pathId: pathId + '-' + i,
								onChange: ( name, v ) => onChange( rows.map( ( r, x ) => ( x === i ? Object.assign( {}, r, { [ name ]: v } ) : r ) ) ),
							} )
						)
				)
			),
			flexible
				? h( C.DropdownMenu, {
						icon: 'plus-alt2',
						text: field.button_label || __( 'Add row', TD ),
						label: field.button_label || __( 'Add row', TD ),
						toggleProps: { variant: 'secondary', disabled: ( !! max && rows.length >= max ) || ! layouts.length },
						controls: layouts.map( ( layout ) => ( {
							title: layout.label,
							onClick: () => onChange( rows.concat( [ Object.assign( { acf_fc_layout: layout.name }, defaultsFor( layout.sub_fields || [] ) ) ] ) ),
						} ) ),
				  } )
				: h(
						C.Button,
						{
							variant: 'secondary',
							disabled: !! max && rows.length >= max,
							onClick: () => onChange( rows.concat( [ defaultsFor( field.sub_fields || [] ) ] ) ),
						},
						field.button_label || __( 'Add row', TD )
				  )
		);
	};
	controls.flexible = controls.repeater;

	controls.message = function ( { field } ) {
		return h( 'div', { className: 'magicfields-message', dangerouslySetInnerHTML: { __html: field.message || '' } } );
	};

	/* ------------------------------------------------------------------ */
	/* Field wrapper and list                                              */
	/* ------------------------------------------------------------------ */

	function Field( { field, value, onChange, pathId } ) {
		const Control = controls[ field.type ] || controls.text;
		const id = pathId + '-' + field.name;
		const style = field.width && field.width < 100 ? { flexBasis: 'calc(' + field.width + '% - 16px)' } : undefined;
		return h(
			'div',
			{ className: 'magicfields-field magicfields-field--' + field.type, style, 'data-name': field.name },
			field.type !== 'message' || field.label
				? h(
						'label',
						{ className: 'magicfields-field__label', htmlFor: id },
						field.label,
						field.required && h( 'span', { className: 'magicfields-field__required', title: __( 'Required', TD ) }, ' *' )
				  )
				: null,
			field.instructions && h( 'p', { className: 'magicfields-field__help' }, field.instructions ),
			h( Control, { field, value, onChange, id, pathId: id } )
		);
	}

	function FieldList( { fields, values, onChange, pathId } ) {
		const [ tab, setTab ] = useState( 0 );
		const sections = [ { tab: null, fields: [] } ];
		( fields || [] ).forEach( ( field ) => {
			if ( field.type === 'tab' ) {
				sections.push( { tab: field, fields: [] } );
			} else {
				sections[ sections.length - 1 ].fields.push( field );
			}
		} );
		const tabs = sections.slice( 1 );
		const active = tabs.length ? tabs[ Math.min( tab, tabs.length - 1 ) ] : null;
		const render = ( list ) =>
			list
				.filter( ( field ) => isVisible( field, values ) )
				.map( ( field ) =>
					h( Field, {
						key: field.key || field.name,
						field,
						pathId: pathId || 'gf',
						value: values ? values[ field.name ] : undefined,
						onChange: ( v ) => onChange( field.name, v ),
					} )
				);
		return h(
			'div',
			{ className: 'magicfields-fields' },
			render( sections[ 0 ].fields ),
			tabs.length > 0 &&
				h(
					'div',
					{ className: 'magicfields-tabs', role: 'tablist' },
					tabs.map( ( s, i ) =>
						h(
							'button',
							{
								key: s.tab.key || i,
								type: 'button',
								role: 'tab',
								'aria-selected': s === active,
								className: 'magicfields-tabs__tab' + ( s === active ? ' is-active' : '' ),
								onClick: () => setTab( i ),
							},
							s.tab.label
						)
					)
				),
			active && h( Fragment, { key: 'tab-' + tab }, render( active.fields ) )
		);
	}

	/** Fields of one group, with the list of required fields still empty. */
	function GroupFields( { group, values, onChange, showMissing } ) {
		const missing = showMissing ? validate( group.fields, values ) : [];
		return h(
			'div',
			{ className: 'magicfields-root magicfields-root--' + ( group.position || 'normal' ) },
			group.description && h( 'p', { className: 'magicfields-root__description' }, group.description ),
			h( FieldList, { fields: group.fields, values, onChange, pathId: 'gf-' + group.key } ),
			missing.length > 0 &&
				h(
					'p',
					{ className: 'magicfields-missing' },
					/* translators: %s: list of field labels */
					sprintf( __( 'Required: %s', TD ), missing.join( ', ' ) )
				)
		);
	}

	window.MagicFields = {
		controls,
		Field,
		FieldList,
		GroupFields,
		validate,
		isVisible,
		isEmpty,
		hasValue,
		defaultsFor,
		TD,
	};
} )( window.wp );
