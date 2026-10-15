/**
 * CRM Home: "Customise Home" lets each person reorder, move and hide cards.
 * Drag a card by its handle (mouse), or use the buttons on it (keyboard and
 * touch). Every change is saved at once for the current user only.
 */
( function () {
	'use strict';

	var home = document.getElementById( 'fw-crm-home' );
	var toggle = document.querySelector( '[data-home-customise]' );
	var panel = document.querySelector( '[data-home-edit]' );
	if ( ! home || ! toggle || ! panel ) {
		return;
	}
	var t = JSON.parse( home.dataset.i18n || '{}' );
	var live = home.querySelector( '[data-home-live]' );
	var hidden = home.dataset.hidden ? home.dataset.hidden.split( ',' ) : [];
	var dragging = null;

	function cols() {
		return Array.prototype.slice.call( home.querySelectorAll( '[data-home-col]' ) );
	}

	function say( text ) {
		if ( live ) {
			live.textContent = text;
		}
	}

	function save( extra, after ) {
		var body = new FormData();
		body.append( 'action', 'fw_crm_home_layout' );
		body.append( '_ajax_nonce', home.dataset.nonce );
		if ( extra && extra.reset ) {
			body.append( 'reset', '1' );
		} else {
			cols().forEach( function ( col ) {
				body.append( col.dataset.homeCol, Array.prototype.map.call( col.querySelectorAll( ':scope > .fw-crm-card' ), function ( c ) {
					return c.dataset.card;
				} ).join( ',' ) );
			} );
			body.append( 'hidden', hidden.join( ',' ) );
		}
		return fetch( home.dataset.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( 'failed' );
				}
				if ( after ) {
					after();
				}
			} )
			.catch( function () {
				say( t.failed || '' );
				window.alert( t.failed || 'Not saved.' );
			} );
	}

	function button( label, icon, action ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'fw-crm-card__btn';
		b.dataset.cardAction = action;
		b.setAttribute( 'aria-label', label );
		b.title = label;
		b.innerHTML = '<span class="dashicons ' + icon + '" aria-hidden="true"></span>';
		return b;
	}

	// Controls on every card (shown only while customising).
	home.querySelectorAll( '.fw-crm-card' ).forEach( function ( card ) {
		var bar = document.createElement( 'div' );
		bar.className = 'fw-crm-card__tools';
		var handle = document.createElement( 'span' );
		handle.className = 'fw-crm-card__handle';
		handle.setAttribute( 'aria-hidden', 'true' );
		handle.innerHTML = '<span class="dashicons dashicons-move"></span>';
		var name = document.createElement( 'span' );
		name.className = 'fw-crm-card__name';
		name.textContent = card.dataset.title;
		bar.appendChild( handle );
		bar.appendChild( name );
		bar.appendChild( button( t.up + ': ' + card.dataset.title, 'dashicons-arrow-up-alt2', 'up' ) );
		bar.appendChild( button( t.down + ': ' + card.dataset.title, 'dashicons-arrow-down-alt2', 'down' ) );
		bar.appendChild( button( t.toMain + ': ' + card.dataset.title, 'dashicons-leftright', 'swap' ) );
		bar.appendChild( button( t.hide + ': ' + card.dataset.title, 'dashicons-hidden', 'hide' ) );
		card.insertBefore( bar, card.firstChild );
	} );

	function setMode( on ) {
		home.classList.toggle( 'is-customising', on );
		panel.hidden = ! on;
		toggle.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		home.querySelectorAll( '.fw-crm-card' ).forEach( function ( card ) {
			card.draggable = on;
		} );
		home.querySelectorAll( '[data-card-action="swap"]' ).forEach( function ( b ) {
			var inMain = 'main' === b.closest( '[data-home-col]' ).dataset.homeCol;
			var title = b.closest( '.fw-crm-card' ).dataset.title;
			b.setAttribute( 'aria-label', ( inMain ? t.toSide : t.toMain ) + ': ' + title );
			b.title = inMain ? t.toSide : t.toMain;
		} );
	}

	toggle.addEventListener( 'click', function () {
		setMode( ! home.classList.contains( 'is-customising' ) );
	} );
	panel.querySelector( '[data-home-done]' ).addEventListener( 'click', function () {
		setMode( false );
		toggle.focus();
	} );
	panel.querySelector( '[data-home-reset]' ).addEventListener( 'click', function () {
		save( { reset: true }, function () {
			window.location.reload();
		} );
	} );
	panel.querySelectorAll( '[data-home-show]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			hidden = hidden.filter( function ( id ) {
				return id !== b.dataset.homeShow;
			} );
			// The card is placed at the end of its column; its content needs a reload.
			save( null, function () {
				window.location.reload();
			} );
		} );
	} );

	home.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-card-action]' );
		if ( ! b ) {
			return;
		}
		var card = b.closest( '.fw-crm-card' );
		var col = card.parentNode;
		var title = card.dataset.title;
		switch ( b.dataset.cardAction ) {
			case 'up':
				if ( card.previousElementSibling && card.previousElementSibling.classList.contains( 'fw-crm-card' ) ) {
					col.insertBefore( card, card.previousElementSibling );
				}
				break;
			case 'down':
				if ( card.nextElementSibling && card.nextElementSibling.classList.contains( 'fw-crm-card' ) ) {
					col.insertBefore( card.nextElementSibling, card );
				}
				break;
			case 'swap':
				var other = cols().filter( function ( c ) {
					return c !== col;
				} )[ 0 ];
				other.appendChild( card );
				setMode( true );
				break;
			case 'hide':
				hidden.push( card.dataset.card );
				card.remove();
				save( null );
				say( ( t.hidden || '%s' ).replace( '%s', title ) );
				return;
		}
		b.focus();
		save( null );
		say( ( t.moved || '%s' ).replace( '%s', title ) );
	} );

	// Mouse drag and drop between and within the columns.
	home.addEventListener( 'dragstart', function ( e ) {
		var card = e.target.closest && e.target.closest( '.fw-crm-card' );
		if ( ! card || ! home.classList.contains( 'is-customising' ) ) {
			return;
		}
		dragging = card;
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', card.dataset.card );
		requestAnimationFrame( function () {
			card.classList.add( 'is-dragging' );
		} );
	} );
	home.addEventListener( 'dragover', function ( e ) {
		var col = e.target.closest && e.target.closest( '[data-home-col]' );
		if ( ! dragging || ! col ) {
			return;
		}
		e.preventDefault();
		var after = null;
		col.querySelectorAll( ':scope > .fw-crm-card:not(.is-dragging)' ).forEach( function ( c ) {
			var box = c.getBoundingClientRect();
			if ( null === after && e.clientY < box.top + box.height / 2 ) {
				after = c;
			}
		} );
		if ( after ) {
			col.insertBefore( dragging, after );
		} else {
			col.appendChild( dragging );
		}
	} );
	home.addEventListener( 'drop', function ( e ) {
		if ( dragging ) {
			e.preventDefault();
		}
	} );
	home.addEventListener( 'dragend', function () {
		if ( ! dragging ) {
			return;
		}
		dragging.classList.remove( 'is-dragging' );
		var title = dragging.dataset.title;
		dragging = null;
		setMode( true );
		save( null );
		say( ( t.moved || '%s' ).replace( '%s', title ) );
	} );
}() );
