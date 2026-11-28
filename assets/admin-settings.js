/*
 * Pulse Question settings: the logo picker. Tabs, saving, unsaved changes, the colour and
 * home screen icon pickers and the autofill guard come from WP Foundation's settings page.
 */
( function ( $ ) {
	var box = $( '.pq-logo-choice' );
	if ( ! box.length ) {
		return;
	}
	var value = box.find( '.pq-logo-value' );
	var preview = box.find( '.pq-logo-preview' );
	var frame;

	// A native event, so the settings page sees the hidden value change.
	function changed() {
		value[ 0 ].dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	box.on( 'change', 'input[name="pulse_question_logo_mode"]', function () {
		var mode = this.value;
		if ( 'auto' === mode ) {
			value.val( 0 );
			preview.hide();
		} else if ( 'bundled' === mode ) {
			value.val( -1 );
			preview.hide();
		} else if ( parseInt( value.val(), 10 ) <= 0 ) {
			box.find( '.pq-logo-pick' ).trigger( 'click' );
		}
	} );

	box.on( 'click', '.pq-logo-pick', function ( e ) {
		e.preventDefault();
		if ( ! frame ) {
			frame = wp.media( {
				title: pulseQuestionSettings.chooseLogo,
				button: { text: pulseQuestionSettings.useLogo },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var image = frame.state().get( 'selection' ).first().toJSON();
				value.val( image.id );
				var size = image.sizes && image.sizes.medium ? image.sizes.medium : image;
				preview.attr( 'src', size.url ).show();
				box.find( 'input[name="pulse_question_logo_mode"][value="image"]' ).prop( 'checked', true );
				changed();
			} );
		}
		frame.open();
	} );
} )( jQuery );
