/* Pulse Question: "Copy link", and the phone's share sheet where the browser has one. */
( function () {
	document.querySelectorAll( '.pq-share' ).forEach( function ( box ) {
		var url = box.getAttribute( 'data-pq-share-url' );
		var title = box.getAttribute( 'data-pq-share-title' );
		var status = box.querySelector( '.pq-share__copied' );
		var native = box.querySelector( '[data-pq-share-native]' );

		if ( native && navigator.share ) {
			native.hidden = false;
			native.addEventListener( 'click', function () {
				navigator.share( { title: title, url: url } ).catch( function () {} );
			} );
		}

		var copy = box.querySelector( '[data-pq-copy]' );
		if ( ! copy ) {
			return;
		}
		copy.addEventListener( 'click', function () {
			var done = function () {
				status.textContent = copy.getAttribute( 'data-copied' ) || 'Link copied';
				setTimeout( function () { status.textContent = ''; }, 2500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( done, function () { window.prompt( 'Copy this link:', url ); } );
			} else {
				window.prompt( 'Copy this link:', url );
			}
		} );
	} );
} )();
