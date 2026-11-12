/**
 * MagicFields — field groups on classic forms (classic editor, terms,
 * users, options pages). Values are posted as one JSON field.
 */
( function ( wp ) {
	'use strict';

	const data = window.magicfieldsForm;
	if ( ! data || ! window.MagicFields ) {
		return;
	}
	const { createElement: h, useState, useEffect, createPortal, Fragment } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { GroupFields, validate, TD } = window.MagicFields;

	function App( { input } ) {
		const [ values, setValues ] = useState( data.values || {} );
		const [ tried, setTried ] = useState( false );

		useEffect( () => {
			input.value = JSON.stringify( values );
		}, [ values ] );

		useEffect( () => {
			const form = input.form;
			if ( ! form ) {
				return;
			}
			const onSubmit = ( event ) => {
				const current = JSON.parse( input.value || '{}' );
				const missing = data.groups.reduce( ( all, group ) => all.concat( validate( group.fields, current ) ), [] );
				if ( missing.length ) {
					event.preventDefault();
					event.stopImmediatePropagation();
					setTried( true );
					/* translators: %s: list of field labels */
					window.alert( sprintf( __( 'Please fill in the required fields: %s', TD ), missing.join( ', ' ) ) );
				}
			};
			form.addEventListener( 'submit', onSubmit, true );

			// "Add term" posts through AJAX and keeps the page: start over afterwards.
			const $ = window.jQuery;
			const onAjax = ( event, xhr, settings ) => {
				if ( settings && typeof settings.data === 'string' && settings.data.indexOf( 'action=add-tag' ) !== -1 ) {
					setValues( data.values || {} );
					setResetKey( ( k ) => k + 1 );
				}
			};
			if ( $ && form.id === 'addtag' ) {
				$( document ).on( 'ajaxSuccess', onAjax );
			}
			return () => {
				form.removeEventListener( 'submit', onSubmit, true );
				if ( $ ) {
					$( document ).off( 'ajaxSuccess', onAjax );
				}
			};
		}, [] );

		const [ resetKey, setResetKey ] = useState( 0 );

		return h(
			Fragment,
			null,
			data.groups.map( ( group ) => {
				const node = document.getElementById( 'magicfields-mount-' + group.key );
				return node
					? createPortal(
							h( GroupFields, {
								key: resetKey,
								group,
								values,
								showMissing: tried,
								onChange: ( name, v ) => setValues( ( prev ) => Object.assign( {}, prev, { [ name ]: v } ) ),
							} ),
							node,
							group.key
					  )
					: null;
			} )
		);
	}

	function start() {
		const input = document.getElementById( 'magicfields-values' );
		if ( ! input ) {
			return;
		}
		const host = document.createElement( 'div' );
		host.hidden = true;
		document.body.appendChild( host );
		const app = h( App, { input } );
		if ( wp.element.createRoot ) {
			wp.element.createRoot( host ).render( app );
		} else {
			wp.element.render( app, host );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window.wp );
