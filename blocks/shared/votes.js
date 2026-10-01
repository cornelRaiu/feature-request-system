/**
 * Voting with the Interactivity API, on the board and on a single request's page.
 *
 * The HTML is the same for every visitor, so it can be cached. After the page loads,
 * callbacks.hydrate asks the REST API which requests this visitor voted for. Each vote
 * button is a real form, so voting still works if this script never runs.
 *
 * Actions that wait for the network are generators (the * before the name) instead of
 * async functions. They yield promises rather than awaiting them, so the Interactivity
 * API can restore the element's scope before resuming, and getContext() keeps working.
 */
import { store, getContext } from '@wordpress/interactivity';

import { send } from './rest';

const { state } = store( 'mwtfrs/votes', {
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
					state,
					`requests/${ context.id }/vote`,
					{ method: context.voted ? 'DELETE' : 'POST' }
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
				const response = yield* send( state, 'me/state', {
					method: 'POST',
					body: { ids },
				} );
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
