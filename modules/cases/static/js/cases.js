/**
 * The cases board: drag a card to another status and it is saved at once; the column counts
 * come back from the server. A failed save puts the card back. Every card is also a link to
 * the case page, where the status can be changed without dragging.
 */
( function () {
	'use strict';

	var board = document.getElementById( 'fw-crm-case-board' );
	if ( ! board ) {
		return;
	}
	var t = JSON.parse( board.dataset.i18n || '{}' );
	var live = document.createElement( 'p' );
	live.className = 'screen-reader-text';
	live.setAttribute( 'aria-live', 'polite' );
	board.appendChild( live );
	var dragging = null;
	var origin = null;
	var originNext = null;

	board.addEventListener( 'dragstart', function ( e ) {
		var card = e.target.closest( '.fw-crm-case-card' );
		if ( ! card ) {
			return;
		}
		dragging = card;
		origin = card.parentNode;
		originNext = card.nextSibling;
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', card.dataset.deal );
		requestAnimationFrame( function () {
			card.classList.add( 'is-dragging' );
		} );
		board.classList.add( 'is-dragging' );
	} );

	board.addEventListener( 'dragend', function () {
		if ( dragging ) {
			dragging.classList.remove( 'is-dragging' );
		}
		dragging = null;
		board.classList.remove( 'is-dragging' );
		board.querySelectorAll( '.is-over' ).forEach( function ( el ) {
			el.classList.remove( 'is-over' );
		} );
	} );

	board.addEventListener( 'dragover', function ( e ) {
		var list = e.target.closest( '.fw-crm-board__list' );
		if ( ! dragging || ! list ) {
			return;
		}
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		board.querySelectorAll( '.is-over' ).forEach( function ( el ) {
			if ( el !== list ) {
				el.classList.remove( 'is-over' );
			}
		} );
		list.classList.add( 'is-over' );
		var before = null;
		list.querySelectorAll( '.fw-crm-case-card:not(.is-dragging)' ).forEach( function ( c ) {
			var box = c.getBoundingClientRect();
			if ( null === before && e.clientY < box.top + box.height / 2 ) {
				before = c;
			}
		} );
		if ( before ) {
			list.insertBefore( dragging, before );
		} else {
			list.appendChild( dragging );
		}
	} );

	board.addEventListener( 'drop', function ( e ) {
		var list = e.target.closest( '.fw-crm-board__list' );
		if ( ! dragging || ! list ) {
			return;
		}
		e.preventDefault();
		list.classList.remove( 'is-over' );
		var col = list.closest( '.fw-crm-board__col' );
		var order = Array.prototype.map.call( list.querySelectorAll( '.fw-crm-case-card' ), function ( c ) {
			return c.dataset.deal;
		} ).join( ',' );
		save( dragging, col.dataset.stage, order, col.querySelector( 'h2' ).textContent );
	} );

	function revert( card ) {
		if ( originNext && originNext.parentNode === origin ) {
			origin.insertBefore( card, originNext );
		} else {
			origin.appendChild( card );
		}
	}

	function save( card, status, order, statusName ) {
		var body = new FormData();
		body.append( 'action', 'fw_crm_case_move' );
		body.append( '_ajax_nonce', board.dataset.nonce );
		body.append( 'case', card.dataset.deal );
		body.append( 'status', status );
		body.append( 'order', order );
		// The filters on the screen, so the counts that come back match what is shown.
		new URLSearchParams( window.location.search ).forEach( function ( v, k ) {
			if ( -1 !== [ 'owner', 'priority', 'age', 's' ].indexOf( k ) ) {
				body.append( k, v );
			}
		} );
		fetch( board.dataset.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( 'failed' );
				}
				Object.keys( res.data.counts ).forEach( function ( k ) {
					var c = board.querySelector( '.fw-crm-board__col[data-stage="' + k + '"] .fw-crm-board__count' );
					if ( c ) {
						c.textContent = res.data.counts[ k ];
					}
				} );
				live.textContent = ( t.moved || '%1$s → %2$s' ).replace( '%1$s', card.dataset.title ).replace( '%2$s', statusName );
			} )
			.catch( function () {
				revert( card );
				live.textContent = t.failed || '';
				window.alert( t.failed || 'Not saved.' );
			} );
	}
}() );
