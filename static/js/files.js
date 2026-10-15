/**
 * Files panel: "Choose from the media library" opens the WordPress media frame
 * (several at once) and adds the picked files to the record. Without the media
 * frame the plain upload field still works.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-files-form]' ).forEach( function ( form ) {
		var library = form.querySelector( '[data-files-library]' );
		var picked = form.querySelector( '[data-files-picked]' );
		var input = form.querySelector( '[data-files-input]' );
		var upload = form.querySelector( '[data-files-upload]' );

		// The upload button waits for a file to be chosen.
		if ( input && upload ) {
			var sync = function () {
				upload.disabled = ! input.files || ! input.files.length;
			};
			input.addEventListener( 'change', sync );
			sync();
		}

		if ( ! library || ! window.wp || ! wp.media ) {
			return;
		}
		library.hidden = false;
		var frame = null;
		library.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: library.textContent,
					multiple: true,
					button: { text: library.dataset.button || library.textContent }
				} );
				frame.on( 'select', function () {
					var ids = frame.state().get( 'selection' ).map( function ( a ) {
						return a.id;
					} );
					if ( ids.length ) {
						picked.value = ids.join( ',' );
						if ( input ) {
							input.value = '';
						}
						form.submit();
					}
				} );
			}
			frame.open();
		} );
	} );
}() );
