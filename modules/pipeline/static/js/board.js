/**
 * The deals board: drag a card to another stage (or onto Won / Lost) and it is
 * saved at once; column totals update from the server's answer. Dropping on Lost
 * asks for a reason first. A failed save puts the card back. Every card is also a
 * link to the deal page, where the stage can be changed without dragging.
 *
 * Also: add and remove stage rows in the pipeline editor.
 */
( function () {
	'use strict';

	/* ---- Pipeline editor ---------------------------------------------- */
	var table = document.getElementById( 'fw-crm-stage-table' );
	var add = document.getElementById( 'fw-crm-add-stage' );
	if ( table && add ) {
		var body = table.querySelector( 'tbody' );
		add.addEventListener( 'click', function () {
			var rows = body.querySelectorAll( 'tr' );
			var last = rows[ rows.length - 1 ];
			var row = last.cloneNode( true );
			var index = Date.now();
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.name = input.name.replace( /stages\[[^\]]+\]/, 'stages[' + index + ']' );
				if ( input.id ) {
					input.id = input.id.replace( /\d+$/, String( index ) );
				}
				input.value = 'hidden' === input.type ? '0' : ( 'number' === input.type ? '0' : '' );
			} );
			row.querySelectorAll( 'label' ).forEach( function ( label ) {
				label.setAttribute( 'for', label.getAttribute( 'for' ).replace( /\d+$/, String( index ) ) );
			} );
			body.appendChild( row );
			var name = row.querySelector( 'input[type=text]' );
			if ( name ) {
				name.focus();
			}
		} );
		body.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-remove-row]' );
			if ( btn && body.querySelectorAll( 'tr' ).length > 1 ) {
				btn.closest( 'tr' ).remove();
			}
		} );
	}

	/* ---- Deal products: the picked product fills the price ----------- */
	document.querySelectorAll( '[data-item-form]' ).forEach( function ( form ) {
		var pick = form.querySelector( '[data-item-product]' );
		var price = form.querySelector( '[data-item-price]' );
		var custom = form.querySelector( '[data-item-custom]' );
		if ( ! pick ) {
			return;
		}
		pick.addEventListener( 'change', function () {
			var opt = pick.options[ pick.selectedIndex ];
			var isCustom = 'custom' === pick.value;
			if ( custom ) {
				custom.hidden = ! isCustom;
				custom.querySelector( 'input' ).required = isCustom;
			}
			if ( price ) {
				price.value = isCustom ? '' : ( opt.dataset.price || '' );
			}
		} );
	} );

	/* ---- Board -------------------------------------------------------- */
	var board = document.getElementById( 'fw-crm-board' );
	if ( ! board ) {
		return;
	}
	var t = JSON.parse( board.dataset.i18n || '{}' );
	var live = document.createElement( 'p' );
	live.className = 'screen-reader-text';
	live.setAttribute( 'aria-live', 'polite' );
	board.appendChild( live );
	var dialog = document.getElementById( 'fw-crm-lost-dialog' );
	var dragging = null;
	var origin = null;
	var originNext = null;

	board.addEventListener( 'dragstart', function ( e ) {
		var card = e.target.closest( '.fw-crm-deal-card' );
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
		board.classList.remove( 'is-dragging' );
		board.querySelectorAll( '.is-over' ).forEach( function ( el ) {
			el.classList.remove( 'is-over' );
		} );
	} );

	function targetOf( e ) {
		return e.target.closest( '.fw-crm-board__list, .fw-crm-board__drop' );
	}

	board.addEventListener( 'dragover', function ( e ) {
		var target = targetOf( e );
		if ( ! dragging || ! target ) {
			return;
		}
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		board.querySelectorAll( '.is-over' ).forEach( function ( el ) {
			if ( el !== target ) {
				el.classList.remove( 'is-over' );
			}
		} );
		target.classList.add( 'is-over' );
		if ( target.classList.contains( 'fw-crm-board__list' ) ) {
			var after = null;
			target.querySelectorAll( '.fw-crm-deal-card:not(.is-dragging)' ).forEach( function ( c ) {
				var box = c.getBoundingClientRect();
				if ( null === after && e.clientY < box.top + box.height / 2 ) {
					after = c;
				}
			} );
			if ( after ) {
				target.insertBefore( dragging, after );
			} else {
				target.appendChild( dragging );
			}
		}
	} );

	board.addEventListener( 'drop', function ( e ) {
		var target = targetOf( e );
		if ( ! dragging || ! target ) {
			return;
		}
		e.preventDefault();
		target.classList.remove( 'is-over' );
		var card = dragging;
		var drop = target.classList.contains( 'fw-crm-board__drop' );
		var stage = drop ? target.dataset.stage : target.closest( '.fw-crm-board__col' ).dataset.stage;
		var stageName = drop ? target.textContent.trim() : target.closest( '.fw-crm-board__col' ).querySelector( 'h2' ).textContent;

		if ( drop && 'lost' === target.dataset.kind ) {
			askReason( function ( reason ) {
				if ( null === reason ) {
					revert( card );
					return;
				}
				card.remove();
				save( card, stage, reason, '', stageName );
			} );
			return;
		}
		if ( drop ) {
			card.remove();
			save( card, stage, '', '', stageName );
			return;
		}
		var order = Array.prototype.map.call( target.querySelectorAll( '.fw-crm-deal-card' ), function ( c ) {
			return c.dataset.deal;
		} ).join( ',' );
		save( card, stage, '', order, stageName );
	} );

	function askReason( done ) {
		if ( ! dialog || 'function' !== typeof dialog.showModal ) {
			done( '' );
			return;
		}
		dialog.returnValue = '';
		dialog.showModal();
		dialog.addEventListener( 'close', function handler() {
			dialog.removeEventListener( 'close', handler );
			done( 'ok' === dialog.returnValue ? dialog.querySelector( 'select' ).value : null );
		} );
	}

	function revert( card ) {
		if ( originNext && originNext.parentNode === origin ) {
			origin.insertBefore( card, originNext );
		} else {
			origin.appendChild( card );
		}
	}

	function save( card, stage, reason, order, stageName ) {
		var body = new FormData();
		body.append( 'action', 'fw_crm_deal_move' );
		body.append( '_ajax_nonce', board.dataset.nonce );
		body.append( 'deal', card.dataset.deal );
		body.append( 'stage', stage );
		body.append( 'mine', board.dataset.mine );
		if ( reason ) {
			body.append( 'reason', reason );
		}
		if ( order ) {
			body.append( 'order', order );
		}
		fetch( board.dataset.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( res && res.data && res.data.message ? res.data.message : 'failed' );
				}
				Object.keys( res.data.totals ).forEach( function ( id ) {
					var col = board.querySelector( '.fw-crm-board__col[data-stage="' + id + '"]' );
					if ( col ) {
						col.querySelector( '.fw-crm-board__count' ).textContent = res.data.totals[ id ].count;
						col.querySelector( '.fw-crm-board__value' ).textContent = res.data.totals[ id ].value;
					}
				} );
				live.textContent = ( t.moved || '%1$s → %2$s' ).replace( '%1$s', card.dataset.title ).replace( '%2$s', stageName );
			} )
			.catch( function () {
				revert( card );
				live.textContent = t.failed || '';
				window.alert( t.failed || 'Not saved.' );
			} );
	}
}() );
