/* Questions → Queue: drag rows to reorder; the hidden order[] inputs follow the rows. */
jQuery( function ( $ ) {
	const $queue = $( '.pq-queue' );
	if ( ! $queue.length ) {
		return;
	}
	$queue.sortable( {
		handle: '.pq-row__handle',
		axis: 'y',
		placeholder: 'pq-row pq-row--placeholder',
		update() {
			$queue.addClass( 'pq-queue--changed' );
		},
	} );
} );
