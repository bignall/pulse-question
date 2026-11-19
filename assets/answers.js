/* Pulse Question answers: votes and reports without a page reload, and replying to an answer.
 * Every action is also a plain form, so the page works without this script. */
( function () {
	const cfg = window.pulseQuestion || {};
	const section = document.getElementById( 'pq-answers' );
	if ( ! section ) {
		return;
	}

	const post = ( path, data ) =>
		fetch( cfg.api + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( data ),
		} ).then( ( r ) => r.json().then( ( body ) => ( r.ok ? body : Promise.reject( body ) ) ) );

	// Votes.
	section.addEventListener( 'submit', ( event ) => {
		const form = event.target;
		if ( form.classList.contains( 'pq-vote-form' ) ) {
			event.preventDefault();
			const button = form.querySelector( '[data-pq-vote]' );
			button.disabled = true;
			post( 'vote', { type: button.dataset.pqVote, id: Number( button.dataset.pqId ) } )
				.then( ( result ) => {
					button.setAttribute( 'aria-pressed', result.voted ? 'true' : 'false' );
					button.querySelector( '.pq-vote__count' ).textContent = String( result.count );
				} )
				.catch( () => form.submit() )
				.finally( () => {
					button.disabled = false;
				} );
		}
		if ( form.classList.contains( 'pq-report' ) ) {
			event.preventDefault();
			if ( ! window.confirm( cfg.reportAsk ) ) {
				return;
			}
			const id = Number( form.querySelector( '[name=id]' ).value );
			post( 'report', { id } )
				.then( ( result ) => {
					const note = document.createElement( 'span' );
					note.className = 'pq-answer__reported';
					note.textContent = cfg.reportThanks;
					form.replaceWith( note );
					if ( result.hidden ) {
						const answer = document.getElementById( 'comment-' + id );
						if ( answer ) {
							answer.querySelector( '.pq-answer__text' ).style.opacity = '0.4';
						}
					}
				} )
				.catch( () => form.submit() );
		}
	} );

	// Replying: the form remembers which answer, and says so.
	const answerForm = section.querySelector( '.pq-answer-form' );
	if ( ! answerForm ) {
		return;
	}
	const parent = answerForm.querySelector( '[name=comment_parent]' );
	const replying = answerForm.querySelector( '.pq-answer-form__replying' );
	const text = answerForm.querySelector( 'textarea' );
	section.addEventListener( 'click', ( event ) => {
		const reply = event.target.closest( '[data-pq-reply]' );
		if ( reply ) {
			parent.value = reply.dataset.pqReply;
			replying.querySelector( 'span' ).textContent = ( cfg.replyingTo || '%s' ).replace( '%s', reply.dataset.pqName );
			replying.hidden = false;
			answerForm.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			text.focus( { preventScroll: true } );
		}
		if ( event.target.closest( '[data-pq-cancel-reply]' ) ) {
			parent.value = '0';
			replying.hidden = true;
		}
	} );
} )();
