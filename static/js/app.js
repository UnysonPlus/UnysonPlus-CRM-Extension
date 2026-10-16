/**
 * CRM screen behaviour: the "/" search shortcut, the New menu, in-place edit
 * panels, auto-submitting pickers and the dialogs.
 *
 * Focus mode and the off-canvas sidebar belong to the app shell and are handled
 * by the framework's admin-app.js — this file must not duplicate them.
 */
( function () {
	'use strict';

	var app = document.getElementById( 'fw-app' );
	if ( ! app ) {
		return;
	}

	/* The New menu closes on an outside click or Escape. */
	var newMenu = app.querySelector( '.fw-crm-new' );

	document.addEventListener( 'click', function ( e ) {
		if ( newMenu && newMenu.open && ! newMenu.contains( e.target ) ) {
			newMenu.open = false;
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			if ( newMenu && newMenu.open ) {
				newMenu.open = false;
				newMenu.querySelector( 'summary' ).focus();
			}
			return;
		}
		/* "/" jumps to search, unless the person is typing somewhere. */
		if ( '/' === e.key && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
			var t = e.target;
			if ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) ) {
				return;
			}
			var q = document.getElementById( 'fw-crm-q' );
			if ( q ) {
				e.preventDefault();
				q.focus();
				q.select();
			}
		}
	} );

	/* Edit in place: a panel holds a read view and a hidden form. */
	app.querySelectorAll( '[data-edit-toggle]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var panel = document.getElementById( btn.getAttribute( 'data-edit-toggle' ) );
			if ( ! panel ) {
				return;
			}
			var view = panel.querySelector( '[data-view]' );
			var form = panel.querySelector( '[data-edit]' );
			var editing = form && form.hidden;
			if ( form ) {
				form.hidden = ! editing;
			}
			if ( view ) {
				view.hidden = editing;
			}
			if ( editing ) {
				panel.scrollIntoView( { block: 'nearest' } );
				var first = form.querySelector( 'input:not([type=hidden]), select, textarea' );
				if ( first ) {
					first.focus();
				}
			}
		} );
	} );

	/* Pickers that save as soon as they change (the stage on a profile). */
	app.querySelectorAll( 'form[data-autosubmit] select' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	} );

	/* Screen Options belong to the contacts list: move them into the content
	   column there, where they sit beside the table they configure. */
	var listbar = app.querySelector( '.fw-crm-listbar' );
	var links = document.getElementById( 'screen-meta-links' );
	var meta = document.getElementById( 'screen-meta' );
	if ( listbar && links && document.querySelector( '.fw-crm-body input[name="tab"][value="subscribers"]' ) ) {
		var body = app.querySelector( '.fw-crm-body' );
		if ( meta ) {
			body.insertBefore( meta, listbar );
		}
		body.insertBefore( links, listbar );
	}

	/* Task forms: duration, place and attendees only for calls and meetings. */
	app.querySelectorAll( '[data-task-form]' ).forEach( function ( form ) {
		var type = form.querySelector( '[data-task-type]' );
		if ( ! type ) {
			return;
		}
		var sync = function () {
			form.querySelectorAll( '[data-for-types]' ).forEach( function ( el ) {
				el.hidden = -1 === el.dataset.forTypes.split( ' ' ).indexOf( type.value );
			} );
		};
		type.addEventListener( 'change', sync );
		sync();
	} );

	/* Reports: the date fields only for a custom period. */
	var period = app.querySelector( '[data-report-period]' );
	var dates = app.querySelector( '[data-report-dates]' );
	if ( period && dates ) {
		period.addEventListener( 'change', function () {
			dates.hidden = 'custom' !== period.value;
		} );
	}

	/* Custom field form: the key follows the label until typed; options follow the type. */
	app.querySelectorAll( '[data-field-form]' ).forEach( function ( form ) {
		var label = form.querySelector( '[data-field-label]' );
		var key = form.querySelector( '[data-field-key]' );
		var type = form.querySelector( '[data-field-type]' );
		if ( label && key ) {
			var touched = false;
			key.addEventListener( 'input', function () {
				touched = true;
			} );
			label.addEventListener( 'input', function () {
				if ( ! touched ) {
					key.value = label.value.toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).replace( /[^a-z0-9]+/g, '_' ).replace( /^[^a-z]+|_+$/g, '' ).slice( 0, 64 );
				}
			} );
		}
		if ( type ) {
			var sync = function () {
				form.querySelectorAll( '[data-for-field]' ).forEach( function ( el ) {
					el.hidden = -1 === el.dataset.forField.split( ' ' ).indexOf( type.value );
				} );
			};
			type.addEventListener( 'change', sync );
			sync();
		}
	} );

	/* Copy buttons: data-copy="<selector of the field>". */
	app.querySelectorAll( '[data-copy]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var field = document.querySelector( btn.dataset.copy );
			if ( ! field ) {
				return;
			}
			var done = function () {
				var label = btn.textContent;
				btn.textContent = btn.dataset.copied || '\u2713';
				setTimeout( function () {
					btn.textContent = label;
				}, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( field.value ).then( done );
			} else {
				field.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );

	/* Buttons that open a <dialog> (the email composer...); a draft reopens at once. */
	app.querySelectorAll( '[data-open-dialog]' ).forEach( function ( btn ) {
		var dlg = document.getElementById( btn.dataset.openDialog );
		if ( ! dlg || 'function' !== typeof dlg.showModal ) {
			btn.hidden = true;
			return;
		}
		var open = function () {
			dlg.showModal();
			var first = dlg.querySelector( 'input:not([type=hidden]):not([readonly]), select, textarea' );
			if ( first ) {
				first.focus();
			}
		};
		btn.addEventListener( 'click', open );
		dlg.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-close]' ) || e.target === dlg ) {
				dlg.close();
			}
		} );
		dlg.addEventListener( 'close', function () {
			btn.focus();
		} );
		if ( btn.hasAttribute( 'data-open-now' ) ) {
			open();
		}
	} );

	/* Ticking off a call or meeting asks for the outcome and how long it took. */
	var done = document.getElementById( 'fw-crm-task-done' );
	if ( done && 'function' === typeof done.showModal ) {
		var opener = null;
		app.addEventListener( 'submit', function ( e ) {
			var form = e.target.closest( '.fw-crm-task__check[data-ask]' );
			if ( ! form ) {
				return;
			}
			e.preventDefault();
			opener = form.querySelector( 'button' );
			var fields = done.querySelector( '[data-fields]' );
			fields.textContent = '';
			form.querySelectorAll( 'input[type=hidden]' ).forEach( function ( input ) {
				fields.appendChild( input.cloneNode() );
			} );
			done.querySelector( '[data-title]' ).textContent = form.dataset.title || '';
			done.querySelector( 'textarea' ).value = '';
			var select = done.querySelector( 'select[name=duration]' );
			select.value = select.querySelector( 'option[value="' + ( form.dataset.duration || '0' ) + '"]' ) ? form.dataset.duration : '0';
			done.showModal();
			done.querySelector( 'textarea' ).focus();
		} );
		done.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-close]' ) || e.target === done ) {
				done.close();
			}
		} );
		done.addEventListener( 'close', function () {
			if ( opener ) {
				opener.focus();
				opener = null;
			}
		} );
	}
}() );
