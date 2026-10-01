/**
 * The Feature Request Form with the Interactivity API.
 *
 * While the visitor types a title, published requests with similar titles are listed,
 * so they can vote for one instead of posting a duplicate. The form is sent through the
 * REST API. It is a real form too, so it still works if this script never runs.
 *
 * Actions that wait are generators (the * before the name) instead of async functions,
 * so the Interactivity API can restore the element's scope and getContext() keeps working.
 */
import { store, getContext } from '@wordpress/interactivity';

import { send } from '../shared/rest';

// Wait this long after the last keystroke before searching.
const SEARCH_DELAY = 300;

// The server refuses requests sent within 3 seconds of rendering the form, as
// bots send them. Someone that quick waits for the rest of this time instead.
const RENDER_TO_SEND = 3500;

// Fields the plain form post needs but the REST API does not.
const FORM_ONLY_FIELDS = [ 'action', 'board', 'redirect_to', '_wpnonce' ];

const { state } = store( 'mwtfrs/submit', {
	actions: {
		/**
		 * Lists requests whose titles share words with the title being typed.
		 *
		 * @param {InputEvent} event Input event of the title field.
		 */
		*search( event ) {
			const context = getContext();
			const text = event.target.value.trim();
			// Each search gets a number, so a slow response cannot replace a newer one.
			const ticket = ++context.searchTicket;

			if ( text.length < state.minSearchLength ) {
				showSimilar( context, [] );
				return;
			}

			yield new Promise( ( resolve ) =>
				setTimeout( resolve, SEARCH_DELAY )
			);
			if ( ticket !== context.searchTicket ) {
				return;
			}

			try {
				const response = yield* send(
					state,
					`boards/${ context.board }/similar`,
					{ query: { q: text } }
				);
				const similar = response.ok ? yield response.json() : [];
				if ( ticket === context.searchTicket ) {
					showSimilar( context, similar );
				}
			} catch {
				// Suggestions are a convenience. The form works without them.
			}
		},

		/**
		 * Sends the request and shows the outcome. Clears the form when it was saved.
		 *
		 * @param {SubmitEvent} event Form submit event.
		 */
		*submit( event ) {
			event.preventDefault();

			const context = getContext();
			if ( context.busy ) {
				return;
			}

			const form = event.target;
			const fields = Object.fromEntries( new FormData( form ) );
			FORM_ONLY_FIELDS.forEach( ( name ) => delete fields[ name ] );

			context.busy = true;
			context.message = '';
			context.isError = false;
			context.needsLogin = false;
			context.url = '';

			try {
				yield* waitUntilSendable();
				const response = yield* send(
					state,
					`boards/${ context.board }/requests`,
					{ method: 'POST', body: fields }
				);
				const data = yield response.json();

				if ( response.ok ) {
					form.reset();
					context.searchTicket++;
					showSimilar( context, [] );
					context.message = data.message;
					context.url = data.url;
				} else {
					context.message = data.message || state.strings.error;
					context.isError = true;
					context.needsLogin = response.status === 401;
				}
			} catch {
				context.message = state.strings.error;
				context.isError = true;
			} finally {
				context.busy = false;
			}
		},
	},
} );

/**
 * Waits until the form has been on the page long enough for the server to accept it.
 * The page's response time stands in for when the server rendered the form.
 */
function* waitUntilSendable() {
	const [ navigation ] = performance.getEntriesByType( 'navigation' );
	const wait =
		( navigation?.responseStart ?? 0 ) + RENDER_TO_SEND - performance.now();

	if ( wait > 0 ) {
		yield new Promise( ( resolve ) => setTimeout( resolve, wait ) );
	}
}

/**
 * Shows the similar requests and tells screen reader users how many there are.
 *
 * @param {Object} context Form context.
 * @param {Array}  similar Similar requests from the REST API.
 */
function showSimilar( context, similar ) {
	context.similar = similar;
	context.announcement = similar.length
		? state.strings.similarFound.replace( '%d', similar.length )
		: '';
}
