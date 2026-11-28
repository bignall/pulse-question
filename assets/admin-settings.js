/* Pulse Question settings: tabs, unsaved-change tracking, logo picker and colour pickers. */
( function ( $ ) {
	var l10n = window.pulseQuestionSettings || {};
	var form = $( '#pq-settings-form' );
	var panels = form.find( '.pq-tab-panel' );
	var tabs = form.find( '.pq-tab' );
	var bar = form.find( '.pq-savebar' );
	var status = bar.find( '.pq-savebar__status' );
	var saved = {};
	var submitting = false;
	var active = '';

	// This form's own fields in a panel (not the "create a page" ones, which belong to another form).
	function fields( panel ) {
		return $( panel ).find( ':input[name]' ).filter( function () {
			return this.form === form[ 0 ];
		} );
	}
	function snapshot( panel ) {
		return fields( panel ).serialize();
	}

	function activate( id, focus ) {
		if ( ! id || ! panels.filter( function () {
			return this.id === id;
		} ).length ) {
			id = panels.first().attr( 'id' );
		}
		active = id;
		panels.each( function () {
			this.hidden = this.id !== id;
		} );
		tabs.each( function () {
			var on = $( this ).attr( 'aria-controls' ) === id;
			$( this ).attr( { 'aria-selected': on ? 'true' : 'false', tabindex: on ? 0 : -1 } );
			if ( on ) {
				// On narrow screens the tabs scroll sideways: keep the chosen one in view.
				var strip = this.parentNode;
				if ( this.offsetLeft < strip.scrollLeft || this.offsetLeft + this.offsetWidth > strip.scrollLeft + strip.clientWidth ) {
					strip.scrollLeft = this.offsetLeft - 24;
				}
				if ( focus ) {
					this.focus();
				}
			}
		} );
	}

	// Open the tab holding the address's #anchor (links from the checklist, To-do Hub and buttons).
	function fromHash( scroll ) {
		var target = location.hash ? document.getElementById( location.hash.slice( 1 ) ) : null;
		var panel = target ? $( target ).closest( '.pq-tab-panel' ) : $();
		activate( panel.length ? panel.attr( 'id' ) : '', false );
		if ( scroll && target && panel.length ) {
			if ( target === panel[ 0 ] ) {
				form.find( '.pq-tabs' )[ 0 ].scrollIntoView( { block: 'start' } );
				window.scrollBy( 0, -46 ); // Below the admin bar.
			} else {
				target.scrollIntoView( { block: 'center' } );
			}
		}
	}

	function check() {
		var changed = [];
		panels.each( function () {
			var dirty = snapshot( this ) !== saved[ this.id ];
			var tab = tabs.filter( '[aria-controls="' + this.id + '"]' );
			tab.toggleClass( 'pq-tab--changed', dirty );
			if ( dirty ) {
				changed.push( tab.contents().first().text() );
			}
		} );
		bar.toggleClass( 'pq-savebar--changed', changed.length > 0 );
		status.text( changed.length ? ( l10n.unsaved || '%s' ).replace( '%s', changed.join( ', ' ) ) : '' );
		return changed.length > 0;
	}

	if ( form.length ) {
		form.addClass( 'pq-tabs-ready' );
		fromHash( false );
		// After the browser's own jump to the #anchor, which comes once the page has loaded.
		$( window ).on( 'load', function () {
			setTimeout( function () {
				fromHash( true );
			} );
		} );

		tabs.on( 'click', function ( e ) {
			e.preventDefault();
			activate( $( this ).attr( 'aria-controls' ), false );
			history.replaceState( null, '', '#' + active );
		} );
		form.find( '.pq-tabs' ).on( 'keydown', function ( e ) {
			var keys = { ArrowLeft: -1, ArrowRight: 1, Home: 'first', End: 'last' };
			if ( ! ( e.key in keys ) ) {
				return;
			}
			e.preventDefault();
			var i = tabs.index( tabs.filter( '[aria-selected="true"]' ) );
			var step = keys[ e.key ];
			i = 'first' === step ? 0 : 'last' === step ? tabs.length - 1 : ( i + step + tabs.length ) % tabs.length;
			activate( tabs.eq( i ).attr( 'aria-controls' ), true );
			history.replaceState( null, '', '#' + active );
		} );
		$( window ).on( 'hashchange', function () {
			fromHash( true );
		} );

		// "Settings saved." is at the top of the page, out of sight below the checklist: repeat it here.
		var done = $( '#setting-error-settings_updated p' ).first().text();
		if ( done ) {
			status.text( done );
		}

		// Fields password managers mistake for a log-in (the Google client ID, user names, secrets):
		// only typing, pasting, cutting or dropping text may change them. Anything else, such as
		// LastPass or Chrome filling them in, is put back, and again just before saving.
		var noEdit = [ 'Tab', 'Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown' ];
		var guarded = form.find( '[data-pq-guard]' ).each( function () {
			var el = this;
			el.pqGood = el.defaultValue;
			el.pqEditing = false;
			$( el ).on( 'keydown paste cut drop', function ( e ) {
				if ( e.originalEvent && e.originalEvent.isTrusted && ( 'keydown' !== e.type || -1 === noEdit.indexOf( e.key ) ) ) {
					el.pqEditing = true;
				}
			} );
			el.addEventListener( 'input', function ( e ) {
				// Typing, dictation, undo and so on come from the browser itself with an inputType;
				// a script's value has none, and browser autofill is a "replacement".
				var typed = e.isTrusted && e.inputType && 'insertReplacementText' !== e.inputType;
				if ( el.pqEditing || typed ) {
					el.pqGood = el.value;
					el.pqEditing = false;
				}
			}, true );
		} );
		function restore() {
			guarded.each( function () {
				if ( this.value !== this.pqGood ) {
					this.value = this.pqGood;
				}
			} );
		}
		restore();
		form.on( 'input change', function () {
			restore();
			check();
		} );
		// Some fill without any event, so look again now and then.
		setInterval( function () {
			if ( guarded.filter( function () {
				return this.value !== this.pqGood;
			} ).length ) {
				restore();
				check();
			}
		}, 400 );
		$( window ).on( 'beforeunload', function ( e ) {
			if ( ! submitting && check() ) {
				e.preventDefault();
				e.originalEvent.returnValue = '';
				return '';
			}
		} );

		// Save comes back to the same tab, and Cmd/Ctrl+S saves.
		form.on( 'submit', function () {
			restore();
			submitting = true;
			var search = location.search.replace( /([?&])settings-updated=[^&]*&?/, '$1' ).replace( /[?&]$/, '' );
			form.find( 'input[name="_wp_http_referer"]' ).val( location.pathname + search + '#' + active );
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( ( e.metaKey || e.ctrlKey ) && 's' === String( e.key ).toLowerCase() ) {
				e.preventDefault();
				form.find( '#submit' ).trigger( 'click' );
			}
		} );
	}

	$( '.pq-color' ).wpColorPicker( {
		change: function () {
			setTimeout( check );
		},
		clear: function () {
			setTimeout( check );
		},
	} );
	panels.each( function () {
		saved[ this.id ] = snapshot( this );
	} );

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
					input.val( image.id ).trigger( 'change' );
					var size = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail : image;
					img.attr( 'src', size.url ).show();
					clear.show();
				} );
			}
			picker.open();
		} );
		cell.on( 'click', '.pq-media-clear', function ( e ) {
			e.preventDefault();
			input.val( 0 ).trigger( 'change' );
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
				value.val( image.id ).trigger( 'change' );
				var size = image.sizes && image.sizes.medium ? image.sizes.medium : image;
				preview.attr( 'src', size.url ).show();
				box.find( 'input[name="pulse_question_logo_mode"][value="image"]' ).prop( 'checked', true );
			} );
		}
		frame.open();
	} );
} )( jQuery );
