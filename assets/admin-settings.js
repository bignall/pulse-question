/* Pulse Question settings: logo picker and colour pickers. */
( function ( $ ) {
	$( '.pq-color' ).wpColorPicker();

	var box = $( '.pq-logo-choice' );
	if ( ! box.length ) {
		return;
	}
	var value = box.find( '.pq-logo-value' );
	var preview = box.find( '.pq-logo-preview' );
	var frame;

	box.on( 'change', 'input[name="pq_logo_mode"]', function () {
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
				box.find( 'input[name="pq_logo_mode"][value="image"]' ).prop( 'checked', true );
			} );
		}
		frame.open();
	} );
} )( jQuery );
