/* Pulse Question settings: logo picker and colour pickers. */
( function ( $ ) {
	$( '.pq-color' ).wpColorPicker();

	// A single image (the home screen icon): choose, or clear to use the site icon.
	$( '.pq-media-choice' ).each( function () {
		var cell = $( this );
		var input = cell.find( '.pq-media-value' );
		var img = cell.find( '.pq-media-preview' );
		var clear = cell.find( '.pq-media-clear' );
		var picker;
		cell.on( 'click', '.pq-media-pick', function ( e ) {
			e.preventDefault();
			if ( ! picker ) {
				picker = wp.media( {
					title: pulseQuestionSettings.chooseIcon,
					button: { text: pulseQuestionSettings.useIcon },
					library: { type: 'image' },
					multiple: false,
				} );
				picker.on( 'select', function () {
					var image = picker.state().get( 'selection' ).first().toJSON();
					input.val( image.id );
					var size = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail : image;
					img.attr( 'src', size.url ).show();
					clear.show();
				} );
			}
			picker.open();
		} );
		cell.on( 'click', '.pq-media-clear', function ( e ) {
			e.preventDefault();
			input.val( 0 );
			img.hide();
			clear.hide();
		} );
	} );

	var box = $( '.pq-logo-choice' );
	if ( ! box.length ) {
		return;
	}
	var value = box.find( '.pq-logo-value' );
	var preview = box.find( '.pq-logo-preview' );
	var frame;

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
			} );
		}
		frame.open();
	} );
} )( jQuery );
