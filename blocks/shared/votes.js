/**
 * Voting with the Interactivity API, wherever requests are shown: on the board, on a
 * list, on a card and on a single request's page.
 *
 * The HTML is the same for every visitor, so it can be cached. After the page loads,
 * callbacks.hydrate asks the REST API which requests this visitor voted for. Each vote
 * button is a real form, so voting still works if this script never runs.
 *
 * A page can show a request more than once, such as on a list and on a card. What the
 * visitor voted for and the counts that changed are kept in the store, and callbacks.sync
 * copies them to every place a request is shown.
 *
 * On a board where visitors vote once they confirm their email, a visitor who has not
 * gets a form for their address next to the button. The plugin emails them a link, and
 * the vote counts once they press the button on the link's page.
 *
 * Actions that wait for the network are generators (the * before the name) instead of
 * async functions. They yield promises rather than awaiting them, so the Interactivity
 * API can restore the element's scope before resuming, and getContext() keeps working.
 */
import { store, getContext } from '@wordpress/interactivity';

import { send } from './rest';

// The server refuses a form sent within 3 seconds of rendering it, as bots send
// them. Someone that quick waits for the rest of this time instead.
const RENDER_TO_SEND = 3500;

// The vote's refusal that asks a visitor to confirm their email first.
const EMAIL_REQUIRED = 'mwtfrs_email_required';

// Fields of the email form that the plain form post needs but the REST API does not.
// Every other field goes along, such as one a captcha adds to the form.
const LINK_FORM_ONLY_FIELDS = [ 'action', 'request_id', 'redirect_to' ];

/**
 * Says how many votes the visitor has left, in a board that limits them. Lists, cards
 * and request pages do not say it, so their blocks have nothing to update.
 *
 * @param {Object} context Context of the request or the block.
 * @param {number} left    Votes left.
 */
function showVotesLeft( context, left ) {
	if ( context.allowanceBoard ) {
		// A translation may number the placeholder, as %1$d.
		context.allowance = state.strings.votesLeft.replace(
			/%(1\$)?d/,
			String( left )
		);
	}
}

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
					state.votes[ context.id ] = data.votes;
					// A vote can make a logged-in user follow the request, and taking it back can end that.
					if ( typeof data.following === 'boolean' ) {
						context.following = data.following;
					}
					// The announcement belongs to the block around the request, so a page
					// with several blocks says it once.
					context.announcement = data.voted
						? state.strings.added
						: state.strings.removed;
					// A board that limits votes says how many are left.
					if ( typeof data.votes_left === 'number' ) {
						showVotesLeft( context, data.votes_left );
					}
				} else if ( data.code === EMAIL_REQUIRED ) {
					context.message = data.message;
					context.needsEmail = true;
					yield* focusEmail( event.target );
					showCaptcha( event.target );
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

		/**
		 * Asks for the link that confirms the visitor's email, for the vote they started.
		 *
		 * @param {SubmitEvent} event Form submit event.
		 */
		*requestLink( event ) {
			event.preventDefault();

			const context = getContext();
			if ( context.busy ) {
				return;
			}

			// Cloudflare's script may have loaded only after the form opened. The widget is drawn
			// now, and its answer goes with the next try.
			const captcha = event.target.querySelector(
				'.mwtfrs-guest__captcha'
			);
			if ( captcha && ! captcha.dataset.widget ) {
				showCaptcha( event.target );
				return;
			}

			const fields = Object.fromEntries( new FormData( event.target ) );
			LINK_FORM_ONLY_FIELDS.forEach( ( name ) => delete fields[ name ] );

			context.busy = true;
			context.message = '';

			try {
				yield* waitUntilSendable();
				const response = yield* send( state, 'guests/link', {
					method: 'POST',
					body: { ...fields, request: context.id },
				} );
				const data = yield response.json();

				context.message = data.message || state.strings.linkError;
				if ( response.ok ) {
					context.needsEmail = false;
					event.target.reset();
				}
			} catch {
				context.message = state.strings.linkError;
			} finally {
				context.busy = false;
				// A Turnstile answer works once, so the next try needs a new one.
				resetCaptcha( event.target );
			}
		},
	},
	callbacks: {
		/**
		 * Marks the requests in this block that the visitor already voted for.
		 */
		*hydrate() {
			const context = getContext();
			const { ids, guestVotes, allowanceBoard } = context;
			// A visitor who is not logged in has votes only where a board lets guests
			// vote. Anywhere else there is nothing to ask the server.
			if ( ! ids.length || ( ! state.nonce && ! guestVotes ) ) {
				return;
			}

			try {
				const response = yield* send( state, 'me/state', {
					method: 'POST',
					// A board that limits votes also asks how many the visitor has left.
					body: allowanceBoard
						? { ids, board: allowanceBoard }
						: { ids },
				} );
				if ( response.ok ) {
					const data = yield response.json();
					data.voted.forEach( ( id ) => {
						state.voted[ id ] = true;
					} );
					if ( typeof data.votes_left === 'number' ) {
						showVotesLeft( context, data.votes_left );
					}
				}
			} catch {
				// The buttons still work. They just start unpressed.
			}
		},

		/**
		 * Keeps a request's pressed state and count in line with the visitor's votes,
		 * also when the vote was cast on the same request elsewhere on the page.
		 */
		sync() {
			const context = getContext();
			const votes = state.votes[ context.id ];

			context.voted = Boolean( state.voted[ context.id ] );
			if ( votes !== undefined ) {
				context.votes = votes;
			}
		},
	},
} );

/**
 * Moves the keyboard focus to the email field of a request, once it is shown.
 *
 * @param {HTMLFormElement} voteForm The request's vote form.
 */
function* focusEmail( voteForm ) {
	yield new Promise( ( resolve ) => window.requestAnimationFrame( resolve ) );
	voteForm
		.closest( '[data-wp-context]' )
		?.querySelector( '.mwtfrs-guest__email' )
		?.focus();
}

/**
 * Draws the Turnstile widget in a request's email form, the first time the form opens.
 * Pages draw none until then, so a board with many requests starts no check for each.
 *
 * @param {HTMLFormElement} voteForm The request's vote form.
 */
function showCaptcha( voteForm ) {
	const box = voteForm
		.closest( '[data-wp-context]' )
		?.querySelector( '.mwtfrs-guest__captcha' );
	if ( ! box || box.dataset.widget || ! window.turnstile ) {
		return;
	}

	box.dataset.widget = window.turnstile.render( box, {
		sitekey: box.dataset.sitekey,
		'response-field-name': box.dataset.responseFieldName,
		// The widget needs 300 pixels. Narrower forms, as on a phone, get its compact size.
		size: box.parentElement.clientWidth < 300 ? 'compact' : 'flexible',
	} );
}

/**
 * Asks the Turnstile widget of an email form, if it has one, for a new answer.
 *
 * @param {HTMLFormElement} form The email form.
 */
function resetCaptcha( form ) {
	const box = form.querySelector( '.mwtfrs-guest__captcha' );
	if ( box?.dataset.widget && window.turnstile ) {
		window.turnstile.reset( box.dataset.widget );
	}
}

/**
 * Waits until the page has been open long enough for the server to accept a form.
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
