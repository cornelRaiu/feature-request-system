/**
 * Voting on the Feature Request Board with the Interactivity API.
 *
 * The board's HTML is the same for every visitor, so it can be cached. After the page
 * loads, callbacks.hydrate asks the REST API which requests this visitor voted for.
 * Each vote button is a real form, so voting still works if this script never runs.
 */
import { store, getContext } from '@wordpress/interactivity';

const { state } = store( 'mwtfrs/board', {
	actions: {
		/**
		 * Casts or retracts the vote for the request whose form was submitted.
		 *
		 * @param {SubmitEvent} event Form submit event.
		 */
		*toggle( event ) {
			event.preventDefault();

			const context = getContext();
			if ( context.busy ) {
				return;
			}

			context.busy = true;
			context.message = '';
			context.needsLogin = false;

			try {
				const response = yield* send(
					`requests/${ context.id }/vote`,
					context.voted ? 'DELETE' : 'POST'
				);
				const data = yield response.json();

				if ( response.ok ) {
					context.votes = data.votes;
					context.voted = data.voted;
					state.voted[ context.id ] = data.voted;
					state.announcement = data.voted
						? state.strings.added
						: state.strings.removed;
				} else {
					context.message = data.message || state.strings.error;
					context.needsLogin = response.status === 401;
				}
			} catch {
				context.message = state.strings.error;
			} finally {
				context.busy = false;
			}
		},
	},
	callbacks: {
		/**
		 * Marks the requests on this board that the visitor already voted for.
		 */
		*hydrate() {
			const { ids } = getContext();
			if ( ! ids.length ) {
				return;
			}

			try {
				const response = yield* send( 'me/state', 'POST', { ids } );
				if ( response.ok ) {
					const data = yield response.json();
					data.voted.forEach( ( id ) => {
						state.voted[ id ] = true;
					} );
				}
			} catch {
				// The buttons still work. They just start unpressed.
			}
		},

		/**
		 * Keeps a request's pressed state in line with the visitor's votes.
		 */
		syncVoted() {
			const context = getContext();
			context.voted = Boolean( state.voted[ context.id ] );
		},
	},
} );

/**
 * Sends a request to the plugin's REST API.
 *
 * Logged-in visitors send the REST nonce. If it expired while the page was open,
 * a fresh one is fetched and the request is sent once more.
 *
 * @param {string} path   Route relative to the plugin's REST namespace.
 * @param {string} method HTTP method.
 * @param {Object} [body] JSON body.
 * @return {Response} The response to the request.
 */
function* send( path, method, body ) {
	const request = () =>
		fetch( state.restUrl + path, {
			method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				...( state.nonce ? { 'X-WP-Nonce': state.nonce } : {} ),
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );

	let response = yield request();

	if ( response.status === 403 && state.nonce ) {
		const error = yield response.clone().json();
		if ( error.code === 'rest_cookie_invalid_nonce' ) {
			const refreshed = yield fetch(
				`${ state.ajaxUrl }?action=rest-nonce`,
				{ credentials: 'same-origin' }
			);
			if ( refreshed.ok ) {
				state.nonce = yield refreshed.text();
				response = yield request();
			}
		}
	}

	return response;
}
