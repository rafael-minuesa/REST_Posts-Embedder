/**
 * REST Posts Embedder - Load More button.
 *
 * Fetches the next page of posts via admin-ajax and appends them to the grid.
 * The remote endpoint is never exposed to the browser: the button carries an
 * opaque token that the server resolves back to the feed configuration.
 *
 * @since 3.7.0
 */
( function () {
	'use strict';

	var settings = window.restPostsEmbedderLoadMore || {};

	/**
	 * Handle a click on a Load More button.
	 *
	 * @param {HTMLButtonElement} button The clicked button.
	 */
	function loadMore( button ) {
		if ( button.disabled ) {
			return;
		}

		var token = button.getAttribute( 'data-token' );
		var nextPage = parseInt( button.getAttribute( 'data-page' ), 10 ) + 1;

		if ( ! token || ! settings.ajaxUrl ) {
			return;
		}

		var originalLabel = button.textContent;
		button.disabled = true;
		button.classList.add( 'is-loading' );
		button.textContent = settings.loadingText || 'Loading…';

		var wrap = button.closest( '.embed-posts-load-more-wrap' );
		var container = button.closest( '.embed-posts-container' );
		var grid = container ? container.querySelector( '.embed-posts-wrapper' ) : null;

		var body = new URLSearchParams();
		body.append( 'action', 'rest_posts_embedder_load_more' );
		body.append( 'nonce', settings.nonce || '' );
		body.append( 'token', token );
		body.append( 'page', String( nextPage ) );

		var done = function () {
			button.disabled = false;
			button.classList.remove( 'is-loading' );
			button.textContent = originalLabel;
		};

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success || ! result.data ) {
					throw new Error( 'request-failed' );
				}

				var data = result.data;

				if ( grid && data.html ) {
					grid.insertAdjacentHTML( 'beforeend', data.html );
				}

				button.setAttribute( 'data-page', String( nextPage ) );

				if ( ! data.has_more ) {
					// No more pages: remove the button entirely.
					if ( wrap ) {
						wrap.parentNode.removeChild( wrap );
					}
				} else {
					done();
				}
			} )
			.catch( function () {
				done();
				button.textContent = settings.errorText || 'Could not load more posts. Please try again.';
				window.setTimeout( function () {
					button.textContent = originalLabel;
				}, 3000 );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.embed-posts-load-more' ) : null;
		if ( button ) {
			event.preventDefault();
			loadMore( button );
		}
	} );
} )();
