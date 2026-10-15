/**
 * CRM calendar: the "+" on a day opens the new-task dialog with that day filled
 * in (09:00, or the next full hour today). Without <dialog> support the "+" is
 * a plain link to the Tasks screen.
 */
( function () {
	'use strict';

	var cal = document.getElementById( 'fw-crm-cal' );
	var dialog = document.getElementById( 'fw-crm-cal-add' );
	if ( ! cal || ! dialog || 'function' !== typeof dialog.showModal ) {
		return;
	}
	var due = dialog.querySelector( '[name="due_at"]' );
	var title = dialog.querySelector( '[name="title"]' );
	var opener = null;

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	cal.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '.fw-crm-cal__add' );
		if ( ! add ) {
			return;
		}
		e.preventDefault();
		opener = add;
		var date = add.dataset.date;
		var now = new Date();
		var today = now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() );
		var hour = date === today ? Math.min( 23, Math.max( 9, now.getHours() + 1 ) ) : 9;
		due.value = date + 'T' + pad( hour ) + ':00';
		title.value = '';
		dialog.showModal();
		title.focus();
	} );

	dialog.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-close]' ) || e.target === dialog ) {
			dialog.close();
		}
	} );

	dialog.addEventListener( 'close', function () {
		if ( opener ) {
			opener.focus();
			opener = null;
		}
	} );
}() );
